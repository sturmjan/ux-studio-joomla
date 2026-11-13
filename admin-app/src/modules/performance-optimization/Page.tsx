/**
 * Performance optimization: overview (what Joomla already does, browser cache headers in .htaccess, a check of the
 * home page with recommendations) and the settings of the front-end optimizations.
 */
import { useState } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import { Play, RefreshCw, Trash2 } from 'lucide-react';
import { api, queryClient, type ModuleInfo } from '../../app/api';
import { createT } from '../../app/i18n';
import { SettingsPanel } from '../../app/SettingsForm';
import { Loading, TabPanel, Tabs, confirm, pushToast } from '../../app/ui';

interface Builtin {
	joomla_cache: number;
	gzip: boolean;
	page_cache: boolean;
	debug: boolean;
	opcache: boolean;
	php: string;
}

interface Htaccess {
	exists: boolean;
	writable: boolean;
	active: boolean;
	ttl: string;
	backups: string[];
	apache: boolean;
	server: string;
}

interface Status {
	builtin: Builtin;
	htaccess: Htaccess;
	features: Record< string, boolean | number >;
	ttl: string;
}

interface PageAudit {
	url: string;
	status: number;
	time_ms: number;
	html_bytes: number;
	encoding: string;
	cache_control: string;
	images: number;
	images_lazy: number;
	iframes: number;
	iframes_lazy: number;
	scripts: number;
	scripts_blocking: number;
	styles: number;
	inline_js_bytes: number;
	comments: number;
	asset: { url: string; status: number; cache_control: string; expires: string } | null;
}

const { t } = createT( 'performance-optimization' );

function errorText( e: unknown ): string {
	return e instanceof Error ? e.message : String( e );
}

function kb( n: number ): string {
	return ( n / 1024 ).toFixed( 1 ) + ' kB';
}

function Badge( { ok, yes, no, warnIfNo }: { ok: boolean; yes: string; no: string; warnIfNo?: boolean } ): JSX.Element {
	return <span className={ `uxs-badge ${ ok ? 'is-success' : warnIfNo ? 'is-warning' : 'is-info' }` }>{ ok ? yes : no }</span>;
}

function ttlLabel( ttl: string ): string {
	return { week: t( 'One week' ), month: t( 'One month' ), halfyear: t( 'Half a year' ), year: t( 'One year' ) }[ ttl ] ?? ( ttl || '-' );
}

function refresh(): void {
	void queryClient.invalidateQueries( { queryKey: [ 'performance-optimization' ] } );
}

/** Turns what the check found into plain-language advice. */
function recommendations( audit: PageAudit, status: Status ): string[]  {
	const out: string[] = [];
	const f = status.features;
	if ( ! audit.encoding && ! status.builtin.gzip ) {
		out.push( t( 'The page is sent uncompressed. Turn on GZIP Page Compression in System - Global Configuration - Server (or compression on the web server).' ) );
	}
	if ( ! status.builtin.page_cache ) {
		out.push( t( 'Enable the plugin "System - Page Cache" (and Cache in Global Configuration) so anonymous visitors get a stored copy of each page.' ) );
	}
	if ( status.builtin.debug ) {
		out.push( t( 'Debug mode is on. Turn it off on the live site, it slows every request down.' ) );
	}
	if ( audit.images > 2 && audit.images_lazy < audit.images - 1 && ! f.lazy_images ) {
		out.push( t( 'Images are not lazy-loaded. Turn on "Lazy-load images" in the settings.' ) );
	}
	if ( audit.iframes > 0 && audit.iframes_lazy < audit.iframes && ! f.lazy_iframes ) {
		out.push( t( 'Embedded frames are not lazy-loaded. Turn on "Lazy-load iframes" in the settings.' ) );
	}
	if ( audit.scripts_blocking > 0 && ! f.defer_scripts ) {
		out.push( t( '{n} scripts block the page while loading. "Defer external scripts" can fix this (test the site afterwards).', { n: audit.scripts_blocking } ) );
	}
	if ( audit.asset && ! /max-age=\d{5,}/.test( audit.asset.cache_control ) && ! audit.asset.expires && ! status.htaccess.active ) {
		out.push( t( 'Static files are sent without a long cache time. Add the cache headers to .htaccess below.' ) );
	}
	if ( audit.html_bytes > 150 * 1024 ) {
		out.push( t( 'The HTML of the page is large ({size}). Look for repeated markup or inline data.', { size: kb( audit.html_bytes ) } ) );
	}
	if ( audit.inline_js_bytes > 50 * 1024 ) {
		out.push( t( 'The page carries {size} of inline JavaScript.', { size: kb( audit.inline_js_bytes ) } ) );
	}
	if ( audit.comments > 5 && ! f.strip_comments ) {
		out.push( t( 'The HTML contains {n} comments. "Remove HTML comments" saves them.', { n: audit.comments } ) );
	}
	return out;
}

function OverviewTab(): JSX.Element {
	const status = useQuery( { queryKey: [ 'performance-optimization', 'status' ], queryFn: () => api.get< Status >( 'performance-optimization/status' ), refetchOnMount: 'always' } );
	const audit = useMutation( { mutationFn: () => api.post< PageAudit >( 'performance-optimization/audit', {} ), onError: ( e ) => pushToast( 'error', errorText( e ) ) } );
	const apply = useMutation( {
		mutationFn: () => api.post< { backup: string } >( 'performance-optimization/htaccess/apply', { confirm: true } ),
		onSuccess: ( r ) => {
			pushToast( 'success', r.backup ? t( 'Cache headers added. The original file was saved as {name}.', { name: r.backup } ) : t( 'Cache headers added.' ) );
			refresh();
		},
		onError: ( e ) => pushToast( 'error', errorText( e ) ),
	} );
	const remove = useMutation( {
		mutationFn: () => api.post( 'performance-optimization/htaccess/remove', {} ),
		onSuccess: () => {
			pushToast( 'success', t( 'Cache headers removed.' ) );
			refresh();
		},
		onError: ( e ) => pushToast( 'error', errorText( e ) ),
	} );

	if ( status.isLoading ) {
		return <Loading />;
	}
	const s = status.data;
	if ( ! s ) {
		return <p role="alert">{ errorText( status.error ) }</p>;
	}
	const b = s.builtin;
	const h = s.htaccess;
	const a = audit.data;
	const tips = a ? recommendations( a, s ) : [];
	const cacheLabel = [ t( 'Off' ), t( 'Conservative' ), t( 'Progressive' ) ][ b.joomla_cache ] ?? t( 'Off' );

	return (
		<>
			<h2 className="uxs-form__section">{ t( 'Already built into Joomla' ) }</h2>
			<p className="uxs-muted">{ t( 'These speed-ups belong to Joomla itself, so UX1 Studio does not copy them. Turn them on in the places named here.' ) }</p>
			<div className="uxs-table-wrap"><table className="uxs-table">
				<tbody>
					<tr>
						<td>{ t( 'Page cache (plugin "System - Page Cache")' ) }</td>
						<td><Badge ok={ b.page_cache } yes={ t( 'On' ) } no={ t( 'Off' ) } warnIfNo /></td>
						<td className="uxs-muted">{ t( 'System - Plugins' ) }</td>
					</tr>
					<tr>
						<td>{ t( 'Cache in Global Configuration' ) }</td>
						<td><Badge ok={ b.joomla_cache > 0 } yes={ cacheLabel } no={ cacheLabel } warnIfNo /></td>
						<td className="uxs-muted">{ t( 'System - Global Configuration - System' ) }</td>
					</tr>
					<tr>
						<td>{ t( 'GZIP page compression' ) }</td>
						<td><Badge ok={ b.gzip } yes={ t( 'On' ) } no={ t( 'Off' ) } /></td>
						<td className="uxs-muted">{ t( 'System - Global Configuration - Server (the web server may compress anyway)' ) }</td>
					</tr>
					<tr>
						<td>{ t( 'PHP OPcache' ) }</td>
						<td><Badge ok={ b.opcache } yes={ t( 'On' ) } no={ t( 'Off' ) } warnIfNo /></td>
						<td className="uxs-muted">PHP { b.php }</td>
					</tr>
					<tr>
						<td>{ t( 'Debug mode' ) }</td>
						<td><span className={ `uxs-badge ${ b.debug ? 'is-warning' : 'is-success' }` }>{ b.debug ? t( 'On' ) : t( 'Off' ) }</span></td>
						<td className="uxs-muted">{ t( 'Should be off on a live site' ) }</td>
					</tr>
				</tbody>
			</table></div>

			<h2 className="uxs-form__section" style={ { marginTop: 'var(--uxs-sp-6)' } }>{ t( 'Browser cache for static files' ) }</h2>
			<p className="uxs-muted">
				{ t( 'Adds a marked block to the .htaccess file so browsers keep images, fonts, CSS and JS and do not download them on every visit. Nothing outside the block is changed, a copy of the original file is saved first and the block is removed again when you turn the module off.' ) }
			</p>
			{ ! h.apache ? <div className="uxs-notice" role="alert">{ t( 'This server ({server}) does not read .htaccess. Add the cache headers to the server configuration instead.', { server: h.server } ) }</div> : null }
			<div className="uxs-actions" style={ { margin: 'var(--uxs-sp-3) 0' } }>
				<Badge ok={ h.active } yes={ t( 'Active' ) } no={ t( 'Not added' ) } />
				{ h.active ? <span className="uxs-muted">{ t( 'Cache time' ) }: { ttlLabel( h.ttl ) }</span> : null }
				<button
					type="button"
					className="uxs-btn uxs-btn--primary"
					disabled={ apply.isPending || ! h.apache || ! h.writable }
					onClick={ async () => {
						if ( await confirm( { message: t( 'Add the cache headers to the .htaccess file of the site? A copy of the current file is saved first.' ), confirmLabel: t( 'Add to .htaccess' ) } ) ) {
							apply.mutate();
						}
					} }
				>
					{ h.active ? t( 'Update' ) : t( 'Add to .htaccess' ) }
				</button>
				{ h.active ? (
					<button
						type="button"
						className="uxs-btn uxs-btn--danger"
						disabled={ remove.isPending }
						onClick={ async () => {
							if ( await confirm( { message: t( 'Remove the cache headers block from the .htaccess file? A copy of the current file is saved first.' ), confirmLabel: t( 'Remove' ) } ) ) {
								remove.mutate();
							}
						} }
					>
						<Trash2 size={ 14 } aria-hidden="true" /> { t( 'Remove' ) }
					</button>
				) : null }
			</div>
			{ ! h.writable ? <p className="uxs-muted">{ t( 'The .htaccess file (or the site folder) is not writable by PHP.' ) }</p> : null }
			{ h.backups.length > 0 ? <p className="uxs-muted">{ t( 'Backups in administrator/logs/uxs-performance: {list}', { list: h.backups.join( ', ' ) } ) }</p> : null }

			<h2 className="uxs-form__section" style={ { marginTop: 'var(--uxs-sp-6)' } }>{ t( 'Check the home page' ) }</h2>
			<div className="uxs-actions" style={ { marginBottom: 'var(--uxs-sp-4)' } }>
				<button type="button" className="uxs-btn" disabled={ audit.isPending } onClick={ () => audit.mutate() }>
					{ audit.isPending ? <RefreshCw size={ 14 } aria-hidden="true" /> : <Play size={ 14 } aria-hidden="true" /> } { t( 'Run the check' ) }
				</button>
			</div>
			{ a ? (
				<>
					<div className="uxs-cards">
						<div className="uxs-card"><span className="uxs-card__label">{ t( 'Server response' ) }</span><span className="uxs-card__num">{ a.time_ms } ms</span></div>
						<div className="uxs-card"><span className="uxs-card__label">{ t( 'HTML size' ) }</span><span className="uxs-card__num">{ kb( a.html_bytes ) }</span></div>
						<div className="uxs-card"><span className="uxs-card__label">{ t( 'Compression' ) }</span><span className="uxs-card__num">{ a.encoding || t( 'none' ) }</span></div>
						<div className="uxs-card"><span className="uxs-card__label">{ t( 'Blocking scripts' ) }</span><span className="uxs-card__num">{ a.scripts_blocking } / { a.scripts }</span></div>
						<div className="uxs-card"><span className="uxs-card__label">{ t( 'Lazy images' ) }</span><span className="uxs-card__num">{ a.images_lazy } / { a.images }</span></div>
						<div className="uxs-card"><span className="uxs-card__label">{ t( 'Stylesheets' ) }</span><span className="uxs-card__num">{ a.styles }</span></div>
					</div>
					{ tips.length > 0 ? (
						<>
							<h3>{ t( 'Recommendations' ) }</h3>
							<ul>{ tips.map( ( tip ) => <li key={ tip }>{ tip }</li> ) }</ul>
						</>
					) : (
						<p className="uxs-muted">{ t( 'Nothing to recommend, the page looks well optimized.' ) }</p>
					) }
					<p className="uxs-muted">{ t( 'The check loads the home page once as an anonymous visitor ({url}).', { url: a.url } ) }</p>
				</>
			) : null }
		</>
	);
}

export default function Page( { module }: { module: ModuleInfo } ): JSX.Element {
	const [ tab, setTab ] = useState( 'overview' );
	return (
		<>
			<Tabs
				label={ module.name }
				active={ tab }
				onChange={ setTab }
				items={ [
					{ id: 'overview', label: t( 'Overview' ) },
					{ id: 'settings', label: t( 'Settings' ) },
				] }
			/>
			<TabPanel id={ tab }>
				{ tab === 'overview' ? <OverviewTab /> : null }
				{ tab === 'settings' ? <SettingsPanel moduleId={ module.id } /> : null }
			</TabPanel>
		</>
	);
}
