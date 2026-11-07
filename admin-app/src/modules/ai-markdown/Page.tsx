/**
 * AI Markdown: overview of the AI-facing addresses, the public articles with their cache state, the AI bot access log,
 * robots.txt guidance for AI crawlers, and the settings.
 */
import { useState } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import { Copy, ExternalLink, Eye, RefreshCw, Trash2 } from 'lucide-react';
import { api, queryClient, type ModuleInfo } from '../../app/api';
import { createT, formatDate } from '../../app/i18n';
import { SettingsPanel } from '../../app/SettingsForm';
import { DataTable, Loading, Modal, TabPanel, Tabs, confirm, pushToast, type Column } from '../../app/ui';

const { t, tn } = createT( 'ai-markdown' );

type BotStat = { bot_name: string; count: number };

type Status = {
	urls: { llms_txt: string; llms_full: string; sitemap: string };
	markdown: boolean;
	accept: boolean;
	public: number;
	cached: number;
	log: { total: number; last_24h: number; bots: BotStat[] };
	link_style: string;
};

type ArticleRow = {
	id: number;
	title: string;
	category: string;
	url: string;
	md_url: string;
	generated_at: string | null;
};

type ArticlePage = { items: ArticleRow[]; total: number; page: number; per_page: number };

type LogRow = {
	id: number;
	created: string;
	kind: string;
	ref_id: number;
	bot_name: string;
	url: string;
	via: string;
	ip_address: string;
	user_agent: string;
};

type LogPage = { items: LogRow[]; total: number; page: number; per_page: number };

type RobotBot = { token: string; name: string; purpose: string; status: string; source: string };

type Robots = { exists: boolean; file: string; bots: RobotBot[]; policy: string; snippet: string };

type Preview = { content: string; generated_at: string; bytes: number };

const KEY = 'ai-markdown';
const refreshAll = (): void => void queryClient.invalidateQueries( { queryKey: [ KEY ] } );

function kindLabel( kind: string ): string {
	switch ( kind ) {
		case 'llms':
		case 'llms_txt':
			return 'llms.txt';
		case 'llms_full':
			return 'llms-full.txt';
		case 'sitemap':
			return t( 'AI sitemap' );
		case 'article':
			return t( 'Article' );
		case 'category':
			return t( 'Category' );
		default:
			return kind;
	}
}

function viaLabel( via: string ): string {
	switch ( via ) {
		case 'query':
			return '?format=md';
		case 'suffix':
			return '.md';
		case 'accept':
			return 'Accept';
		case 'file':
			return t( 'File' );
		default:
			return via;
	}
}

function PreviewModal( { request, onClose }: { request: { kind?: string; id?: number; title: string } | null; onClose: () => void } ): JSX.Element {
	const query = useQuery( {
		queryKey: [ KEY, 'preview', request?.kind ?? '', request?.id ?? 0 ],
		queryFn: () => api.get< Preview >( 'ai-markdown/preview', request?.kind ? { kind: request.kind } : { id: request?.id ?? 0 } ),
		enabled: request !== null,
		retry: false,
	} );

	return (
		<Modal
			open={ request !== null }
			onClose={ onClose }
			wide
			title={ request ? request.title : '' }
			actions={
				<button type="button" className="uxs-btn" onClick={ onClose }>
					{ t( 'Close' ) }
				</button>
			}
		>
			{ query.isLoading ? <Loading /> : null }
			{ query.error ? <p role="alert">{ query.error.message }</p> : null }
			{ query.data ? (
				<>
					<p className="uxs-muted">
						{ formatDate( query.data.generated_at ) } · { t( '{n} bytes', { n: query.data.bytes } ) }
					</p>
					<pre className="uxs-code-editor" style={ { whiteSpace: 'pre-wrap', wordBreak: 'break-word', maxHeight: 420, overflow: 'auto' } }>
						{ query.data.content }
					</pre>
				</>
			) : null }
		</Modal>
	);
}

function Overview( { onPreview }: { onPreview: ( r: { kind: string; title: string } ) => void } ): JSX.Element {
	const status = useQuery( { queryKey: [ KEY, 'status' ], queryFn: () => api.get< Status >( 'ai-markdown/status' ) } );
	const regenerate = useMutation( {
		mutationFn: () => api.post< { regenerated: number; queued: boolean } >( 'ai-markdown/regenerate-all' ),
		onSuccess: ( r ) => {
			pushToast( 'success', tn( 'Cache cleared, {n} article is rebuilt in the background', 'Cache cleared, {n} articles are rebuilt in the background', r.regenerated ) );
			refreshAll();
		},
		onError: ( e: Error ) => pushToast( 'error', e.message ),
	} );
	const clear = useMutation( {
		mutationFn: () => api.post( 'ai-markdown/cache/clear' ),
		onSuccess: () => {
			pushToast( 'success', t( 'The cache was emptied.' ) );
			refreshAll();
		},
		onError: ( e: Error ) => pushToast( 'error', e.message ),
	} );

	const s = status.data;
	if ( status.isLoading || ! s ) {
		return <Loading />;
	}
	const rows: Array< { kind: string; label: string; url: string } > = [
		{ kind: 'llms', label: 'llms.txt', url: s.urls.llms_txt },
		{ kind: 'llms-full', label: 'llms-full.txt', url: s.urls.llms_full },
		{ kind: 'sitemap', label: 'ai-sitemap.md', url: s.urls.sitemap },
	];

	return (
		<>
			<div className="uxs-cards">
				<div className="uxs-card"><span className="uxs-card__label">{ t( 'Public articles' ) }</span><span className="uxs-card__num">{ s.public }</span></div>
				<div className="uxs-card"><span className="uxs-card__label">{ t( 'Cached articles' ) }</span><span className="uxs-card__num">{ s.cached }</span></div>
				<div className="uxs-card"><span className="uxs-card__label">{ t( 'AI bot requests (24 h)' ) }</span><span className="uxs-card__num">{ s.log.last_24h }</span></div>
				<div className="uxs-card"><span className="uxs-card__label">{ t( 'AI bot requests (total)' ) }</span><span className="uxs-card__num">{ s.log.total }</span></div>
			</div>

			<h3 className="uxs-section-title">{ t( 'Addresses for AI' ) }</h3>
			<ul className="uxs-status-list">
				{ rows.map( ( r ) => (
					<li key={ r.kind } className="uxs-status-list__row">
						<strong>{ r.label }</strong>
						{ r.url ? (
							<>
								<a href={ r.url } target="_blank" rel="noreferrer">
									{ r.url } <ExternalLink size={ 12 } aria-hidden="true" />
								</a>
								<button type="button" className="uxs-btn uxs-btn--small" onClick={ () => onPreview( { kind: r.kind, title: r.label } ) }>
									<Eye size={ 14 } aria-hidden="true" /> { t( 'Preview' ) }
								</button>
							</>
						) : (
							<span className="uxs-muted">{ t( 'Switched off' ) }</span>
						) }
					</li>
				) ) }
				<li className="uxs-status-list__row">
					<strong>{ t( 'Article as markdown' ) }</strong>
					{ s.markdown ? (
						<span className="uxs-muted">
							{ s.link_style === 'suffix' ? t( 'Add .md to the end of an article address' ) : t( 'Add ?format=md to an article address' ) }
							{ s.accept ? ' · ' + t( 'or send the header Accept: text/markdown' ) : '' }
						</span>
					) : (
						<span className="uxs-muted">{ t( 'Switched off' ) }</span>
					) }
				</li>
			</ul>
			<p className="uxs-form__help">
				{ t( 'Without URL rewriting, files in the site root are reached as index.php/llms.txt. Enable URL rewriting (rename htaccess.txt to .htaccess) to serve /llms.txt.' ) }
			</p>

			<div className="uxs-actions">
				<button type="button" className="uxs-btn uxs-btn--primary" disabled={ regenerate.isPending } onClick={ () => regenerate.mutate() }>
					<RefreshCw size={ 14 } aria-hidden="true" /> { regenerate.isPending ? t( 'Rebuilding…' ) : t( 'Rebuild everything' ) }
				</button>
				<button type="button" className="uxs-btn" disabled={ clear.isPending } onClick={ () => clear.mutate() }>
					{ t( 'Empty the cache' ) }
				</button>
			</div>

			{ s.log.bots.length > 0 ? (
				<>
					<h3 className="uxs-section-title">{ t( 'Who reads the site' ) }</h3>
					<div className="uxs-table-wrap" style={ { maxWidth: 420 } }>
						<table className="uxs-table">
							<thead>
								<tr>
									<th>{ t( 'AI bot' ) }</th>
									<th>{ t( 'Requests' ) }</th>
								</tr>
							</thead>
							<tbody>
								{ s.log.bots.map( ( b ) => (
									<tr key={ b.bot_name }>
										<td>{ b.bot_name }</td>
										<td>{ b.count }</td>
									</tr>
								) ) }
							</tbody>
						</table>
					</div>
				</>
			) : null }
		</>
	);
}

const PER_PAGE = 20;

function Articles( { onPreview }: { onPreview: ( r: { id: number; title: string } ) => void } ): JSX.Element {
	const [ page, setPage ] = useState( 1 );
	const [ search, setSearch ] = useState( '' );
	const [ term, setTerm ] = useState( '' );
	const list = useQuery( {
		queryKey: [ KEY, 'articles', page, term ],
		queryFn: () => api.get< ArticlePage >( 'ai-markdown/articles', { page, per_page: PER_PAGE, search: term } ),
	} );
	const regenerate = useMutation( {
		mutationFn: ( id: number ) => api.post( `ai-markdown/regenerate/${ id }` ),
		onSuccess: refreshAll,
		onError: ( e: Error ) => pushToast( 'error', e.message ),
	} );

	const columns: Column< ArticleRow >[] = [
		{ key: 'title', header: t( 'Article' ), render: ( r ) => <a href={ r.url } target="_blank" rel="noreferrer">{ r.title }</a> },
		{ key: 'category', header: t( 'Category' ) },
		{
			key: 'generated_at',
			header: t( 'Markdown' ),
			render: ( r ) => ( r.generated_at ? <span title={ formatDate( r.generated_at ) }>{ formatDate( r.generated_at ) }</span> : <span className="uxs-muted">{ t( 'Not built yet' ) }</span> ),
		},
		{
			key: 'actions',
			header: '',
			render: ( r ) => (
				<span className="uxs-actions">
					<button type="button" className="uxs-btn uxs-btn--small" onClick={ () => onPreview( { id: r.id, title: r.title } ) }>
						<Eye size={ 14 } aria-hidden="true" /> { t( 'Preview' ) }
					</button>
					<button type="button" className="uxs-btn uxs-btn--small" disabled={ regenerate.isPending } aria-label={ t( 'Rebuild' ) } onClick={ () => regenerate.mutate( r.id ) }>
						<RefreshCw size={ 14 } aria-hidden="true" />
					</button>
					<a className="uxs-btn uxs-btn--small" href={ r.md_url } target="_blank" rel="noreferrer" aria-label={ t( 'Open the markdown version' ) }>
						<ExternalLink size={ 14 } aria-hidden="true" />
					</a>
				</span>
			),
		},
	];
	const data = list.data;
	const pages = data ? Math.max( 1, Math.ceil( data.total / PER_PAGE ) ) : 1;

	return (
		<>
			<form
				className="uxs-actions uxs-list-toolbar"
				onSubmit={ ( e ) => {
					e.preventDefault();
					setPage( 1 );
					setTerm( search.trim() );
				} }
			>
				<input type="search" className="uxs-input" value={ search } placeholder={ t( 'Search by title' ) } aria-label={ t( 'Search' ) } onChange={ ( e ) => setSearch( e.target.value ) } />
				<button type="submit" className="uxs-btn">{ t( 'Search' ) }</button>
			</form>
			{ list.isLoading ? <Loading /> : null }
			{ list.error ? <p role="alert">{ list.error.message }</p> : null }
			{ data ? (
				<>
					<p className="uxs-muted uxs-list-toolbar__count">{ tn( '{n} public article', '{n} public articles', data.total ) }</p>
					<DataTable columns={ columns } rows={ data.items } rowKey={ ( r ) => r.id } page={ page } pages={ pages } onPage={ setPage } empty={ t( 'No public articles.' ) } />
				</>
			) : null }
		</>
	);
}

const LOG_PER_PAGE = 30;

function BotLog(): JSX.Element {
	const [ page, setPage ] = useState( 1 );
	const [ search, setSearch ] = useState( '' );
	const [ term, setTerm ] = useState( '' );
	const [ bot, setBot ] = useState( '' );
	const stats = useQuery( { queryKey: [ KEY, 'log-stats' ], queryFn: () => api.get< Status[ 'log' ] >( 'ai-markdown/log/stats' ) } );
	const list = useQuery( {
		queryKey: [ KEY, 'log', page, term, bot ],
		queryFn: () => api.get< LogPage >( 'ai-markdown/log', { page, per_page: LOG_PER_PAGE, search: term, bot } ),
	} );
	const remove = useMutation( {
		mutationFn: ( id: number ) => api.post( 'ai-markdown/log/delete', { ids: [ id ] } ),
		onSuccess: refreshAll,
		onError: ( e: Error ) => pushToast( 'error', e.message ),
	} );
	const clear = useMutation( {
		mutationFn: () => api.post( 'ai-markdown/log/clear' ),
		onSuccess: () => {
			setPage( 1 );
			refreshAll();
		},
		onError: ( e: Error ) => pushToast( 'error', e.message ),
	} );

	const columns: Column< LogRow >[] = [
		{ key: 'created', header: t( 'Date' ), render: ( r ) => formatDate( r.created ) },
		{ key: 'bot_name', header: t( 'AI bot' ), render: ( r ) => <span className="uxs-badge" title={ r.user_agent }>{ r.bot_name }</span> },
		{ key: 'kind', header: t( 'Document' ), render: ( r ) => kindLabel( r.kind ) },
		{
			key: 'url',
			header: t( 'Address' ),
			render: ( r ) => <a href={ r.url } target="_blank" rel="noreferrer" style={ { wordBreak: 'break-all' } }>{ r.url.length > 70 ? r.url.slice( 0, 70 ) + '…' : r.url }</a>,
		},
		{ key: 'via', header: t( 'Requested by' ), render: ( r ) => viaLabel( r.via ) },
		{ key: 'ip_address', header: t( 'IP address' ), render: ( r ) => <code>{ r.ip_address || '—' }</code> },
		{
			key: 'actions',
			header: '',
			render: ( r ) => (
				<button type="button" className="uxs-btn uxs-btn--small" aria-label={ t( 'Delete entry' ) } onClick={ () => remove.mutate( r.id ) }>
					<Trash2 size={ 14 } aria-hidden="true" />
				</button>
			),
		},
	];
	const data = list.data;
	const pages = data ? Math.max( 1, Math.ceil( data.total / LOG_PER_PAGE ) ) : 1;

	return (
		<>
			<form
				className="uxs-actions uxs-list-toolbar"
				onSubmit={ ( e ) => {
					e.preventDefault();
					setPage( 1 );
					setTerm( search.trim() );
				} }
			>
				<input type="search" className="uxs-input" value={ search } placeholder={ t( 'Search bot, address, IP' ) } aria-label={ t( 'Search' ) } onChange={ ( e ) => setSearch( e.target.value ) } />
				<select className="uxs-input" value={ bot } aria-label={ t( 'AI bot' ) } onChange={ ( e ) => { setPage( 1 ); setBot( e.target.value ); } }>
					<option value="">{ t( 'All bots' ) }</option>
					{ ( stats.data?.bots ?? [] ).map( ( b ) => (
						<option key={ b.bot_name } value={ b.bot_name }>{ b.bot_name } ({ b.count })</option>
					) ) }
				</select>
				<button type="submit" className="uxs-btn">{ t( 'Search' ) }</button>
				<button
					type="button"
					className="uxs-btn uxs-btn--danger"
					disabled={ clear.isPending || ! data || data.total === 0 }
					onClick={ () => void confirm( { message: t( 'Delete the whole bot log? This cannot be undone.' ), danger: true, confirmLabel: t( 'Delete all' ) } ).then( ( ok ) => ok && clear.mutate() ) }
				>
					<Trash2 size={ 14 } aria-hidden="true" /> { t( 'Clear all' ) }
				</button>
			</form>
			{ list.isLoading ? <Loading /> : null }
			{ list.error ? <p role="alert">{ list.error.message }</p> : null }
			{ data ? (
				<DataTable columns={ columns } rows={ data.items } rowKey={ ( r ) => r.id } page={ page } pages={ pages } onPage={ setPage } empty={ t( 'No AI bot has requested the markdown addresses yet.' ) } />
			) : null }
		</>
	);
}

function RobotsTab(): JSX.Element {
	const [ policy, setPolicy ] = useState( 'allow_all' );
	const robots = useQuery( { queryKey: [ KEY, 'robots', policy ], queryFn: () => api.get< Robots >( 'ai-markdown/robots', { policy } ) } );
	const d = robots.data;
	const copy = (): void => {
		if ( ! d ) {
			return;
		}
		void navigator.clipboard.writeText( d.snippet ).then(
			() => pushToast( 'success', t( 'Copied.' ) ),
			() => pushToast( 'error', t( 'Copying failed. Select the text and copy it by hand.' ) )
		);
	};
	const badge = ( b: RobotBot ): JSX.Element => {
		if ( b.status === 'blocked' ) {
			return <span className="uxs-badge is-danger">{ t( 'Blocked' ) }</span>;
		}
		return <span className="uxs-badge is-success">{ b.source === 'none' ? t( 'Allowed (no rule)' ) : t( 'Allowed' ) }</span>;
	};
	const columns: Column< RobotBot >[] = [
		{ key: 'name', header: t( 'AI bot' ), render: ( b ) => <><strong>{ b.name }</strong> <code>{ b.token }</code></> },
		{ key: 'purpose', header: t( 'Purpose' ), render: ( b ) => ( b.purpose === 'training' ? t( 'Model training' ) : t( 'Search and answers' ) ) },
		{ key: 'status', header: t( 'Your robots.txt' ), render: badge },
	];

	return (
		<>
			<p className="uxs-form__help">
				{ t( 'robots.txt tells crawlers what they may read. UX Studio never edits that file; here you see what it says today and can copy a suggestion into it.' ) }
			</p>
			{ robots.isLoading ? <Loading /> : null }
			{ robots.error ? <p role="alert">{ robots.error.message }</p> : null }
			{ d ? (
				<>
					{ ! d.exists ? <p className="uxs-muted">{ t( 'This site has no robots.txt file, so every crawler is allowed.' ) }</p> : null }
					<DataTable columns={ columns } rows={ d.bots } rowKey={ ( b ) => b.token } />
					<h3 className="uxs-section-title">{ t( 'Suggested rules' ) }</h3>
					<div className="uxs-actions">
						<select className="uxs-input" value={ policy } aria-label={ t( 'Policy' ) } onChange={ ( e ) => setPolicy( e.target.value ) }>
							<option value="allow_all">{ t( 'Allow all AI crawlers' ) }</option>
							<option value="block_training">{ t( 'Block model training, allow AI search' ) }</option>
							<option value="block_all">{ t( 'Block all AI crawlers' ) }</option>
						</select>
						<button type="button" className="uxs-btn" onClick={ copy }>
							<Copy size={ 14 } aria-hidden="true" /> { t( 'Copy' ) }
						</button>
					</div>
					<pre className="uxs-code-editor" style={ { whiteSpace: 'pre-wrap', maxHeight: 320, overflow: 'auto' } }>{ d.snippet }</pre>
					<p className="uxs-form__help">{ t( 'Paste the rules into the robots.txt file in the site root. A bot that is blocked there cannot read llms.txt or the markdown pages either.' ) }</p>
				</>
			) : null }
		</>
	);
}

export default function Page( { module }: { module: ModuleInfo } ): JSX.Element {
	const [ tab, setTab ] = useState( 'overview' );
	const [ preview, setPreview ] = useState< { kind?: string; id?: number; title: string } | null >( null );

	return (
		<>
			<Tabs
				label={ module.name }
				active={ tab }
				onChange={ setTab }
				items={ [
					{ id: 'overview', label: t( 'Overview' ) },
					{ id: 'articles', label: t( 'Articles' ) },
					{ id: 'log', label: t( 'Bot log' ) },
					{ id: 'robots', label: t( 'robots.txt' ) },
					{ id: 'settings', label: t( 'Settings' ) },
				] }
			/>
			<TabPanel id={ tab }>
				{ tab === 'overview' ? <Overview onPreview={ setPreview } /> : null }
				{ tab === 'articles' ? <Articles onPreview={ setPreview } /> : null }
				{ tab === 'log' ? <BotLog /> : null }
				{ tab === 'robots' ? <RobotsTab /> : null }
				{ tab === 'settings' ? <SettingsPanel moduleId={ module.id } /> : null }
			</TabPanel>
			<PreviewModal request={ preview } onClose={ () => setPreview( null ) } />
		</>
	);
}
