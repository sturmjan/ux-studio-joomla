/**
 * Image optimizer: overview with the batch run over the images folder, the list of processed files (restore /
 * optimize again) and the settings.
 */
import { useRef, useState } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import { Play, RefreshCw, Square, Undo2, Wand2 } from 'lucide-react';
import { api, queryClient, type ModuleInfo } from '../../app/api';
import { createT, formatDate } from '../../app/i18n';
import { SettingsPanel } from '../../app/SettingsForm';
import { DataTable, Loading, TabPanel, Tabs, confirm, pushToast, type Column } from '../../app/ui';

interface Totals {
	files: number;
	optimized: number;
	original: number;
	current: number;
	saved: number;
	webp: number;
	avif: number;
	backups: number;
	webp_files: number;
	avif_files: number;
}

interface Overview {
	totals: Totals;
	capabilities: { gd: boolean; imagick: boolean; library: string; formats: { webp: boolean; avif: boolean } };
	delivery: { enabled: boolean; active: boolean };
	bulk_enabled: boolean;
	auto: boolean;
}

interface ScanResult {
	total: number;
	pending: number;
	pending_bytes: number;
	truncated: boolean;
}

interface BatchResult {
	processed: number;
	optimized: number;
	skipped: number;
	failed: number;
	saved: number;
	done: boolean;
	errors: string[];
}

interface Row extends Record< string, unknown > {
	id: string;
	path: string;
	status: 'optimized' | 'skipped' | 'failed' | 'restored';
	original_size: string;
	file_size: string;
	webp_size: string;
	avif_size: string;
	width: string;
	height: string;
	backup: string;
	source: string;
	note: string;
	updated: string;
}

const { t } = createT( 'image-optimizer' );

const STATUS_BADGE: Record< Row[ 'status' ], string > = { optimized: 'is-success', skipped: 'is-info', failed: 'is-danger', restored: 'is-warning' };

function errorText( e: unknown ): string {
	return e instanceof Error ? e.message : String( e );
}

function bytes( n: number ): string {
	const units = [ 'B', 'kB', 'MB', 'GB' ];
	let v = n;
	let i = 0;
	while ( v >= 1024 && i < units.length - 1 ) {
		v /= 1024;
		i++;
	}
	return ( i === 0 ? String( Math.round( v ) ) : v.toFixed( 1 ) ) + ' ' + units[ i ];
}

function statusLabel( s: Row[ 'status' ] ): string {
	return { optimized: t( 'Optimized' ), skipped: t( 'Left as it was' ), failed: t( 'Failed' ), restored: t( 'Restored' ) }[ s ];
}

function noteLabel( note: string ): string {
	const map: Record< string, string > = {
		not_smaller: t( 'Already well compressed' ),
		decode_failed: t( 'The image could not be read' ),
		type_mismatch: t( 'The file content does not match its extension' ),
		encode_failed: t( 'The image could not be encoded' ),
		too_large: t( 'Too large for the PHP memory limit' ),
		animated: t( 'Animated image' ),
	};
	return map[ note ] ?? note;
}

function refresh(): void {
	void queryClient.invalidateQueries( { queryKey: [ 'image-optimizer' ] } );
}

function OverviewTab(): JSX.Element {
	const overview = useQuery( { queryKey: [ 'image-optimizer', 'overview' ], queryFn: () => api.get< Overview >( 'image-optimizer/overview' ) } );
	const scan = useQuery( { queryKey: [ 'image-optimizer', 'scan' ], queryFn: () => api.get< ScanResult >( 'image-optimizer/scan' ) } );
	const [ running, setRunning ] = useState( false );
	const [ progress, setProgress ] = useState< { processed: number; optimized: number; saved: number; failed: number } | null >( null );
	const stop = useRef( false );

	const run = async (): Promise< void > => {
		stop.current = false;
		setRunning( true );
		const total = { processed: 0, optimized: 0, saved: 0, failed: 0 };
		setProgress( total );
		try {
			for ( ;; ) {
				const r = await api.post< BatchResult >( 'image-optimizer/bulk/run', { limit: 10 } );
				total.processed += r.processed;
					total.optimized += r.optimized;
				total.saved += r.saved;
				total.failed += r.failed;
				setProgress( { ...total } );
				// A batch that handled nothing means no progress: never loop on the same files.
					if ( r.done || stop.current || r.processed === 0 ) {
					break;
				}
			}
			pushToast( 'success', t( 'Optimized {n} images, saved {size}.', { n: total.optimized, size: bytes( total.saved ) } ) );
		} catch ( e ) {
			pushToast( 'error', errorText( e ) );
		} finally {
			setRunning( false );
			refresh();
		}
	};

	if ( overview.isLoading ) {
		return <Loading />;
	}
	const o = overview.data;
	if ( ! o ) {
		return <p role="alert">{ errorText( overview.error ) }</p>;
	}
	const s = scan.data;
	const ratio = o.totals.original > 0 ? Math.round( ( o.totals.saved / o.totals.original ) * 100 ) : 0;

	return (
		<>
			{ o.capabilities.library === 'none' ? (
				<div className="uxs-notice is-error" role="alert">
					{ t( 'Neither the GD nor the Imagick PHP extension is available, so no image can be processed.' ) }
				</div>
			) : null }

			<div className="uxs-cards">
				<div className="uxs-card">
					<span className="uxs-card__label">{ t( 'Space saved' ) }</span>
					<span className="uxs-card__num">{ bytes( o.totals.saved ) }{ ratio > 0 ? ' (' + ratio + ' %)' : '' }</span>
				</div>
				<div className="uxs-card">
					<span className="uxs-card__label">{ t( 'Optimized images' ) }</span>
					<span className="uxs-card__num">{ o.totals.optimized }</span>
				</div>
				<div className="uxs-card">
					<span className="uxs-card__label">{ t( 'Still to do' ) }</span>
					<span className="uxs-card__num">{ s ? s.pending + ( s.truncated ? '+' : '' ) : '...' }</span>
				</div>
				<div className="uxs-card">
					<span className="uxs-card__label">{ t( 'WebP / AVIF files' ) }</span>
					<span className="uxs-card__num">{ o.totals.webp_files } / { o.totals.avif_files }</span>
				</div>
			</div>

			<div className="uxs-actions" style={ { margin: 'var(--uxs-sp-4) 0' } }>
				{ running ? (
					<button type="button" className="uxs-btn" onClick={ () => ( stop.current = true ) }>
						<Square size={ 14 } aria-hidden="true" /> { t( 'Stop after this batch' ) }
					</button>
				) : (
					<button type="button" className="uxs-btn uxs-btn--primary" onClick={ () => void run() } disabled={ o.capabilities.library === 'none' || ( s !== undefined && s.pending === 0 ) }>
						<Play size={ 14 } aria-hidden="true" /> { t( 'Optimize existing images' ) }
					</button>
				) }
				<button type="button" className="uxs-btn" onClick={ refresh } disabled={ running }>
					<RefreshCw size={ 14 } aria-hidden="true" /> { t( 'Refresh' ) }
				</button>
				{ progress ? (
					<span className="uxs-muted">
						{ t( 'Processed {n}, saved {size}', { n: progress.processed, size: bytes( progress.saved ) } ) }
						{ progress.failed > 0 ? ', ' + t( '{n} failed', { n: progress.failed } ) : '' }
					</span>
				) : null }
			</div>
			{ running && s && s.pending > 0 ? <progress max={ s.pending } value={ Math.min( progress?.processed ?? 0, s.pending ) } style={ { width: '100%' } } /> : null }

			<ul className="uxs-muted">
				<li>
					{ t( 'Image library' ) }: <strong>{ o.capabilities.library.toUpperCase() }</strong>
					{ ' ' }(WebP: { o.capabilities.formats.webp ? t( 'yes' ) : t( 'no' ) }, AVIF: { o.capabilities.formats.avif ? t( 'yes' ) : t( 'no' ) })
				</li>
				<li>{ o.auto ? t( 'New uploads to the Media Manager are optimized automatically.' ) : t( 'Automatic optimization of new uploads is off.' ) }</li>
				<li>{ o.bulk_enabled ? t( 'The scheduler optimizes existing images in the background, every 15 minutes.' ) : t( 'Background optimization by the scheduler is off.' ) }</li>
				<li>
					{ o.delivery.enabled
						? o.delivery.active
							? t( 'WebP / AVIF are served automatically through images/.htaccess.' )
							: t( 'Automatic delivery is on but images/.htaccess has not been updated yet (reload the page).' )
						: t( 'WebP / AVIF delivery through .htaccess is off, the siblings are only created.' ) }
				</li>
				<li>{ t( '{n} originals are kept in media/uxstudio-originals.', { n: o.totals.backups } ) }</li>
			</ul>
			<p className="uxs-muted">{ t( 'Every image is compressed only once. A file is handled again only when it was replaced or when you choose Optimize again.' ) }</p>
		</>
	);
}

function ImagesTab(): JSX.Element {
	const [ page, setPage ] = useState( 1 );
	const [ status, setStatus ] = useState( '' );
	const [ search, setSearch ] = useState( '' );
	const per = 25;
	const list = useQuery( {
		queryKey: [ 'image-optimizer', 'items', page, status, search ],
		queryFn: () => api.get< { items: Row[]; total: number } >( 'image-optimizer/items', { page, per, status, q: search } ),
	} );

	const restore = useMutation( {
		mutationFn: ( path: string ) => api.post( 'image-optimizer/restore', { path } ),
		onSuccess: () => {
			pushToast( 'success', t( 'The original was restored.' ) );
			refresh();
		},
		onError: ( e ) => pushToast( 'error', errorText( e ) ),
	} );
	const again = useMutation( {
		mutationFn: ( path: string ) => api.post( 'image-optimizer/optimize', { path, force: true } ),
		onSuccess: () => {
			pushToast( 'success', t( 'The image was optimized again.' ) );
			refresh();
		},
		onError: ( e ) => pushToast( 'error', errorText( e ) ),
	} );

	const columns: Column< Row >[] = [
		{ key: 'path', header: t( 'Image' ), render: ( r ) => <code>{ r.path }</code> },
		{ key: 'status', header: t( 'Status' ), render: ( r ) => <span className={ `uxs-badge ${ STATUS_BADGE[ r.status ] }` }>{ statusLabel( r.status ) }</span> },
		{ key: 'original_size', header: t( 'Before' ), render: ( r ) => bytes( Number( r.original_size ) ) },
		{ key: 'file_size', header: t( 'After' ), render: ( r ) => bytes( Number( r.file_size ) ) },
		{
			key: 'webp_size',
			header: 'WebP / AVIF',
			render: ( r ) => ( Number( r.webp_size ) > 0 ? bytes( Number( r.webp_size ) ) : '-' ) + ' / ' + ( Number( r.avif_size ) > 0 ? bytes( Number( r.avif_size ) ) : '-' ),
		},
		{ key: 'width', header: t( 'Size (px)' ), render: ( r ) => ( Number( r.width ) > 0 ? r.width + ' x ' + r.height : '-' ) },
		{ key: 'note', header: t( 'Note' ), render: ( r ) => ( r.note ? noteLabel( r.note ) : '' ) },
		{ key: 'updated', header: t( 'Time' ), render: ( r ) => formatDate( r.updated ) },
		{
			key: 'actions',
			header: '',
			render: ( r ) => (
				<span className="uxs-actions">
					{ Number( r.backup ) === 1 ? (
						<button
							type="button"
							className="uxs-btn uxs-btn--small"
							disabled={ restore.isPending }
							onClick={ async () => {
								if ( await confirm( { message: t( 'Put the original back and delete the optimized version and its WebP / AVIF files?' ), confirmLabel: t( 'Restore' ) } ) ) {
									restore.mutate( r.path );
								}
							} }
						>
							<Undo2 size={ 14 } aria-hidden="true" /> { t( 'Restore' ) }
						</button>
					) : null }
					<button type="button" className="uxs-btn uxs-btn--small" disabled={ again.isPending } onClick={ () => again.mutate( r.path ) } title={ t( 'Optimize again with the current settings' ) }>
						<Wand2 size={ 14 } aria-hidden="true" /> { t( 'Optimize again' ) }
					</button>
				</span>
			),
		},
	];

	const total = list.data?.total ?? 0;

	return (
		<>
			<div className="uxs-actions" style={ { marginBottom: 'var(--uxs-sp-4)' } }>
				<select
					value={ status }
					aria-label={ t( 'Status' ) }
					onChange={ ( e ) => {
						setStatus( e.target.value );
						setPage( 1 );
					} }
				>
					<option value="">{ t( 'All statuses' ) }</option>
					<option value="optimized">{ t( 'Optimized' ) }</option>
					<option value="skipped">{ t( 'Left as it was' ) }</option>
					<option value="failed">{ t( 'Failed' ) }</option>
					<option value="restored">{ t( 'Restored' ) }</option>
				</select>
				<input
					type="search"
					value={ search }
					placeholder={ t( 'Search path' ) }
					aria-label={ t( 'Search path' ) }
					onChange={ ( e ) => {
						setSearch( e.target.value );
						setPage( 1 );
					} }
				/>
			</div>
			{ list.isLoading ? <Loading /> : null }
			{ list.error ? <p role="alert">{ errorText( list.error ) }</p> : null }
			<DataTable
				columns={ columns }
				rows={ list.data?.items ?? [] }
				rowKey={ ( r ) => r.id }
				empty={ t( 'No images have been processed yet.' ) }
				page={ page }
				pages={ Math.max( 1, Math.ceil( total / per ) ) }
				onPage={ setPage }
			/>
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
					{ id: 'images', label: t( 'Images' ) },
					{ id: 'settings', label: t( 'Settings' ) },
				] }
			/>
			<TabPanel id={ tab }>
				{ tab === 'overview' ? <OverviewTab /> : null }
				{ tab === 'images' ? <ImagesTab /> : null }
				{ tab === 'settings' ? <SettingsPanel moduleId={ module.id } /> : null }
			</TabPanel>
		</>
	);
}
