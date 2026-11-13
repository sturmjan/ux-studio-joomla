/**
 * Redirects screen: rules (add / edit / test / hit counters), the aggregated 404 log with one-click redirect
 * suggestions, SEO audit, CSV + Joomla import / CSV export, module settings.
 */
import { useRef, useState } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import { ArrowRight, Download, Pencil, Play, Plus, RotateCcw, Trash2, Upload } from 'lucide-react';
import { api, ApiError, queryClient, type ModuleInfo } from '../../app/api';
import { createT, formatDate } from '../../app/i18n';
import { SettingsPanel } from '../../app/SettingsForm';
import { DataTable, Loading, Modal, TabPanel, Tabs, ToggleSwitch, confirm, pushToast, type Column } from '../../app/ui';

const { t, tn } = createT( 'redirects' );

type Code = 301 | 302 | 307 | 308 | 410;
type MatchType = 'exact' | 'regex';
type QueryMode = 'default' | 'exact' | 'ignore' | 'pass';

type Rule = {
	id: number;
	source: string;
	match_type: MatchType;
	target: string;
	target_url: string;
	code: Code;
	case_sensitive: boolean;
	query_mode: QueryMode;
	position: number;
	enabled: boolean;
	hits: number;
	last_hit: string | null;
	origin: string;
	note: string;
	created_at: string;
	updated_at: string;
};

type Issue = { code: string; message: string };

type SaveResult = {
	status: string;
	id: number | null;
	source: string;
	target_url: string;
	errors: Issue[];
	warnings: Issue[];
	index?: number;
};

type BulkResult = { summary: Record< string, number >; results: SaveResult[] };

type Suggestion = { type: string; id: number; title: string; path: string; url: string; route: string; score: number };

type NotFoundRow = {
	id: number;
	url: string;
	full_url: string;
	hits: number;
	last_seen: string;
	referrer: string;
	resolved_by: number | null;
	suggestion: Suggestion | null;
};

type Status = {
	joomla_redirect_plugin: boolean;
	joomla_redirect_component: boolean;
	joomla_links: number;
	rules: number;
	log_404: number;
	sef: boolean;
	rewrite: boolean;
};

type TestResult = {
	tested_url: string;
	matched: boolean;
	hops: number;
	final_url: string | null;
	final_code: number | null;
	loop: boolean;
	issues: string[];
	live?: { ok: boolean; final_status: number; hops: number; chain: Array< { url: string; status: number } >; error?: string };
};

type AuditResult = {
	summary: { rules: number; errors: number; warnings: number; live_checked: number; fixed: number };
	issues: Array< { severity: 'error' | 'warning'; code: string; rule_id: number | null; source: string; message: string } >;
};

type Draft = {
	id: number | null;
	source: string;
	target: string;
	code: Code;
	match_type: MatchType;
	note: string;
	case_sensitive: boolean;
	query_mode: QueryMode;
	origin: string;
};

const PAGE_SIZE = 25;
const KEY = [ 'redirects' ];
const EMPTY_DRAFT: Draft = { id: null, source: '', target: '', code: 301, match_type: 'exact', note: '', case_sensitive: false, query_mode: 'default', origin: 'manual' };

const CODES: Code[] = [ 301, 308, 302, 307, 410 ];

function codeLabel( code: Code ): string {
	switch ( code ) {
		case 301:
			return t( '301 Moved permanently (recommended)' );
		case 308:
			return t( '308 Permanent, keeps the method' );
		case 302:
			return t( '302 Temporary' );
		case 307:
			return t( '307 Temporary, keeps the method' );
		default:
			return t( '410 Gone (content removed)' );
	}
}

function queryLabel( mode: QueryMode ): string {
	switch ( mode ) {
		case 'exact':
			return t( 'Exact: no other parameters allowed' );
		case 'ignore':
			return t( 'Ignore parameters' );
		case 'pass':
			return t( 'Ignore and pass on to the new URL' );
		default:
			return t( 'By the module setting (default)' );
	}
}

function errorMessage( e: unknown ): string {
	return e instanceof ApiError || e instanceof Error ? e.message : t( 'Something went wrong.' );
}

function CodeBadge( { code }: { code: number } ): JSX.Element {
	const cls = code === 410 ? 'is-danger' : code === 301 || code === 308 ? 'is-success' : 'is-warning';
	return <span className={ `uxs-badge ${ cls }` }>{ code }</span>;
}

/** Audit / test problem codes and import statuses as readable text (literal t() calls so the extractor sees them). */
function issueLabel( code: string ): string {
	switch ( code ) {
		case 'loop':
			return t( 'Redirect loop' );
		case 'chain':
			return t( 'Redirect chain' );
		case 'temporary':
		case 'temporary_long':
			return t( 'Long temporary redirect' );
		case 'invalid_regex':
			return t( 'Invalid regular expression' );
		case 'target_broken':
			return t( 'Target does not work' );
		case 'homepage_mass':
			return t( 'Mass redirect to the homepage' );
		case 'live_check_failed':
			return t( 'Live check failed' );
		default:
			return code;
	}
}

function statusLabel( status: string ): string {
	switch ( status ) {
		case 'created':
			return t( 'Created' );
		case 'updated':
			return t( 'Updated' );
		case 'unchanged':
			return t( 'Unchanged' );
		case 'skipped':
			return t( 'Skipped' );
		case 'would_create':
			return t( 'Would create' );
		case 'would_update':
			return t( 'Would update' );
		case 'error':
			return t( 'Error' );
		default:
			return status;
	}
}

function Issues( { result }: { result: SaveResult } ): JSX.Element | null {
	if ( ! result.errors.length && ! result.warnings.length ) {
		return null;
	}
	return (
		<ul className="uxs-muted" style={ { margin: 'var(--uxs-sp-2) 0 0', paddingLeft: 'var(--uxs-sp-4)' } }>
			{ result.errors.map( ( e ) => (
				<li key={ `e-${ e.code }` }>
					<span className="uxs-badge is-danger">{ t( 'Error' ) }</span> { e.message }
				</li>
			) ) }
			{ result.warnings.map( ( w ) => (
				<li key={ `w-${ w.code }` }>
					<span className="uxs-badge is-warning">{ t( 'Warning' ) }</span> { w.message }
				</li>
			) ) }
		</ul>
	);
}

function invalidate(): void {
	void queryClient.invalidateQueries( { queryKey: KEY } );
}

/** Creates one rule through the bulk endpoint and returns its result (or throws the first error). */
async function createRule( item: Record< string, unknown > ): Promise< SaveResult > {
	const bulk = await api.post< BulkResult >( 'redirects/rules', { items: [ item ], on_conflict: 'error' } );
	const first = bulk.results[ 0 ];
	if ( ! first ) {
		throw new Error( t( 'Unexpected empty response.' ) );
	}
	if ( first.status === 'error' ) {
		throw new Error( first.errors[ 0 ]?.message ?? t( 'Something went wrong.' ) );
	}
	return first;
}

/* ------------------------------------------------------------------ */

function RuleModal( { draft, onClose }: { draft: Draft; onClose: () => void } ): JSX.Element {
	const [ d, setD ] = useState< Draft >( draft );
	const [ result, setResult ] = useState< SaveResult | null >( null );
	const [ error, setError ] = useState( '' );
	const submitting = useRef( false );

	const save = useMutation( {
		mutationFn: async (): Promise< SaveResult > => {
			const payload = {
				source: d.source,
				target: d.code === 410 ? '' : d.target,
				code: d.code,
				match_type: d.match_type,
				note: d.note,
				case_sensitive: d.case_sensitive,
				query_mode: d.query_mode,
				origin: d.origin,
			};
			if ( d.id ) {
				return api.post< SaveResult >( `redirects/rules/${ d.id }`, payload );
			}
			return createRule( payload );
		},
		onSuccess: ( res ) => {
			setError( '' );
			invalidate();
			if ( res.warnings.length ) {
				setResult( res );
			} else {
				pushToast( 'success', t( 'Redirect saved.' ) );
				onClose();
			}
		},
		onError: ( e ) => setError( errorMessage( e ) ),
		onSettled: () => {
			submitting.current = false;
		},
	} );
	// A synchronous guard: a double click fires twice before isPending re-renders the button.
	const submit = () => {
		if ( ! d.source || submitting.current ) {
			return;
		}
		submitting.current = true;
		save.mutate();
	};

	return (
		<Modal
			open
			wide
			onClose={ onClose }
			title={ d.id ? t( 'Edit redirect' ) : t( 'Add redirect' ) }
			actions={
				result ? (
					<button type="button" className="uxs-btn uxs-btn--primary" onClick={ onClose }>
						{ t( 'Close' ) }
					</button>
				) : (
					<>
						<button type="button" className="uxs-btn" onClick={ onClose }>
							{ t( 'Cancel' ) }
						</button>
						<button type="button" className="uxs-btn uxs-btn--primary" disabled={ ! d.source || save.isPending } onClick={ submit }>
							{ d.id ? t( 'Update redirect' ) : t( 'Add redirect' ) }
						</button>
					</>
				)
			}
		>
			{ result ? (
				<>
					<p>
						<span className="uxs-badge is-success">{ t( 'Saved' ) }</span> <code>{ result.source }</code>
					</p>
					<Issues result={ result } />
				</>
			) : (
				<div
					className="uxs-form uxs-form--grid"
					style={ { border: 'none', padding: 0 } }
					onKeyDown={ ( e ) => {
						if ( e.key === 'Enter' && e.target instanceof HTMLInputElement && e.target.type === 'text' ) {
							e.preventDefault();
							submit();
						}
					} }
				>
					<div className="uxs-form__row">
						<label htmlFor="uxs-rd-source">{ t( 'Old URL' ) }</label>
						<input id="uxs-rd-source" type="text" value={ d.source } placeholder={ d.match_type === 'regex' ? '^/blog/[0-9]+-(.*)$' : '/old-page.html' } onChange={ ( e ) => setD( { ...d, source: e.target.value } ) } />
						<p className="uxs-form__help">
							{ d.match_type === 'regex'
								? t( 'A regular expression matched against the path and query of the address (case-insensitive). Slow patterns are refused.' )
								: t( 'A path on this site (/old-page) or a full address of the old site. Case and a trailing slash are ignored.' ) }
						</p>
					</div>
					<div className="uxs-form__row">
						<label htmlFor="uxs-rd-target">{ t( 'New URL' ) }</label>
						<input
							id="uxs-rd-target"
							type="text"
							value={ d.code === 410 ? '' : d.target }
							disabled={ d.code === 410 }
							placeholder="/new-page"
							onChange={ ( e ) => setD( { ...d, target: e.target.value } ) }
						/>
						<p className="uxs-form__help">
							{ t( 'A path on this site, a full https:// address, or a Joomla route such as index.php?Itemid=101. In a regular expression $1 to $9 insert the captured groups.' ) }
						</p>
					</div>
					<div className="uxs-form__row">
						<label htmlFor="uxs-rd-code">{ t( 'Type' ) }</label>
						<select id="uxs-rd-code" value={ d.code } onChange={ ( e ) => setD( { ...d, code: Number( e.target.value ) as Code } ) }>
							{ CODES.map( ( c ) => (
								<option key={ c } value={ c }>
									{ codeLabel( c ) }
								</option>
							) ) }
						</select>
					</div>
					<div className="uxs-form__row">
						<label htmlFor="uxs-rd-match">{ t( 'Match' ) }</label>
						<select id="uxs-rd-match" value={ d.match_type } onChange={ ( e ) => setD( { ...d, match_type: e.target.value as MatchType } ) }>
							<option value="exact">{ t( 'Exact URL' ) }</option>
							<option value="regex">{ t( 'Regular expression' ) }</option>
						</select>
					</div>
					{ d.match_type === 'exact' ? (
						<>
							<div className="uxs-form__row">
								<label htmlFor="uxs-rd-query">{ t( 'Query string' ) }</label>
								<select id="uxs-rd-query" value={ d.query_mode } onChange={ ( e ) => setD( { ...d, query_mode: e.target.value as QueryMode } ) }>
									{ ( [ 'default', 'exact', 'ignore', 'pass' ] as QueryMode[] ).map( ( m ) => (
										<option key={ m } value={ m }>
											{ queryLabel( m ) }
										</option>
									) ) }
								</select>
							</div>
							<div className="uxs-form__row">
								<label>{ t( 'Case sensitive' ) }</label>
								<ToggleSwitch checked={ d.case_sensitive } onChange={ ( v ) => setD( { ...d, case_sensitive: v } ) } label={ t( 'Case sensitive' ) } />
							</div>
						</>
					) : null }
					<div className="uxs-form__row">
						<label htmlFor="uxs-rd-note">{ t( 'Note' ) }</label>
						<input id="uxs-rd-note" type="text" maxLength={ 255 } value={ d.note } onChange={ ( e ) => setD( { ...d, note: e.target.value } ) } />
					</div>
					{ error ? <p role="alert" className="uxs-inline-warning">{ error }</p> : null }
				</div>
			) }
		</Modal>
	);
}

/* ------------------------------------------------------------------ */

function RulesTab( { onEdit }: { onEdit: ( d: Draft ) => void } ): JSX.Element {
	const [ search, setSearch ] = useState( '' );
	const [ page, setPage ] = useState( 1 );
	const rules = useQuery( {
		queryKey: [ ...KEY, 'rules', search, page ],
		queryFn: () => api.get< { items: Rule[]; total: number } >( 'redirects/rules', { search, limit: PAGE_SIZE, offset: ( page - 1 ) * PAGE_SIZE } ),
	} );

	const toggle = useMutation( {
		mutationFn: ( rule: Rule ) => api.post( `redirects/rules/${ rule.id }`, { enabled: ! rule.enabled } ),
		onSuccess: invalidate,
		onError: ( e ) => pushToast( 'error', errorMessage( e ) ),
	} );
	const remove = useMutation( {
		mutationFn: ( id: number ) => api.post( 'redirects/rules/delete', { ids: [ id ] } ),
		onSuccess: () => {
			invalidate();
			pushToast( 'success', t( 'Redirect deleted.' ) );
		},
		onError: ( e ) => pushToast( 'error', errorMessage( e ) ),
	} );
	const resetHits = useMutation( {
		mutationFn: ( ids: number[] | null ) => api.post( 'redirects/rules/reset-hits', ids ? { ids } : { all: true } ),
		onSuccess: invalidate,
		onError: ( e ) => pushToast( 'error', errorMessage( e ) ),
	} );

	const columns: Column< Rule >[] = [
		{
			key: 'source',
			header: t( 'Old URL' ),
			render: ( r ) => (
				<>
					<code>{ r.source }</code>
					{ r.match_type === 'regex' ? <span className="uxs-badge is-info"> regex</span> : null }
					{ r.case_sensitive ? <span className="uxs-badge"> { t( 'Case sensitive' ) }</span> : null }
					{ r.query_mode !== 'default' ? <span className="uxs-badge" title={ queryLabel( r.query_mode ) }> { t( 'Parameters: {mode}', { mode: r.query_mode } ) }</span> : null }
					{ r.note ? <div className="uxs-muted">{ r.note }</div> : null }
				</>
			),
		},
		{ key: 'target', header: t( 'New URL' ), render: ( r ) => ( r.code === 410 ? '-' : r.target_url ) },
		{ key: 'code', header: t( 'Type' ), render: ( r ) => <CodeBadge code={ r.code } /> },
		{ key: 'hits', header: t( 'Hits' ) },
		{ key: 'last_hit', header: t( 'Last hit' ), render: ( r ) => <span style={ { whiteSpace: 'nowrap' } }>{ r.last_hit ? formatDate( r.last_hit ) : '-' }</span> },
		{ key: 'origin', header: t( 'Origin' ), render: ( r ) => originLabel( r.origin ) },
		{ key: 'enabled', header: t( 'Active' ), render: ( r ) => <ToggleSwitch checked={ r.enabled } onChange={ () => toggle.mutate( r ) } label={ t( 'Active' ) } /> },
		{
			key: 'actions',
			header: '',
			render: ( r ) => (
				<span style={ { whiteSpace: 'nowrap' } }>
					<button
						type="button"
						className="uxs-btn uxs-btn--small"
						aria-label={ t( 'Edit' ) }
						title={ t( 'Edit' ) }
						onClick={ () =>
							onEdit( { id: r.id, source: r.source, target: r.target, code: r.code, match_type: r.match_type, note: r.note, case_sensitive: r.case_sensitive, query_mode: r.query_mode, origin: r.origin } )
						}
					>
						<Pencil size={ 14 } aria-hidden="true" />
					</button>{ ' ' }
					{ r.hits > 0 || r.last_hit ? (
						<>
							<button type="button" className="uxs-btn uxs-btn--small" aria-label={ t( 'Reset hit counter' ) } title={ t( 'Reset hit counter' ) } onClick={ () => resetHits.mutate( [ r.id ] ) }>
								<RotateCcw size={ 14 } aria-hidden="true" />
							</button>{ ' ' }
						</>
					) : null }
					<button
						type="button"
						className="uxs-btn uxs-btn--small uxs-btn--danger"
						aria-label={ t( 'Delete' ) }
						title={ t( 'Delete' ) }
						onClick={ () => {
							void confirm( { message: t( 'Delete this redirect?' ), confirmLabel: t( 'Delete' ), danger: true } ).then( ( ok ) => ok && remove.mutate( r.id ) );
						} }
					>
						<Trash2 size={ 14 } aria-hidden="true" />
					</button>
				</span>
			),
		},
	];

	const total = rules.data?.total ?? 0;
	return (
		<>
			<div className="uxs-actions uxs-list-toolbar">
				<button type="button" className="uxs-btn uxs-btn--primary" onClick={ () => onEdit( EMPTY_DRAFT ) }>
					<Plus size={ 14 } aria-hidden="true" /> { t( 'Add redirect' ) }
				</button>
				<input
					type="search"
					aria-label={ t( 'Search redirects…' ) }
					placeholder={ t( 'Search redirects…' ) }
					value={ search }
					onChange={ ( e ) => {
						setPage( 1 );
						setSearch( e.target.value );
					} }
				/>
				{ rules.data && rules.data.items.some( ( r ) => r.hits > 0 ) ? (
					<button
						type="button"
						className="uxs-btn"
						onClick={ () => {
							void confirm( { message: t( 'Reset the hit counters of ALL redirects to zero?' ), confirmLabel: t( 'Reset' ), danger: true } ).then( ( ok ) => ok && resetHits.mutate( null ) );
						} }
					>
						<RotateCcw size={ 14 } aria-hidden="true" /> { t( 'Reset all hit counters' ) }
					</button>
				) : null }
				<span className="uxs-muted uxs-list-toolbar__count">{ tn( '{n} redirect', '{n} redirects', total ) }</span>
			</div>
			{ rules.isLoading ? <Loading /> : null }
			{ rules.error ? <p role="alert">{ errorMessage( rules.error ) }</p> : null }
			{ rules.data ? (
				<DataTable
					columns={ columns }
					rows={ rules.data.items }
					rowKey={ ( r ) => r.id }
					empty={ t( 'No redirects yet.' ) }
					page={ page }
					pages={ Math.max( 1, Math.ceil( total / PAGE_SIZE ) ) }
					onPage={ setPage }
				/>
			) : null }
		</>
	);
}

function originLabel( origin: string ): string {
	switch ( origin ) {
		case 'import':
			return t( 'Import' );
		case 'auto':
			return t( 'Automatic' );
		case 'suggest':
			return t( 'From 404 log' );
		default:
			return t( 'Manual' );
	}
}

/* ------------------------------------------------------------------ */

function NotFoundTab( { onRedirect }: { onRedirect: ( url: string ) => void } ): JSX.Element {
	const [ unresolved, setUnresolved ] = useState( true );
	const [ page, setPage ] = useState( 1 );
	const log = useQuery( {
		queryKey: [ ...KEY, '404', unresolved, page ],
		queryFn: () => api.get< { items: NotFoundRow[]; total: number } >( 'redirects/404', { unresolved_only: unresolved ? 1 : 0, suggest: 1, limit: PAGE_SIZE, offset: ( page - 1 ) * PAGE_SIZE } ),
	} );
	const clear = useMutation( {
		mutationFn: ( ids: number[] | null ) => api.post( 'redirects/404/delete', ids ? { ids } : { all: true } ),
		onSuccess: invalidate,
		onError: ( e ) => pushToast( 'error', errorMessage( e ) ),
	} );
	const quick = useMutation( {
		mutationFn: ( p: { row: NotFoundRow; target: string; code: Code } ) => createRule( { source: p.row.url, target: p.target, code: p.code, origin: 'suggest' } ),
		onSuccess: ( res ) => {
			invalidate();
			pushToast( 'success', res.warnings[ 0 ]?.message ?? t( 'Redirect saved.' ) );
		},
		onError: ( e ) => pushToast( 'error', errorMessage( e ) ),
	} );

	const columns: Column< NotFoundRow >[] = [
		{
			key: 'url',
			header: t( 'URL' ),
			render: ( r ) => (
				<>
					<code>{ r.url }</code>
					{ r.resolved_by ? <span className="uxs-badge is-success"> #{ r.resolved_by }</span> : null }
				</>
			),
		},
		{ key: 'hits', header: t( 'Hits' ) },
		{ key: 'last_seen', header: t( 'Last seen' ), render: ( r ) => <span style={ { whiteSpace: 'nowrap' } }>{ formatDate( r.last_seen ) }</span> },
		{ key: 'referrer', header: t( 'Referrer' ), render: ( r ) => ( r.referrer ? <span title={ r.referrer }>{ r.referrer.replace( /^https?:\/\//, '' ).slice( 0, 40 ) }</span> : '-' ) },
		{
			key: 'actions',
			header: '',
			render: ( r ) =>
				r.resolved_by ? null : (
					<span style={ { display: 'flex', flexWrap: 'wrap', gap: 'var(--uxs-sp-1)', minWidth: 260 } }>
						{ r.suggestion ? (
							<button
								type="button"
								className="uxs-btn uxs-btn--small uxs-btn--primary"
								style={ { flexBasis: '100%' } }
								disabled={ quick.isPending }
								title={ t( 'Create a 301 redirect to {title}', { title: r.suggestion.title } ) }
								onClick={ () => r.suggestion && quick.mutate( { row: r, target: r.suggestion.route, code: 301 } ) }
							>
								301 <ArrowRight size={ 12 } aria-hidden="true" /> { r.suggestion.title.slice( 0, 24 ) } ({ r.suggestion.score } %)
							</button>
						) : null }
						<button type="button" className="uxs-btn uxs-btn--small" onClick={ () => onRedirect( r.url ) }>
							{ t( 'Redirect…' ) }
						</button>
						<button type="button" className="uxs-btn uxs-btn--small" title={ t( 'The content is gone for good' ) } disabled={ quick.isPending } onClick={ () => quick.mutate( { row: r, target: '', code: 410 } ) }>
							410
						</button>
						<button type="button" className="uxs-btn uxs-btn--small uxs-btn--danger" aria-label={ t( 'Delete' ) } title={ t( 'Delete' ) } onClick={ () => {
							void confirm( { message: t( 'Delete this URL from the 404 log?' ), confirmLabel: t( 'Delete' ), danger: true } ).then( ( ok ) => ok && clear.mutate( [ r.id ] ) );
						} }>
							<Trash2 size={ 14 } aria-hidden="true" />
						</button>
					</span>
				),
		},
	];

	const total = log.data?.total ?? 0;
	return (
		<>
			<div className="uxs-actions uxs-list-toolbar">
				<label>
					<input
						type="checkbox"
						checked={ unresolved }
						onChange={ ( e ) => {
							setPage( 1 );
							setUnresolved( e.target.checked );
						} }
					/>{ ' ' }
					{ t( 'Only URLs without a redirect' ) }
				</label>
				<button
					type="button"
					className="uxs-btn"
					disabled={ clear.isPending || total === 0 }
					onClick={ () => {
						void confirm( { message: t( 'Clear the whole 404 log?' ), confirmLabel: t( 'Clear log' ), danger: true } ).then( ( ok ) => ok && clear.mutate( null ) );
					} }
				>
					<Trash2 size={ 14 } aria-hidden="true" /> { t( 'Clear log' ) }
				</button>
				<span className="uxs-muted uxs-list-toolbar__count">{ tn( '{n} logged URL', '{n} logged URLs', total ) }</span>
			</div>
			<p className="uxs-muted">{ t( 'Suggestions compare the address with the aliases and titles of your published articles, categories and menu items. Nothing is created until you click.' ) }</p>
			{ log.isLoading ? <Loading /> : null }
			{ log.error ? <p role="alert">{ errorMessage( log.error ) }</p> : null }
			{ log.data ? (
				<DataTable
					columns={ columns }
					rows={ log.data.items }
					rowKey={ ( r ) => r.id }
					empty={ t( 'No 404 errors logged.' ) }
					page={ page }
					pages={ Math.max( 1, Math.ceil( total / PAGE_SIZE ) ) }
					onPage={ setPage }
				/>
			) : null }
		</>
	);
}

/* ------------------------------------------------------------------ */

function TestBox(): JSX.Element {
	const [ url, setUrl ] = useState( '' );
	const [ live, setLive ] = useState( false );
	const test = useMutation( {
		mutationFn: () => api.post< TestResult[] >( 'redirects/test', { urls: [ url ], live } ),
		onError: ( e ) => pushToast( 'error', errorMessage( e ) ),
	} );
	const result = test.data?.[ 0 ];

	return (
		<div className="uxs-form" style={ { marginBottom: 'var(--uxs-sp-4)' } }>
			<h2 className="uxs-form__section">{ t( 'Test a URL' ) }</h2>
			<div className="uxs-form__row">
				<label htmlFor="uxs-rd-test">{ t( 'URL' ) }</label>
				<input id="uxs-rd-test" type="text" value={ url } placeholder="/old-page.html" onChange={ ( e ) => setUrl( e.target.value ) } onKeyDown={ ( e ) => e.key === 'Enter' && url && test.mutate() } />
				<p className="uxs-form__help">{ t( 'Shows which rule answers the address and where the visitor ends up. A live check also sends real requests to your site and reports the status of every hop.' ) }</p>
			</div>
			<div className="uxs-actions">
				<label>
					<input type="checkbox" checked={ live } onChange={ ( e ) => setLive( e.target.checked ) } /> { t( 'Live HTTP check' ) }
				</label>
				<button type="button" className="uxs-btn" disabled={ ! url || test.isPending } onClick={ () => test.mutate() }>
					<Play size={ 14 } aria-hidden="true" /> { t( 'Test' ) }
				</button>
			</div>
			{ result ? (
				<div>
					{ ! result.matched ? (
						<p>{ t( 'No rule matches this URL.' ) }</p>
					) : (
						<p>
							{ result.final_code !== null ? <CodeBadge code={ result.final_code } /> : null } { result.final_url || t( '(gone)' ) }{ ' ' }
							<span className="uxs-badge is-info">{ tn( '{n} hop', '{n} hops', result.hops ) }</span>
						</p>
					) }
					{ result.issues.map( ( issue ) => (
						<span key={ issue } className="uxs-badge is-danger" style={ { marginRight: 4 } }>
							{ issueLabel( issue ) }
						</span>
					) ) }
					{ result.live ? (
						<ol>
							{ result.live.chain.map( ( hop ) => (
								<li key={ hop.url }>
									<code>{ hop.status }</code> { hop.url }
								</li>
							) ) }
							{ result.live.error ? <li>{ result.live.error }</li> : null }
						</ol>
					) : null }
				</div>
			) : null }
		</div>
	);
}

type AuditIssue = AuditResult[ 'issues' ][ number ];

function auditColumns(): Column< AuditIssue >[] {
	return [
		{
			key: 'code',
			header: t( 'Problem' ),
			render: ( issue ) => <span className={ `uxs-badge ${ issue.severity === 'error' ? 'is-danger' : 'is-warning' }` }>{ issueLabel( issue.code ) }</span>,
		},
		{ key: 'source', header: t( 'Source' ), render: ( issue ) => <code>{ issue.source }</code> },
		{ key: 'message', header: t( 'Details' ) },
	];
}

function AuditTab(): JSX.Element {
	const audit = useMutation( {
		mutationFn: ( opts: { live?: boolean; fix_chains?: boolean } ) => api.post< AuditResult >( 'redirects/audit', opts ),
		onSuccess: invalidate,
		onError: ( e ) => pushToast( 'error', errorMessage( e ) ),
	} );
	const data = audit.data;

	return (
		<>
			<TestBox />
			<p className="uxs-muted">{ t( 'The audit checks loops, redirect chains, long-running temporary redirects, mass redirects to the homepage (soft 404) and invalid regular expressions.' ) }</p>
			<div className="uxs-actions" style={ { marginBottom: 'var(--uxs-sp-4)' } }>
				<button type="button" className="uxs-btn uxs-btn--primary" disabled={ audit.isPending } onClick={ () => audit.mutate( {} ) }>
					{ t( 'Run audit' ) }
				</button>
				<button type="button" className="uxs-btn" disabled={ audit.isPending } onClick={ () => audit.mutate( { live: true } ) }>
					{ t( 'Audit + check targets over HTTP' ) }
				</button>
				<button type="button" className="uxs-btn" disabled={ audit.isPending } onClick={ () => {
						void confirm( { message: t( 'Rewrite the targets of rules that point into other redirects so they go straight to the final address?' ), confirmLabel: t( 'Fix chains' ) } ).then( ( ok ) => ok && audit.mutate( { fix_chains: true } ) );
					} }>
					{ t( 'Fix chains' ) }
				</button>
			</div>
			{ audit.isPending ? <Loading /> : null }
			{ data ? (
				<>
					<div className="uxs-cards">
						<div className="uxs-card">
							<span className="uxs-card__num">{ data.summary.rules }</span>
							<span className="uxs-card__label">{ t( 'Active rules' ) }</span>
						</div>
						<div className="uxs-card">
							<span className="uxs-card__num">{ data.summary.errors }</span>
							<span className="uxs-card__label">{ t( 'Errors' ) }</span>
						</div>
						<div className="uxs-card">
							<span className="uxs-card__num">{ data.summary.warnings }</span>
							<span className="uxs-card__label">{ t( 'Warnings' ) }</span>
						</div>
						<div className="uxs-card">
							<span className="uxs-card__num">{ data.summary.fixed }</span>
							<span className="uxs-card__label">{ t( 'Chains fixed' ) }</span>
						</div>
					</div>
					{ data.issues.length === 0 ? (
						<p>
							<span className="uxs-badge is-success">{ t( 'No problems found.' ) }</span>
						</p>
					) : (
						<DataTable
							columns={ auditColumns() }
							rows={ data.issues }
							rowKey={ ( issue ) => `${ issue.code }-${ issue.rule_id ?? 0 }-${ issue.source }` }
							pageSize={ 25 }
						/>
					) }
				</>
			) : null }
		</>
	);
}

/* ------------------------------------------------------------------ */

function BulkSummary( { result }: { result: BulkResult } ): JSX.Element {
	return (
		<div style={ { marginTop: 'var(--uxs-sp-3)' } }>
			<p>
				{ Object.entries( result.summary ).map( ( [ status, count ] ) => (
					<span key={ status } className={ `uxs-badge ${ status === 'error' ? 'is-danger' : 'is-info' }` } style={ { marginRight: 4 } }>
						{ statusLabel( status ) }: { count }
					</span>
				) ) }
			</p>
			{ result.results.slice( 0, 100 ).map( ( r, i ) => (
				<div key={ `${ r.source }-${ i }` }>
					<code>{ r.source }</code>
					<Issues result={ r } />
				</div>
			) ) }
		</div>
	);
}

function ImportTab( { status }: { status: Status | undefined } ): JSX.Element {
	const [ csv, setCsv ] = useState( '' );
	const importCsv = useMutation( {
		mutationFn: ( dry: boolean ) => api.post< BulkResult >( 'redirects/import/csv', { csv, dry_run: dry } ),
		onSuccess: invalidate,
		onError: ( e ) => pushToast( 'error', errorMessage( e ) ),
	} );
	const importJoomla = useMutation( {
		mutationFn: ( dry: boolean ) => api.post< BulkResult >( 'redirects/import/joomla', { dry_run: dry } ),
		onSuccess: invalidate,
		onError: ( e ) => pushToast( 'error', errorMessage( e ) ),
	} );
	const exportCsv = useMutation( {
		mutationFn: () => api.get< { csv: string } >( 'redirects/export' ),
		onSuccess: ( data ) => {
			const link = document.createElement( 'a' );
			link.href = URL.createObjectURL( new Blob( [ data.csv ], { type: 'text/csv;charset=utf-8' } ) );
			link.download = 'redirects.csv';
			link.click();
			URL.revokeObjectURL( link.href );
		},
		onError: ( e ) => pushToast( 'error', errorMessage( e ) ),
	} );

	const readFile = ( file: File | undefined ): void => {
		if ( file ) {
			void file.text().then( setCsv );
		}
	};

	return (
		<>
			<h2 className="uxs-section-title">{ t( 'CSV import' ) }</h2>
			<p className="uxs-muted">
				{ t( 'One redirect per line: source, target, code, note. Header row optional, comma or semicolon separated. Empty target = 410 Gone. The export also has match_type, enabled, hits, position, case_sensitive, query_mode and last_hit columns; the import reads them (counters only for new redirects).' ) }
			</p>
			<div className="uxs-actions" style={ { marginBottom: 'var(--uxs-sp-2)' } }>
				<input type="file" accept=".csv,text/csv,text/plain" aria-label={ t( 'CSV file' ) } onChange={ ( e ) => readFile( e.target.files?.[ 0 ] ) } />
			</div>
			<textarea
				rows={ 8 }
				style={ { width: '100%', fontFamily: 'monospace' } }
				value={ csv }
				aria-label={ t( 'CSV import' ) }
				placeholder={ 'source,target,code\n/old-page.html,/new-page,301\n/removed.html,,410' }
				onChange={ ( e ) => setCsv( e.target.value ) }
			/>
			<div className="uxs-actions" style={ { marginTop: 'var(--uxs-sp-2)' } }>
				<button type="button" className="uxs-btn" disabled={ ! csv || importCsv.isPending } onClick={ () => importCsv.mutate( true ) }>
					{ t( 'Check (dry run)' ) }
				</button>
				<button type="button" className="uxs-btn uxs-btn--primary" disabled={ ! csv || importCsv.isPending } onClick={ () => importCsv.mutate( false ) }>
					<Upload size={ 14 } aria-hidden="true" /> { t( 'Import' ) }
				</button>
			</div>
			{ importCsv.data ? <BulkSummary result={ importCsv.data } /> : null }

			{ status && status.joomla_links > 0 ? (
				<>
					<h2 className="uxs-section-title">{ t( 'Import from Joomla Redirects' ) }</h2>
					<p className="uxs-muted">
						{ tn(
							'Copies the {n} redirect with a new URL from the Joomla Redirects component (collected 404 addresses without a target are not copied). Disable the Joomla Redirect plugin afterwards so the two do not compete.',
							'Copies the {n} redirects with a new URL from the Joomla Redirects component (collected 404 addresses without a target are not copied). Disable the Joomla Redirect plugin afterwards so the two do not compete.',
							status.joomla_links
						) }
					</p>
					<div className="uxs-actions">
						<button type="button" className="uxs-btn" disabled={ importJoomla.isPending } onClick={ () => importJoomla.mutate( true ) }>
							{ t( 'Check (dry run)' ) }
						</button>
						<button type="button" className="uxs-btn uxs-btn--primary" disabled={ importJoomla.isPending } onClick={ () => importJoomla.mutate( false ) }>
							{ t( 'Import' ) }
						</button>
					</div>
					{ importJoomla.data ? <BulkSummary result={ importJoomla.data } /> : null }
				</>
			) : null }

			<h2 className="uxs-section-title">{ t( 'Export' ) }</h2>
			<button type="button" className="uxs-btn" disabled={ exportCsv.isPending } onClick={ () => exportCsv.mutate() }>
				<Download size={ 14 } aria-hidden="true" /> { t( 'Download CSV' ) }
			</button>
		</>
	);
}

/* ------------------------------------------------------------------ */

export default function Page( { module }: { module: ModuleInfo } ): JSX.Element {
	const [ tab, setTab ] = useState( 'rules' );
	const [ draft, setDraft ] = useState< Draft | null >( null );
	const status = useQuery( { queryKey: [ ...KEY, 'status' ], queryFn: () => api.get< Status >( 'redirects/status' ) } );

	return (
		<>
			{ status.data?.joomla_redirect_plugin ? (
				<div className="uxs-notice" role="status">
					{ t( 'The Joomla "System - Redirect" plugin is enabled. Both handle 404 pages: the rules here are tried first, then Joomla\'s own list. Both log 404 addresses (here and in Joomla Redirects). To avoid keeping two lists, import the Joomla redirects on the "Import / export" tab and disable the Joomla plugin.' ) }
				</div>
			) : null }
			<Tabs
				label={ module.name }
				active={ tab }
				onChange={ setTab }
				items={ [
					{ id: 'rules', label: t( 'Redirects' ) },
					{ id: '404', label: t( '404 log' ) },
					{ id: 'audit', label: t( 'Test and audit' ) },
					{ id: 'import', label: t( 'Import / export' ) },
					{ id: 'settings', label: t( 'Settings' ) },
				] }
			/>
			<TabPanel id={ tab }>
				{ tab === 'rules' ? <RulesTab onEdit={ setDraft } /> : null }
				{ tab === '404' ? (
					<NotFoundTab
						onRedirect={ ( url ) => {
							setDraft( { ...EMPTY_DRAFT, source: url } );
						} }
					/>
				) : null }
				{ tab === 'audit' ? <AuditTab /> : null }
				{ tab === 'import' ? <ImportTab status={ status.data } /> : null }
				{ tab === 'settings' ? <SettingsPanel moduleId={ module.id } /> : null }
			</TabPanel>
			{ draft ? <RuleModal key={ `${ draft.id ?? 'new' }-${ draft.source }` } draft={ draft } onClose={ () => setDraft( null ) } /> : null }
		</>
	);
}
