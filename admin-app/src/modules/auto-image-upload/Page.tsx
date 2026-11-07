/**
 * Auto image upload: bulk tool for the existing articles (scan, then download), the list of downloaded and failed
 * addresses, and the settings.
 */
import { useRef, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Play, ScanSearch, Square } from 'lucide-react';
import { api, queryClient, type ModuleInfo } from '../../app/api';
import { createT, formatDate } from '../../app/i18n';
import { SettingsPanel } from '../../app/SettingsForm';
import { DataTable, Loading, TabPanel, Tabs, pushToast, type Column } from '../../app/ui';

interface Overview {
	counts: { ok: number; failed: number };
	contexts: string[];
	optimizer: boolean;
	curl: boolean;
	max_per_save: number;
}

interface BulkResult {
	scanned: number;
	articles: number;
	found: number;
	replaced: number;
	failed: number;
	locked: number;
	next_after_id: number;
	done: boolean;
	errors: string[];
}

interface LogRow extends Record< string, unknown > {
	id: string;
	url: string;
	status: 'ok' | 'failed';
	path: string;
	error: string;
	context: string;
	updated: string;
}

const { t } = createT( 'auto-image-upload' );

function errorText( e: unknown ): string {
	return e instanceof Error ? e.message : String( e );
}

/** Translated label of an internal kind (articles, categories, modules). */
function kindLabel( kind: string ): string {
	const map: Record< string, string > = { articles: t( 'Articles' ), categories: t( 'Categories' ), modules: t( 'Custom HTML modules' ) };
	return map[ kind ] ?? kind;
}

/** Translated label of the save context stored in the log. */
function contextLabel( context: string ): string {
	if ( context === 'bulk' ) {
		return t( 'Bulk tool' );
	}
	if ( context.startsWith( 'com_content.' ) ) {
		return kindLabel( 'articles' );
	}
	if ( context.startsWith( 'com_categories.' ) ) {
		return kindLabel( 'categories' );
	}
	if ( context.startsWith( 'com_modules.' ) ) {
		return kindLabel( 'modules' );
	}
	return context;
}

function ToolsTab(): JSX.Element {
	const overview = useQuery( { queryKey: [ 'auto-image-upload', 'overview' ], queryFn: () => api.get< Overview >( 'auto-image-upload/overview' ) } );
	const [ running, setRunning ] = useState< 'scan' | 'run' | null >( null );
	const [ total, setTotal ] = useState< { scanned: number; articles: number; found: number; replaced: number; failed: number } | null >( null );
	const [ errors, setErrors ] = useState< string[] >( [] );
	const stop = useRef( false );

	const go = async ( dryRun: boolean ): Promise< void > => {
		stop.current = false;
		setRunning( dryRun ? 'scan' : 'run' );
		setErrors( [] );
		const sum = { scanned: 0, articles: 0, found: 0, replaced: 0, failed: 0 };
		setTotal( sum );
		let after = 0;
		const errs: string[] = [];
		let locked = 0;
		try {
			for ( ;; ) {
				const r = await api.post< BulkResult >( 'auto-image-upload/bulk/run', { after_id: after, dry_run: dryRun } );
				sum.scanned += r.scanned;
				sum.articles += r.articles;
				sum.found += r.found;
				sum.replaced += r.replaced;
				sum.failed += r.failed;
				locked += r.locked;
				errs.push( ...r.errors );
				setTotal( { ...sum } );
				setErrors( errs.slice( 0, 20 ) );
				after = r.next_after_id;
				if ( r.done || stop.current ) {
					break;
				}
			}
			pushToast( 'success', dryRun ? t( 'Scan finished.' ) : t( 'Done: {n} images downloaded.', { n: sum.replaced } ) );
			if ( locked > 0 ) {
				pushToast( 'error', t( '{n} articles are open in an editor and were skipped.', { n: locked } ) );
			}
		} catch ( e ) {
			pushToast( 'error', errorText( e ) );
		} finally {
			setRunning( null );
			void queryClient.invalidateQueries( { queryKey: [ 'auto-image-upload' ] } );
		}
	};

	if ( overview.isLoading ) {
		return <Loading />;
	}
	const o = overview.data;
	if ( ! o ) {
		return <p role="alert">{ errorText( overview.error ) }</p>;
	}

	return (
		<>
			{ ! o.curl ? (
				<div className="uxs-notice is-error" role="alert">
					{ t( 'The PHP cURL extension is missing, images cannot be downloaded.' ) }
				</div>
			) : null }
			<div className="uxs-cards">
				<div className="uxs-card">
					<span className="uxs-card__label">{ t( 'Downloaded images' ) }</span>
					<span className="uxs-card__num">{ o.counts.ok }</span>
				</div>
				<div className="uxs-card">
					<span className="uxs-card__label">{ t( 'Failed addresses' ) }</span>
					<span className="uxs-card__num">{ o.counts.failed }</span>
				</div>
			</div>

			<p>{ t( 'Images are downloaded automatically when an article is saved. The tool below goes through the articles that already exist.' ) }</p>
			<div className="uxs-actions" style={ { margin: 'var(--uxs-sp-4) 0' } }>
				{ running ? (
					<button type="button" className="uxs-btn" onClick={ () => ( stop.current = true ) }>
						<Square size={ 14 } aria-hidden="true" /> { t( 'Stop' ) }
					</button>
				) : (
					<>
						<button type="button" className="uxs-btn" onClick={ () => void go( true ) }>
							<ScanSearch size={ 14 } aria-hidden="true" /> { t( 'Scan articles' ) }
						</button>
						<button type="button" className="uxs-btn uxs-btn--primary" onClick={ () => void go( false ) } disabled={ ! o.curl }>
							<Play size={ 14 } aria-hidden="true" /> { t( 'Download images of existing articles' ) }
						</button>
					</>
				) }
				{ running ? <span className="uxs-muted">{ running === 'scan' ? t( 'Scanning...' ) : t( 'Downloading...' ) }</span> : null }
			</div>

			{ total ? (
				<p className="uxs-muted">
					{ t( 'Articles checked: {scanned}, with external images: {articles}, external images found: {found}, downloaded: {replaced}, failed: {failed}.', total ) }
				</p>
			) : null }
			{ errors.length > 0 ? (
				<ul className="uxs-muted">
					{ errors.map( ( e ) => (
						<li key={ e }>{ e }</li>
					) ) }
				</ul>
			) : null }

			<ul className="uxs-muted">
				<li>{ t( 'Processed on save: {kinds}', { kinds: o.contexts.map( kindLabel ).join( ', ' ) || '-' } ) }</li>
				<li>{ t( 'At most {n} images are downloaded per save.', { n: o.max_per_save } ) }</li>
				<li>{ o.optimizer ? t( 'Downloaded images are passed to the Image optimizer.' ) : t( 'Turn on the Image optimizer to compress and resize downloaded images.' ) }</li>
				<li>{ t( 'Only JPEG, PNG, GIF, WebP and AVIF images from public addresses are accepted; SVG files are refused.' ) }</li>
			</ul>
		</>
	);
}

function LogTab(): JSX.Element {
	const [ page, setPage ] = useState( 1 );
	const [ status, setStatus ] = useState( '' );
	const per = 25;
	const q = useQuery( {
		queryKey: [ 'auto-image-upload', 'log', page, status ],
		queryFn: () => api.get< { items: LogRow[]; total: number } >( 'auto-image-upload/log', { page, status } ),
	} );
	const columns: Column< LogRow >[] = [
		{ key: 'url', header: t( 'Address' ), render: ( r ) => <code style={ { wordBreak: 'break-all' } }>{ r.url.length > 90 ? r.url.slice( 0, 90 ) + '...' : r.url }</code> },
		{ key: 'status', header: t( 'Result' ), render: ( r ) => <span className={ `uxs-badge ${ r.status === 'ok' ? 'is-success' : 'is-danger' }` }>{ r.status === 'ok' ? t( 'Downloaded' ) : t( 'Failed' ) }</span> },
		{ key: 'path', header: t( 'Saved as' ), render: ( r ) => ( r.status === 'ok' ? <code>{ r.path }</code> : r.error ) },
		{ key: 'context', header: t( 'From' ), render: ( r ) => contextLabel( r.context ) },
		{ key: 'updated', header: t( 'Time' ), render: ( r ) => formatDate( r.updated ) },
	];
	const total = q.data?.total ?? 0;
	return (
		<>
			<p>
				<select
					value={ status }
					aria-label={ t( 'Result' ) }
					onChange={ ( e ) => {
						setStatus( e.target.value );
						setPage( 1 );
					} }
				>
					<option value="">{ t( 'All results' ) }</option>
					<option value="ok">{ t( 'Downloaded' ) }</option>
					<option value="failed">{ t( 'Failed' ) }</option>
				</select>
			</p>
			{ q.isLoading ? <Loading /> : null }
			{ q.error ? <p role="alert">{ errorText( q.error ) }</p> : null }
			<DataTable columns={ columns } rows={ q.data?.items ?? [] } rowKey={ ( r ) => r.id } empty={ t( 'Nothing has been downloaded yet.' ) } page={ page } pages={ Math.max( 1, Math.ceil( total / per ) ) } onPage={ setPage } />
			<p className="uxs-muted">{ t( 'A failed address is tried again after six hours.' ) }</p>
		</>
	);
}

export default function Page( { module }: { module: ModuleInfo } ): JSX.Element {
	const [ tab, setTab ] = useState( 'tools' );
	return (
		<>
			<Tabs
				label={ module.name }
				active={ tab }
				onChange={ setTab }
				items={ [
					{ id: 'tools', label: t( 'Overview' ) },
					{ id: 'log', label: t( 'History' ) },
					{ id: 'settings', label: t( 'Settings' ) },
				] }
			/>
			<TabPanel id={ tab }>
				{ tab === 'tools' ? <ToolsTab /> : null }
				{ tab === 'log' ? <LogTab /> : null }
				{ tab === 'settings' ? <SettingsPanel moduleId={ module.id } /> : null }
			</TabPanel>
		</>
	);
}
