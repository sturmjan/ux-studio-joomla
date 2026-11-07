/**
 * DNS management through the Central App: record list, add / edit / delete with a confirmation, propagation check,
 * change history with revert. Validation, locks and the authoritative log live in the Central App and the module API.
 */
import { useEffect, useMemo, useState } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import { Pencil, Plus, RefreshCw, Radar, Trash2, Undo2, Lock } from 'lucide-react';
import { api, ApiError, queryClient, type ModuleInfo } from '../../app/api';
import { createT, formatDate } from '../../app/i18n';
import { navigate } from '../../app/route';
import { ConfigPanel } from './ConfigPanel';
import { DataTable, Loading, Modal, TabPanel, Tabs, pushToast, type Column } from '../../app/ui';

type RecType = 'A' | 'AAAA' | 'CNAME' | 'MX' | 'TXT';
type Risk = 'mx' | 'email_auth' | 'web' | 'service';

interface DnsRecord {
	id: string;
	type: string;
	name: string;
	content: string;
	ttl: number;
	proxied: boolean;
	priority: number | null;
	comment: string;
	modified_on: string;
	locked?: boolean;
	lock_reason?: string | null;
	read_only?: boolean;
	risk?: Risk[];
	critical?: boolean;
	delete_blocked?: boolean;
}

interface Status {
	state: 'active' | 'inactive' | 'connector_off' | 'not_paired' | 'ca_unreachable' | 'ca_error';
	reason: string;
	message: string;
	zone: string;
	site_host: string;
	is_admin: boolean;
	can_manage: boolean;
	acknowledged: boolean;
	reauth_left: number;
	allow_critical: boolean;
}

interface RecordsPayload {
	active: boolean;
	zone: string;
	records: DnsRecord[];
	cached?: boolean;
}

interface WriteResult {
	status: string;
	record?: DnsRecord | null;
	before?: DnsRecord | null;
	warnings?: Array< { code: string; message: string } >;
}

interface WritePayload {
	results: WriteResult[];
	batch_id: string;
}

interface LogRow extends Record< string, unknown > {
	id: number;
	created_at: string;
	user_login: string;
	user_name: string;
	wp_user_login?: string;
	wp_user_name?: string;
	action: string;
	record_type: string;
	record_name: string;
	before: DnsRecord | null;
	after: DnsRecord | null;
	status: string;
	error: string | null;
	reverted_by?: number | null;
	revert_risk?: Risk[];
	revert_name?: string;
	revert_blocked?: boolean;
}

interface LogPayload {
	rows: LogRow[];
	total: number;
	source: 'central' | 'local';
}

interface Resolver {
	id: string;
	name: string;
	status: 'match' | 'mismatch' | 'empty' | 'info' | 'error';
	values: string[];
	ttl: number | null;
}

interface PropagationResult {
	fqdn: string;
	type: string;
	expected: string | null;
	propagated: boolean | null;
	proxied: boolean;
	resolvers: Resolver[];
}

interface PropagationQuery {
	type: string;
	name: string;
	content?: string;
	priority?: number | null;
	proxied?: boolean;
	absent?: boolean;
}

interface Draft {
	id: string;
	type: RecType;
	name: string;
	content: string;
	priority: string;
	ttl: number;
	proxied: boolean;
	comment: string;
	modified_on: string;
}

type Kind = 'create' | 'update' | 'delete' | 'revert';

/** A change waiting in the confirmation dialog. */
interface Pending {
	kind: Kind;
	title: string;
	before: Partial< DnsRecord > | null;
	after: Partial< DnsRecord > | null;
	risk: Risk[];
	requireName: string;
	run: ( extra: { confirm: true; confirm_name: string } ) => Promise< WritePayload >;
}

const { t } = createT( 'dns-manager' );

const WRITABLE: RecType[] = [ 'A', 'AAAA', 'CNAME', 'MX', 'TXT' ];
const TTL_OPTIONS = [ 1, 60, 120, 300, 600, 1800, 3600, 7200, 14400, 43200, 86400 ];
const EMPTY_DRAFT: Draft = { id: '', type: 'A', name: '', content: '', priority: '10', ttl: 1, proxied: false, comment: '', modified_on: '' };

function ttlLabel( ttl: number ): string {
	return ttl === 1 ? t( 'Auto' ) : `${ ttl } s`;
}

/** `blog.example.cz` in zone `example.cz` -> `blog`; the apex -> `@`. */
function shortName( name: string, zone: string ): string {
	if ( name === zone ) {
		return '@';
	}
	return zone && name.endsWith( `.${ zone }` ) ? name.slice( 0, -( zone.length + 1 ) ) : name;
}

function fullName( name: string, zone: string ): string {
	const n = name.trim().toLowerCase();
	return n === '' || n === '@' ? zone : n === zone || n.endsWith( `.${ zone }` ) ? n : `${ n }.${ zone }`;
}

/** Cloudflare cannot proxy mail or verification records; mirrors the Central App, which decides. */
function proxyForbidden( type: string, name: string, content: string ): boolean {
	if ( ! [ 'A', 'AAAA', 'CNAME' ].includes( type ) ) {
		return true;
	}
	return type === 'CNAME' && ( /(\.mcsv\.net|\.mandrillapp\.com|\.sendgrid\.net|\.mailgun\.org|\.amazonses\.com)$/i.test( content ) || name.includes( '_domainkey' ) );
}

function messageOf( e: unknown ): string {
	return e instanceof Error ? e.message : t( 'Something went wrong.' );
}

/* ------------------------------------------------------------------ */

const FIELDS: Array< { key: keyof DnsRecord; label: () => string } > = [
	{ key: 'type', label: () => t( 'Type' ) },
	{ key: 'name', label: () => t( 'Name' ) },
	{ key: 'content', label: () => t( 'Value' ) },
	{ key: 'priority', label: () => t( 'Priority' ) },
	{ key: 'ttl', label: () => t( 'TTL' ) },
	{ key: 'proxied', label: () => t( 'Proxy' ) },
	{ key: 'comment', label: () => t( 'Note' ) },
];

function fieldText( rec: Partial< DnsRecord > | null, key: keyof DnsRecord ): string {
	if ( ! rec ) {
		return '';
	}
	const v = rec[ key ];
	if ( key === 'ttl' ) {
		return v === undefined ? '' : ttlLabel( Number( v ) );
	}
	if ( key === 'proxied' ) {
		return v === undefined ? '' : v ? t( 'on' ) : t( 'off' );
	}
	return v === null || v === undefined ? '' : String( v );
}

function DiffTable( { before, after }: { before: Partial< DnsRecord > | null; after: Partial< DnsRecord > | null } ): JSX.Element {
	const type = String( before?.type ?? after?.type ?? '' );
	const rows = FIELDS.filter( ( f ) => {
		if ( f.key === 'priority' && type !== 'MX' ) {
			return false;
		}
		if ( f.key === 'proxied' && ! [ 'A', 'AAAA', 'CNAME' ].includes( type ) ) {
			return false;
		}
		return fieldText( before, f.key ) !== '' || fieldText( after, f.key ) !== '';
	} );
	return (
		<table className="uxs-table">
			<tbody>
				{ rows.map( ( f ) => {
					const o = fieldText( before, f.key );
					const n = fieldText( after, f.key );
					const changed = before !== null && after !== null && o !== n;
					return (
						<tr key={ f.key }>
							<th scope="row">{ f.label() }</th>
							<td>
								<code>
									{ changed ? (
										<>
											<del>{ o || '-' }</del> → <strong>{ n || '-' }</strong>
										</>
									) : (
										n || o
									) }
								</code>
							</td>
						</tr>
					);
				} ) }
			</tbody>
		</table>
	);
}

function Warnings( { risk, zone, kind }: { risk: Risk[]; zone: string; kind: Kind } ): JSX.Element {
	return (
		<>
			{ risk.includes( 'mx' ) ? (
				<div className="uxs-notice is-error" role="note">
					<strong>{ t( 'E-mail delivery' ) }</strong>
					<p>{ t( 'Changing or deleting an MX record redirects or stops the delivery of e-mails to @{zone}.', { zone } ) }</p>
				</div>
			) : null }
			{ risk.includes( 'email_auth' ) ? (
				<div className="uxs-notice is-error" role="note">
					<strong>{ t( 'SPF / DKIM / DMARC' ) }</strong>
					<p>{ t( 'Sent e-mails may start landing in spam or be rejected.' ) }</p>
				</div>
			) : null }
			{ risk.includes( 'web' ) ? (
				<div className="uxs-notice is-error" role="note">
					<strong>{ t( 'The website itself' ) }</strong>
					<p>{ t( 'This record leads visitors to your website. A wrong value makes the website unreachable.' ) }</p>
				</div>
			) : null }
			{ risk.includes( 'service' ) ? (
				<div className="uxs-notice" role="note">
					<strong>{ t( 'Service on a subdomain' ) }</strong>
					<p>{ kind === 'delete' ? t( 'The service running on this subdomain will stop responding.' ) : t( 'If a certificate or service runs on this subdomain, it may stop responding after the change.' ) }</p>
				</div>
			) : null }
		</>
	);
}

/* ------------------------------------------------------------------ */

function ConfirmDialog( {
	pending,
	zone,
	needsPassword,
	onDone,
	onCancel,
}: {
	pending: Pending;
	zone: string;
	needsPassword: boolean;
	onDone: ( result: WritePayload ) => void;
	onCancel: () => void;
} ): JSX.Element {
	const [ understood, setUnderstood ] = useState( false );
	const [ typed, setTyped ] = useState( '' );
	const [ password, setPassword ] = useState( '' );
	const [ forceName, setForceName ] = useState( false );
	const [ forcePassword, setForcePassword ] = useState( false );
	const [ error, setError ] = useState( '' );
	const [ stale, setStale ] = useState( false );

	const askPassword = needsPassword || forcePassword;
	const nameRequired = pending.requireName !== '' || forceName;
	const nameToType = pending.requireName || ( pending.before?.name ?? pending.after?.name ?? '' );
	const ready = understood && ( ! nameRequired || typed.trim().toLowerCase() === String( nameToType ).toLowerCase() ) && ( ! askPassword || password !== '' );

	const run = useMutation( {
		mutationFn: async () => {
			if ( askPassword ) {
				await api.post( 'dns-manager/reauth', { password } );
			}
			return pending.run( { confirm: true, confirm_name: typed.trim() } );
		},
		onSuccess: ( data ) => {
			void queryClient.invalidateQueries( { queryKey: [ 'dns-manager' ] } );
			onDone( data );
		},
		onError: ( e: unknown ) => {
			setError( messageOf( e ) );
			setStale( e instanceof ApiError && ( e.status === 409 || e.code === 'record_not_found' ) );
			if ( e instanceof ApiError && e.code === 'name_required' ) {
				setForceName( true );
			}
			if ( e instanceof ApiError && e.code === 'reauth_required' ) {
				setForcePassword( true );
				setPassword( '' );
			}
			void queryClient.invalidateQueries( { queryKey: [ 'dns-manager', 'status' ] } );
		},
	} );

	return (
		<Modal
			open
			wide
			onClose={ () => ( run.isPending ? undefined : onCancel() ) }
			title={ pending.title }
			actions={
				<>
					<button type="button" className="uxs-btn" disabled={ run.isPending } onClick={ onCancel }>
						{ t( 'Cancel' ) }
					</button>
					<button type="button" className={ `uxs-btn ${ pending.kind === 'delete' ? 'uxs-btn--danger' : 'uxs-btn--primary' }` } disabled={ ! ready || run.isPending } onClick={ () => run.mutate() }>
						{ run.isPending ? t( 'Saving…' ) : t( 'Confirm and save' ) }
					</button>
				</>
			}
		>
			<DiffTable before={ pending.before } after={ pending.after } />
			<Warnings risk={ pending.risk } zone={ zone } kind={ pending.kind } />
			<label style={ { display: 'flex', gap: 'var(--uxs-sp-2)', alignItems: 'flex-start', margin: 'var(--uxs-sp-3) 0' } }>
				<input type="checkbox" checked={ understood } onChange={ ( e ) => setUnderstood( e.target.checked ) } />
				<span>{ t( 'I understand that this change will affect the functioning of the website and connected services.' ) }</span>
			</label>
			{ nameRequired ? (
				<div className="uxs-form__row">
					<label htmlFor="uxs-dns-typed">{ t( 'To confirm, type the record name: {name}', { name: String( nameToType ) } ) }</label>
					<input id="uxs-dns-typed" className="uxs-input" type="text" value={ typed } autoComplete="off" onChange={ ( e ) => setTyped( e.target.value ) } />
				</div>
			) : null }
			{ askPassword ? (
				<div className="uxs-form__row">
					<label htmlFor="uxs-dns-password">{ t( 'Confirm your password (required every 15 minutes)' ) }</label>
					<input id="uxs-dns-password" className="uxs-input" type="password" value={ password } autoComplete="current-password" onChange={ ( e ) => setPassword( e.target.value ) } />
				</div>
			) : null }
			{ error ? (
				<div className="uxs-notice is-error" role="alert">
					<p>{ error }</p>
					{ stale ? (
						<button
							type="button"
							className="uxs-btn uxs-btn--small"
							onClick={ () => {
								void queryClient.invalidateQueries( { queryKey: [ 'dns-manager', 'records' ] } );
								onCancel();
							} }
						>
							<RefreshCw size={ 14 } aria-hidden="true" /> { t( 'Reload records' ) }
						</button>
					) : null }
				</div>
			) : null }
		</Modal>
	);
}

/* ------------------------------------------------------------------ */

function PropagationPanel( { query, onClose }: { query: PropagationQuery; onClose: () => void } ): JSX.Element {
	const check = useQuery( {
		queryKey: [ 'dns-manager', 'propagation', query ],
		queryFn: () => api.get< PropagationResult >( 'dns-manager/propagation', { type: query.type, name: query.name, content: query.content, priority: query.priority ?? undefined, proxied: query.proxied ? 1 : undefined, absent: query.absent ? 1 : undefined } ),
		staleTime: 0,
		gcTime: 0,
	} );
	const d = check.data;
	const label = ( s: Resolver[ 'status' ] ): string => ( s === 'match' ? t( 'Correct value' ) : s === 'mismatch' ? t( 'Other value' ) : s === 'empty' ? t( 'No record' ) : s === 'info' ? t( 'Current answer' ) : t( 'No answer' ) );
	const badge = ( s: Resolver[ 'status' ] ): string => ( s === 'match' ? 'is-success' : s === 'mismatch' ? 'is-warning' : s === 'error' ? 'is-danger' : 'is-info' );

	return (
		<Modal
			open
			onClose={ onClose }
			title={ t( 'Propagation of {name}', { name: d?.fqdn ?? query.name } ) }
			actions={
				<>
					<button type="button" className="uxs-btn" disabled={ check.isFetching } onClick={ () => void check.refetch() }>
						<RefreshCw size={ 14 } aria-hidden="true" /> { t( 'Check again' ) }
					</button>
					<button type="button" className="uxs-btn uxs-btn--primary" onClick={ onClose }>
						{ t( 'Close' ) }
					</button>
				</>
			}
		>
			{ check.isLoading ? <Loading /> : null }
			{ check.error ? <p role="alert">{ check.error.message }</p> : null }
			{ d ? (
				<>
					<p className="uxs-modal__body">
						{ d.propagated === true
							? t( 'The change is visible on the public resolvers.' )
							: d.propagated === false
							? t( 'The change is not visible everywhere yet. Resolvers keep the old answer until its TTL expires, try again in a few minutes.' )
							: d.proxied
							? t( 'The record is proxied through Cloudflare, so the resolvers show Cloudflare addresses and the change applies immediately.' )
							: t( 'This is what the public resolvers answer right now.' ) }
					</p>
					<table className="uxs-table">
						<tbody>
							{ d.resolvers.map( ( r ) => (
								<tr key={ r.id }>
									<th scope="row">{ r.name }</th>
									<td>
										<span className={ `uxs-badge ${ badge( r.status ) }` }>{ label( r.status ) }</span>
										{ r.values.map( ( v ) => (
											<div key={ v }>
												<code>{ v }</code>
											</div>
										) ) }
										{ r.ttl !== null ? <span className="uxs-muted">TTL {r.ttl} s</span> : null }
									</td>
								</tr>
							) ) }
						</tbody>
					</table>
				</>
			) : null }
		</Modal>
	);
}

/* ------------------------------------------------------------------ */

function RecordForm( { draft, zone, onSubmit, onCancel }: { draft: Draft; zone: string; onSubmit: ( d: Draft ) => void; onCancel: () => void } ): JSX.Element {
	const [ d, setD ] = useState< Draft >( draft );
	const [ localError, setLocalError ] = useState( '' );
	const editing = d.id !== '';
	const proxyOff = proxyForbidden( d.type, d.name, d.content );
	const set = ( patch: Partial< Draft > ): void => setD( ( cur ) => ( { ...cur, ...patch } ) );

	const validate = (): string => {
		if ( d.name.includes( '*' ) ) {
			return t( 'Wildcard records (*) are not supported yet.' );
		}
		if ( d.content.trim() === '' ) {
			return t( 'Fill in the record value.' );
		}
		if ( d.type === 'MX' && ! /^\d{1,5}$/.test( d.priority.trim() ) ) {
			return t( 'MX priority must be a whole number 0-65535.' );
		}
		if ( d.type === 'CNAME' && ( d.name.trim() === '' || d.name.trim() === '@' ) ) {
			return t( 'A CNAME cannot be used at the domain root.' );
		}
		return '';
	};

	return (
		<Modal
			open
			wide
			onClose={ onCancel }
			title={ editing ? t( 'Edit record' ) : t( 'Add record' ) }
			actions={
				<>
					<button type="button" className="uxs-btn" onClick={ onCancel }>
						{ t( 'Cancel' ) }
					</button>
					<button
						type="button"
						className="uxs-btn uxs-btn--primary"
						onClick={ () => {
							const msg = validate();
							setLocalError( msg );
							if ( msg === '' ) {
								onSubmit( d );
							}
						} }
					>
						{ t( 'Review change…' ) }
					</button>
				</>
			}
		>
			<div className="uxs-form uxs-form--grid" style={ { border: 'none', padding: 0 } }>
				<div className="uxs-form__row">
					<label htmlFor="uxs-dns-type">{ t( 'Type' ) }</label>
					<select id="uxs-dns-type" value={ d.type } disabled={ editing } onChange={ ( e ) => set( { type: e.target.value as RecType, proxied: false } ) }>
						{ WRITABLE.map( ( x ) => (
							<option key={ x } value={ x }>
								{ x }
							</option>
						) ) }
					</select>
				</div>
				<div className="uxs-form__row">
					<label htmlFor="uxs-dns-name">{ t( 'Name' ) }</label>
					<input id="uxs-dns-name" className="uxs-input" type="text" value={ d.name } placeholder="@" onChange={ ( e ) => set( { name: e.target.value } ) } />
					<p className="uxs-form__help">{ t( '@ = {zone}, "blog" = blog.{zone}', { zone } ) }</p>
				</div>
				<div className="uxs-form__row">
					<label htmlFor="uxs-dns-content">{ t( 'Value' ) }</label>
					<input id="uxs-dns-content" className="uxs-input" type="text" value={ d.content } onChange={ ( e ) => set( { content: e.target.value } ) } />
				</div>
				{ d.type === 'MX' ? (
					<div className="uxs-form__row">
						<label htmlFor="uxs-dns-prio">{ t( 'Priority' ) }</label>
						<input id="uxs-dns-prio" className="uxs-input" type="number" min={ 0 } max={ 65535 } value={ d.priority } onChange={ ( e ) => set( { priority: e.target.value } ) } />
					</div>
				) : null }
				<div className="uxs-form__row">
					<label htmlFor="uxs-dns-ttl">{ t( 'TTL' ) }</label>
					<select id="uxs-dns-ttl" value={ d.proxied ? 1 : d.ttl } disabled={ d.proxied } onChange={ ( e ) => set( { ttl: Number( e.target.value ) } ) }>
						{ TTL_OPTIONS.map( ( x ) => (
							<option key={ x } value={ x }>
								{ ttlLabel( x ) }
							</option>
						) ) }
					</select>
					<p className="uxs-form__help">{ t( 'The TTL says how long other servers remember the record before they ask again. A short time makes a change visible sooner, a long one lowers the load. The priority matters for mail (MX) records only: the server with the lower number is tried first.' ) }</p>
				</div>
				<div className="uxs-form__row">
					<label htmlFor="uxs-dns-comment">{ t( 'Note' ) }</label>
					<input id="uxs-dns-comment" className="uxs-input" type="text" maxLength={ 100 } value={ d.comment } onChange={ ( e ) => set( { comment: e.target.value } ) } />
				</div>
				{ [ 'A', 'AAAA', 'CNAME' ].includes( d.type ) ? (
					<div className="uxs-form__row">
						<label>
							<input type="checkbox" checked={ d.proxied && ! proxyOff } disabled={ proxyOff } onChange={ ( e ) => set( { proxied: e.target.checked } ) } /> { t( 'Proxied through Cloudflare (orange cloud)' ) }
						</label>
						<p className="uxs-form__help">{ t( 'With the orange cloud the visitors reach the site through Cloudflare, which hides the real address of the server. The TTL is then set by Cloudflare. Leave it off for mail and verification records.' ) }</p>
					</div>
				) : null }
			</div>
			{ localError ? (
				<div className="uxs-notice is-error" role="alert">
					<p>{ localError }</p>
				</div>
			) : null }
		</Modal>
	);
}

/* ------------------------------------------------------------------ */

function RecordsTab( { status, zone, needsPassword }: { status: Status; zone: string; needsPassword: boolean } ): JSX.Element {
	const canEdit = status.can_manage && status.acknowledged;
	const [ typeFilter, setTypeFilter ] = useState( '' );
	const [ search, setSearch ] = useState( '' );
	const [ draft, setDraft ] = useState< Draft | null >( null );
	const [ pending, setPending ] = useState< Pending | null >( null );
	const [ saved, setSaved ] = useState< WriteResult | null >( null );
	const [ probe, setProbe ] = useState< PropagationQuery | null >( null );

	const records = useQuery( { queryKey: [ 'dns-manager', 'records' ], queryFn: () => api.get< RecordsPayload >( 'dns-manager/records' ) } );
	const reload = useMutation( {
		mutationFn: () => api.get< RecordsPayload >( 'dns-manager/records', { fresh: 1 } ),
		onSuccess: ( data ) => queryClient.setQueryData( [ 'dns-manager', 'records' ], data ),
		onError: ( e: unknown ) => pushToast( 'error', messageOf( e ) ),
	} );

	const list = useMemo( () => records.data?.records ?? [], [ records.data ] );
	const types = useMemo( () => Array.from( new Set( list.map( ( r ) => r.type ) ) ).sort(), [ list ] );
	const shown = list.filter( ( r ) => ( typeFilter === '' || r.type === typeFilter ) && ( search === '' || `${ r.name } ${ r.content }`.toLowerCase().includes( search.toLowerCase() ) ) );

	const body = ( d: Draft ) => ( {
		type: d.type,
		name: d.name.trim() === '' ? '@' : d.name.trim(),
		content: d.content.trim(),
		ttl: d.proxied ? 1 : d.ttl,
		proxied: d.proxied && ! proxyForbidden( d.type, d.name, d.content ),
		priority: d.type === 'MX' ? Number( d.priority ) : null,
		comment: d.comment,
	} );

	const submitDraft = ( d: Draft ): void => {
		const rec = body( d );
		const after: Partial< DnsRecord > = { ...rec, name: fullName( rec.name, zone ) };
		const before = d.id ? list.find( ( r ) => r.id === d.id ) ?? null : null;
		const risk = Array.from( new Set( [ ...( before?.risk ?? [] ) ] ) );
		const hard = risk.some( ( x ) => x !== 'service' );
		setDraft( null );
		setPending( {
			kind: d.id ? 'update' : 'create',
			title: d.id ? t( 'Confirm the change of the record' ) : t( 'Confirm the new record' ),
			before,
			after,
			risk,
			requireName: d.id && hard && before ? before.name : '',
			run: ( extra ) =>
				d.id
					? api.post< WritePayload >( `dns-manager/records/${ d.id }`, { record: rec, modified_on: d.modified_on, ...extra } )
					: api.post< WritePayload >( 'dns-manager/records', { record: rec, ...extra } ),
		} );
	};

	const askDelete = ( rec: DnsRecord ): void => {
		setPending( {
			kind: 'delete',
			title: t( 'Confirm deleting the record' ),
			before: rec,
			after: null,
			risk: rec.risk ?? [],
			requireName: ( rec.risk ?? [] ).length > 0 ? rec.name : '',
			run: ( extra ) => api.post< WritePayload >( `dns-manager/records/${ rec.id }/delete`, { modified_on: rec.modified_on, ...extra } ),
		} );
	};

	const startEdit = ( rec: DnsRecord ): void => {
		setSaved( null );
		setDraft( {
			id: rec.id,
			type: rec.type as RecType,
			name: shortName( rec.name, zone ),
			content: rec.content,
			priority: rec.priority === null ? '10' : String( rec.priority ),
			ttl: rec.ttl,
			proxied: rec.proxied,
			comment: rec.comment,
			modified_on: rec.modified_on,
		} );
	};

	const savedRec = saved ? saved.record ?? saved.before ?? null : null;

	return (
		<>
			{ saved && savedRec ? (
				<div className="uxs-notice" role="status">
					<p>
						<strong>{ t( 'Change saved.' ) }</strong>{ ' ' }
						{ savedRec.proxied ? t( 'The record is proxied through Cloudflare, so the change applies immediately.' ) : t( 'The change appears after the TTL expires ({ttl}). Computers and networks may keep the old value in their cache for a while.', { ttl: ttlLabel( savedRec.ttl ) } ) }
					</p>
					{ ( saved.warnings ?? [] ).map( ( w ) => (
						<p key={ w.code }>
							<span className="uxs-badge is-warning">{ t( 'Notice' ) }</span> { w.message }
						</p>
					) ) }
					<button
						type="button"
						className="uxs-btn uxs-btn--small"
						onClick={ () => setProbe( { type: savedRec.type, name: savedRec.name, content: saved.record ? savedRec.content : undefined, priority: savedRec.priority, proxied: savedRec.proxied, absent: ! saved.record } ) }
					>
						<Radar size={ 14 } aria-hidden="true" /> { t( 'Check propagation' ) }
					</button>
				</div>
			) : null }

			<div className="uxs-actions" style={ { marginBottom: 'var(--uxs-sp-4)', flexWrap: 'wrap' } }>
				<select aria-label={ t( 'Filter by type' ) } value={ typeFilter } onChange={ ( e ) => setTypeFilter( e.target.value ) }>
					<option value="">{ t( 'All types' ) }</option>
					{ types.map( ( x ) => (
						<option key={ x } value={ x }>
							{ x }
						</option>
					) ) }
				</select>
				<input className="uxs-input" type="search" value={ search } placeholder={ t( 'Search name or value…' ) } aria-label={ t( 'Search name or value…' ) } onChange={ ( e ) => setSearch( e.target.value ) } />
				<button type="button" className="uxs-btn" disabled={ reload.isPending } onClick={ () => reload.mutate() }>
					<RefreshCw size={ 14 } aria-hidden="true" /> { t( 'Reload' ) }
				</button>
				{ canEdit ? (
					<button
						type="button"
						className="uxs-btn uxs-btn--primary"
						onClick={ () => {
							setSaved( null );
							setDraft( EMPTY_DRAFT );
						} }
					>
						<Plus size={ 14 } aria-hidden="true" /> { t( 'Add record' ) }
					</button>
				) : null }
			</div>

			{ ! canEdit ? (
				<p className="uxs-muted">
					{ ! status.can_manage
						? t( 'You can view the records. Editing is allowed only to Super Users selected in the module settings.' )
						: t( 'You are in view-only mode until you confirm that you understand the risks.' ) }
				</p>
			) : null }
			{ records.isLoading ? <Loading /> : null }
			{ records.error ? <p role="alert">{ records.error.message }</p> : null }
			{ records.data && shown.length === 0 ? <p>{ t( 'No records found.' ) }</p> : null }
			{ shown.length > 0 ? (
				<div className="uxs-table-wrap">
					<table className="uxs-table">
						<thead>
							<tr>
								<th>{ t( 'Type' ) }</th>
								<th>{ t( 'Name' ) }</th>
								<th>{ t( 'Value' ) }</th>
								<th>{ t( 'TTL' ) }</th>
								<th>{ t( 'Proxy' ) }</th>
								<th>
									{ '' }
								</th>
							</tr>
						</thead>
						<tbody>
							{ shown.map( ( r ) => {
								const blocked = r.locked || r.read_only;
								return (
									<tr key={ r.id }>
										<td>
											<span className="uxs-badge is-info">{ r.type }</span>
										</td>
										<td>
											<code>{ shortName( r.name, zone ) }</code>
											{ r.comment ? <div className="uxs-muted">{ r.comment }</div> : null }
										</td>
										<td>
											{ r.type === 'MX' && r.priority !== null ? <span className="uxs-badge is-info">{ r.priority }</span> : null } <code>{ r.content }</code>
										</td>
										<td>{ ttlLabel( r.ttl ) }</td>
										<td>{ r.proxied ? <span className="uxs-badge is-warning">{ t( 'on' ) }</span> : '' }</td>
										<td style={ { whiteSpace: 'nowrap', textAlign: 'right' } }>
											{ r.locked ? (
												<span className="uxs-badge is-warning" title={ r.lock_reason ?? '' }>
													<Lock size={ 12 } aria-hidden="true" /> { t( 'Locked' ) }
												</span>
											) : null }
											{ r.read_only && ! r.locked ? <span className="uxs-badge is-info">{ t( 'Read only' ) }</span> : null }{ ' ' }
											<button type="button" className="uxs-btn uxs-btn--small" aria-label={ t( 'Check propagation' ) } title={ t( 'Check propagation' ) } onClick={ () => setProbe( { type: r.type, name: r.name, content: r.content, priority: r.priority, proxied: r.proxied } ) }>
												<Radar size={ 14 } aria-hidden="true" />
											</button>{ ' ' }
											{ canEdit && ! blocked ? (
												<>
													<button type="button" className="uxs-btn uxs-btn--small" aria-label={ t( 'Edit' ) } title={ t( 'Edit' ) } onClick={ () => startEdit( r ) }>
														<Pencil size={ 14 } aria-hidden="true" />
													</button>{ ' ' }
													<button
														type="button"
														className="uxs-btn uxs-btn--small"
														aria-label={ t( 'Delete' ) }
														title={ r.delete_blocked ? t( 'Critical record: deleting is disabled in the module settings.' ) : t( 'Delete' ) }
														disabled={ r.delete_blocked }
														onClick={ () => askDelete( r ) }
													>
														<Trash2 size={ 14 } aria-hidden="true" />
													</button>
												</>
											) : null }
										</td>
									</tr>
								);
							} ) }
						</tbody>
					</table>
				</div>
			) : null }

			{ draft ? <RecordForm draft={ draft } zone={ zone } onSubmit={ submitDraft } onCancel={ () => setDraft( null ) } /> : null }
			{ pending ? (
				<ConfirmDialog
					pending={ pending }
					zone={ zone }
					needsPassword={ needsPassword }
					onCancel={ () => setPending( null ) }
					onDone={ ( res ) => {
						setSaved( res.results[ 0 ] ?? null );
						setPending( null );
					} }
				/>
			) : null }
			{ probe ? <PropagationPanel query={ probe } onClose={ () => setProbe( null ) } /> : null }
		</>
	);
}

/* ------------------------------------------------------------------ */

function actionLabel( a: string ): string {
	switch ( a ) {
		case 'create':
			return t( 'Added' );
		case 'update':
			return t( 'Changed' );
		case 'delete':
			return t( 'Deleted' );
		case 'revert':
			return t( 'Reverted' );
		case 'denied':
			return t( 'Denied' );
		case 'acknowledged':
			return t( 'Risks acknowledged' );
		case 'reauth':
			return t( 'Password confirmation' );
		default:
			return a;
	}
}

function HistoryTab( { status, zone, needsPassword }: { status: Status; zone: string; needsPassword: boolean } ): JSX.Element {
	const canEdit = status.can_manage && status.acknowledged && status.state === 'active';
	const [ action, setAction ] = useState( '' );
	const [ pending, setPending ] = useState< Pending | null >( null );
	const query = { per_page: 100, ...( action ? { log_action: action } : {} ) };
	const log = useQuery( { queryKey: [ 'dns-manager', 'log', action ], queryFn: () => api.get< LogPayload >( 'dns-manager/log', query ) } );

	const askRevert = ( row: LogRow ): void => {
		setPending( {
			kind: 'revert',
			title: t( 'Confirm reverting the change' ),
			before: row.after,
			after: row.before,
			risk: row.revert_risk ?? [],
			requireName: row.revert_name ?? '',
			run: ( extra ) => api.post< WritePayload >( 'dns-manager/revert', { log_id: row.id, confirm: extra.confirm, confirm_name: extra.confirm_name } ),
		} );
	};

	const columns: Array< Column< LogRow > > = [
		{ key: 'created_at', header: t( 'When' ), render: ( r ) => formatDate( r.created_at ) },
		{ key: 'user_name', header: t( 'Who' ), render: ( r ) => r.user_name || r.wp_user_name || r.user_login || r.wp_user_login || '' },
		{
			key: 'action',
			header: t( 'Action' ),
			render: ( r ) => (
				<>
					<span className={ `uxs-badge ${ r.status === 'success' ? 'is-success' : r.status === 'denied' ? 'is-warning' : 'is-danger' }` }>{ actionLabel( r.action ) }</span>
					{ r.reverted_by ? <div className="uxs-muted">{ t( 'reverted' ) }</div> : null }
				</>
			),
		},
		{
			key: 'record_name',
			header: t( 'Change' ),
			render: ( r ) => (
				<>
					{ r.error ? <div className="uxs-muted">{ r.error }</div> : null }
					{ r.before || r.after ? <DiffTable before={ r.before } after={ r.after } /> : null }
				</>
			),
		},
		{
			key: 'id',
			header: '',
			render: ( r ) =>
				canEdit && r.status === 'success' && [ 'create', 'update', 'delete', 'revert' ].includes( r.action ) && ! r.reverted_by && log.data?.source === 'central' ? (
					<button type="button" className="uxs-btn uxs-btn--small" disabled={ !! r.revert_blocked } title={ r.revert_blocked ? t( 'Reverting this change would remove or move a critical record. Allow it in the Settings first.' ) : undefined } onClick={ () => askRevert( r ) }>
						<Undo2 size={ 14 } aria-hidden="true" /> { t( 'Revert' ) }
					</button>
				) : null,
		},
	];

	return (
		<>
			{ log.data?.source === 'local' ? <div className="uxs-notice">{ t( 'The central app is not reachable, showing the local copy of the history.' ) }</div> : null }
			<div className="uxs-actions" style={ { marginBottom: 'var(--uxs-sp-4)' } }>
				<select aria-label={ t( 'Action' ) } value={ action } onChange={ ( e ) => setAction( e.target.value ) }>
					<option value="">{ t( 'All actions' ) }</option>
					{ [ 'create', 'update', 'delete', 'revert', 'denied', 'acknowledged' ].map( ( a ) => (
						<option key={ a } value={ a }>
							{ actionLabel( a ) }
						</option>
					) ) }
				</select>
			</div>
			{ log.isLoading ? <Loading /> : null }
			{ log.error ? <p role="alert">{ log.error.message }</p> : null }
			{ log.data ? <DataTable columns={ columns } rows={ log.data.rows } rowKey={ ( r ) => `${ log.data?.source }-${ r.id }` } pageSize={ 15 } empty={ t( 'No changes recorded.' ) } /> : null }
			{ pending ? (
				<ConfirmDialog
					pending={ pending }
					zone={ zone }
					needsPassword={ needsPassword }
					onCancel={ () => setPending( null ) }
					onDone={ () => {
						setPending( null );
						pushToast( 'success', t( 'The change was reverted.' ) );
					} }
				/>
			) : null }
		</>
	);
}

/* ------------------------------------------------------------------ */

function StateNotice( { status }: { status: Status } ): JSX.Element {
	return (
		<div className="uxs-notice" role="status">
			<p>
				<strong>{ status.state === 'ca_unreachable' || status.state === 'ca_error' ? t( 'The Central App is not available' ) : t( 'DNS management is not active' ) }</strong>
			</p>
			<p>{ status.message }</p>
			{ status.state === 'connector_off' || status.state === 'not_paired' ? (
				<button type="button" className="uxs-btn uxs-btn--small" onClick={ () => navigate( 'module', { id: 'ca-connector' } ) }>
					{ t( 'Open the Central App module' ) }
				</button>
			) : null }
		</div>
	);
}

function AckDialog( { onDismiss }: { onDismiss: () => void } ): JSX.Element {
	const ack = useMutation( {
		mutationFn: () => api.post( 'dns-manager/ack' ),
		onSuccess: () => void queryClient.invalidateQueries( { queryKey: [ 'dns-manager', 'status' ] } ),
		onError: ( e: unknown ) => pushToast( 'error', messageOf( e ) ),
	} );
	return (
		<Modal
			open
			onClose={ onDismiss }
			title={ t( 'Before you change DNS' ) }
			actions={
				<>
					<button type="button" className="uxs-btn" disabled={ ack.isPending } onClick={ onDismiss }>
						{ t( 'View only' ) }
					</button>
					<button type="button" className="uxs-btn uxs-btn--primary" disabled={ ack.isPending } onClick={ () => ack.mutate() }>
						{ t( 'I understand the risks' ) }
					</button>
				</>
			}
		>
			<p className="uxs-modal__body">{ t( 'DNS records decide where your website, e-mail and other services of the domain lead. A change is applied immediately and a mistake can make the website unreachable or lose e-mails.' ) }</p>
			<p className="uxs-modal__body">{ t( 'Every change is logged with your name and IP address and the administrators are notified by e-mail. You can revert a change in the history.' ) }</p>
		</Modal>
	);
}

export default function Page( { module }: { module: ModuleInfo } ): JSX.Element {
	const [ tab, setTab ] = useState( 'records' );
	const [ dismissed, setDismissed ] = useState( false );
	const status = useQuery( { queryKey: [ 'dns-manager', 'status' ], queryFn: () => api.get< Status >( 'dns-manager/status' ) } );
	const s = status.data;
	const fetchedAt = status.dataUpdatedAt;
	const [ now, setNow ] = useState( () => Date.now() );
	useEffect( () => {
		const id = window.setInterval( () => setNow( Date.now() ), 15000 );
		return () => window.clearInterval( id );
	}, [] );
	// The password confirmation is valid for 15 minutes; ask again when less than a minute is left.
	const needsPassword = ! s || s.reauth_left - Math.floor( ( now - fetchedAt ) / 1000 ) < 60;
	const items = s && s.state === 'active' ? [ { id: 'records', label: t( 'Records' ) }, { id: 'history', label: t( 'Change history' ) }, { id: 'settings', label: t( 'Settings' ) } ] : [ { id: 'records', label: t( 'Overview' ) }, { id: 'history', label: t( 'Change history' ) }, { id: 'settings', label: t( 'Settings' ) } ];
	const active = tab;

	return (
		<>
			{ s?.state === 'active' ? (
				<div className="uxs-callout" role="note">
					<strong>{ t( 'DNS changes take effect immediately.' ) }</strong> { t( 'They directly affect the website, e-mail mailboxes and other services tied to the domain (Google verification, payment gateways, SSL certificate). A wrong record can mean an unreachable website or lost e-mails. If you are not sure, contact us.' ) }
					{ s.zone ? (
						<>
							{ ' ' }
							<span className="uxs-badge is-info">{ s.zone }</span>
						</>
					) : null }
				</div>
			) : null }
			<Tabs label={ module.name } active={ active } onChange={ setTab } items={ items } />
			{ status.isLoading ? <Loading /> : null }
			{ status.error ? <p role="alert">{ status.error.message }</p> : null }
			{ s && active === 'records' ? <TabPanel id="records">{ s.state === 'active' ? <RecordsTab status={ s } zone={ s.zone } needsPassword={ needsPassword } /> : <StateNotice status={ s } /> }</TabPanel> : null }
			{ s && active === 'history' ? <TabPanel id="history"><HistoryTab status={ s } zone={ s.zone } needsPassword={ needsPassword } /></TabPanel> : null }
			{ active === 'settings' ? (
				<TabPanel id="settings">
					<ConfigPanel isAdmin={ !! s?.is_admin } />
				</TabPanel>
			) : null }
			{ s && s.state === 'active' && s.can_manage && ! s.acknowledged && ! dismissed ? <AckDialog onDismiss={ () => setDismissed( true ) } /> : null }
		</>
	);
}
