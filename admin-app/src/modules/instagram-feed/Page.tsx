/**
 * Instagram Feed: connect an Instagram account through the Central App, synchronise and moderate the cached posts,
 * see how to show the feed on the site; plus the settings.
 */
import { useEffect, useState } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import { Camera, Eye, EyeOff, RefreshCw, Unplug } from 'lucide-react';
import { api, queryClient, type ModuleInfo } from '../../app/api';
import { createT, formatDate } from '../../app/i18n';
import { hashParam, navigate } from '../../app/route';
import { SettingsPanel } from '../../app/SettingsForm';
import { DataTable, Loading, TabPanel, Tabs, confirm, pushToast, type Column } from '../../app/ui';

interface Status {
	ca: { availability: 'ok' | 'connector_missing' | 'not_paired' };
	connection: {
		id: number;
		connected: boolean;
		username: string;
		name: string;
		account_type: string;
		followers_count: number | null;
		media_count: number | null;
		connected_at: string;
		lost: boolean;
	};
	cache: {
		posts: number;
		hidden: number;
		local_images: number;
		last_sync: { at: string; status: string; message: string };
	};
	tag: string;
	image_dir: string;
	store_images: boolean;
}

interface SyncResult {
	fetched: number;
	new: number;
	updated: number;
	images: number;
	removed: number;
	skipped: number;
}

type Post = {
	id: number;
	instagram_id: string;
	media_type: string;
	permalink: string;
	caption: string;
	media_timestamp: string | null;
	is_hidden: boolean;
	image_url: string;
	local: boolean;
};

interface PostPage {
	items: Post[];
	total: number;
	page: number;
	pages: number;
}

const { t, tn } = createT( 'instagram-feed' );
const PER_PAGE = 24;
const MODULE_URL = '?option=com_uxstudio#/module?id=instagram-feed';

function typeLabel( type: string ): string {
	const labels: Record< string, string > = { IMAGE: t( 'Photo' ), VIDEO: t( 'Video' ), CAROUSEL_ALBUM: t( 'Album' ) };
	return labels[ type ] ?? type;
}

function reasonLabel( reason: string ): string {
	const labels: Record< string, string > = {
		invalid_callback: t( 'The answer from the Central App could not be verified or has expired. Try connecting again.' ),
		denied: t( 'The Instagram login was cancelled or refused.' ),
	};
	return labels[ reason ] ?? t( 'The connection failed ({reason}).', { reason } );
}

function OverviewTab( { status }: { status: Status } ): JSX.Element {
	const refresh = (): void => void queryClient.invalidateQueries( { queryKey: [ 'instagram-feed' ] } );

	const connect = useMutation( {
		mutationFn: () => api.post< { auth_url: string } >( 'instagram-feed/connect' ),
		onSuccess: ( r ) => {
			window.location.href = r.auth_url;
		},
		onError: ( e: Error ) => pushToast( 'error', e.message ),
	} );
	const sync = useMutation( {
		mutationFn: () => api.post< SyncResult >( 'instagram-feed/sync' ),
		onSuccess: ( r ) => {
			pushToast( 'success', t( '{new} new, {updated} updated, {removed} removed, {images} images stored.', { new: r.new, updated: r.updated, removed: r.removed, images: r.images } ) );
			refresh();
		},
		onError: ( e: Error ) => {
			pushToast( 'error', e.message );
			refresh();
		},
	} );
	const disconnect = useMutation( {
		mutationFn: () => api.post( 'instagram-feed/disconnect' ),
		onSuccess: () => {
			pushToast( 'success', t( 'The account was disconnected and the cached posts were deleted.' ) );
			void queryClient.invalidateQueries( { queryKey: [ 'settings', 'instagram-feed' ] } );
			refresh();
		},
		onError: ( e: Error ) => pushToast( 'error', e.message ),
	} );

	const caOk = status.ca.availability === 'ok';
	const c = status.connection;
	const last = status.cache.last_sync;

	return (
		<>
			{ ! caOk ? (
				<div className="uxs-notice" role="status">
					{ status.ca.availability === 'connector_missing'
						? t( 'The Central App module is switched off, so no Instagram account can be connected or synchronised. Posts that are already stored are still shown on the site.' )
						: t( 'This site is not connected to the Central App, so no Instagram account can be connected or synchronised. Posts that are already stored are still shown on the site.' ) }{ ' ' }
					<button type="button" className="uxs-btn uxs-btn--small" onClick={ () => navigate( 'module', { id: 'ca-connector' } ) }>
						{ t( 'Open Central App' ) }
					</button>
				</div>
			) : null }

			{ c.lost ? (
				<div className="uxs-notice uxs-notice--error" role="alert">
					{ t( 'The Central App no longer knows this Instagram connection (the access was revoked or expired). Connect the account again.' ) }
				</div>
			) : null }

			<h3 className="uxs-section-title">{ t( 'Account' ) }</h3>
			{ c.connected ? (
				<p>
					<strong>{ c.username ? `@${ c.username }` : t( 'Connection #{id}', { id: c.id } ) }</strong>
					{ c.account_type ? <span className="uxs-muted"> · { c.account_type }</span> : null }
					{ c.followers_count !== null ? <span className="uxs-muted"> · { tn( '{n} follower', '{n} followers', c.followers_count ) }</span> : null }
					{ c.connected_at ? <span className="uxs-muted"> · { t( 'connected {when}', { when: formatDate( c.connected_at ) } ) }</span> : null }
				</p>
			) : (
				<p className="uxs-muted">{ t( 'No Instagram account is connected yet. Connecting opens the Central App, where you sign in to Instagram and approve access (a business or creator account linked to a Facebook page is required).' ) }</p>
			) }
			<div className="uxs-actions" style={ { marginBottom: 'var(--uxs-sp-5)' } }>
				<button type="button" className="uxs-btn uxs-btn--primary" disabled={ ! caOk || connect.isPending } onClick={ () => connect.mutate() }>
					<Camera size={ 14 } aria-hidden="true" /> { c.connected ? t( 'Reconnect Instagram' ) : t( 'Connect Instagram' ) }
				</button>
				<button type="button" className="uxs-btn" disabled={ ! caOk || ! c.connected || sync.isPending } onClick={ () => sync.mutate() }>
					<RefreshCw size={ 14 } aria-hidden="true" /> { sync.isPending ? t( 'Synchronising…' ) : t( 'Synchronise now' ) }
				</button>
				{ c.connected ? (
					<button
						type="button"
						className="uxs-btn uxs-btn--danger"
						disabled={ disconnect.isPending }
						onClick={ () =>
							void confirm( {
								message: t( 'Disconnect the Instagram account? The cached posts and the images stored on this server are deleted and the Central App forgets the connection.' ),
								danger: true,
								confirmLabel: t( 'Disconnect' ),
							} ).then( ( ok ) => ok && disconnect.mutate() )
						}
					>
						<Unplug size={ 14 } aria-hidden="true" /> { t( 'Disconnect' ) }
					</button>
				) : null }
			</div>

			<div className="uxs-cards">
				<div className="uxs-card">
					<span className="uxs-card__label">{ t( 'Posts stored' ) }</span>
					<span className="uxs-card__num">{ status.cache.posts }</span>
				</div>
				<div className="uxs-card">
					<span className="uxs-card__label">{ t( 'Hidden from the site' ) }</span>
					<span className="uxs-card__num">{ status.cache.hidden }</span>
				</div>
				<div className="uxs-card">
					<span className="uxs-card__label">{ t( 'Images on this server' ) }</span>
					<span className="uxs-card__num">{ status.cache.local_images }</span>
				</div>
			</div>
			<p className="uxs-muted">
				{ last.at
					? t( 'Last synchronisation: {when} ({result}).', { when: formatDate( last.at ), result: last.status === 'ok' ? last.message : last.message || t( 'failed' ) } )
					: t( 'Not synchronised yet.' ) }
			</p>

			<h3 className="uxs-section-title">{ t( 'Show the feed on the site' ) }</h3>
			<p className="uxs-form__help">
				{ t( 'Add the module "UX Studio - Instagram" to a position (Content - Site Modules), or put {tag} into an article or a Custom module. The tag accepts layout (grid, carousel), count, columns, columns_tablet, columns_mobile, gap, show_caption, show_likes, show_follow and hashtags, for example {example}.', {
					tag: status.tag,
					example: '{uxstudio_instagram layout=carousel count=8 columns=4}',
				} ) }
			</p>
			<p className="uxs-form__help">
				{ status.store_images
					? t( 'The images are copied to {dir} and the feed is served from this site, so visitors never contact Instagram and the feed keeps working when the Central App is down. A post whose image could not be downloaded is not shown.', { dir: status.image_dir } )
					: t( 'Storing images is switched off: the browsers of visitors load the pictures straight from Instagram (their IP address goes to Meta and the links expire after a few days). Switch "Store images on this server" on in the settings.' ) }
			</p>
		</>
	);
}

function PostsTab(): JSX.Element {
	const [ page, setPage ] = useState( 1 );
	const list = useQuery( {
		queryKey: [ 'instagram-feed', 'media', page ],
		queryFn: () => api.get< PostPage >( 'instagram-feed/media', { page, per_page: PER_PAGE } ),
	} );
	const toggle = useMutation( {
		mutationFn: ( id: number ) => api.post( `instagram-feed/media/${ id }/toggle-hidden` ),
		onSuccess: () => void queryClient.invalidateQueries( { queryKey: [ 'instagram-feed' ] } ),
		onError: ( e: Error ) => pushToast( 'error', e.message ),
	} );

	const columns: Column< Post >[] = [
		{
			key: 'image',
			header: '',
			render: ( p ) =>
				p.image_url ? (
					<img src={ p.image_url } alt="" width={ 56 } height={ 56 } loading="lazy" referrerPolicy="no-referrer" style={ { objectFit: 'cover', borderRadius: 4, display: 'block' } } />
				) : (
					<span className="uxs-muted">-</span>
				),
		},
		{
			key: 'caption',
			header: t( 'Caption' ),
			render: ( p ) => {
				const text = p.caption.length > 120 ? `${ p.caption.slice( 0, 120 ) }…` : p.caption;
				return p.permalink ? (
					<a href={ p.permalink } target="_blank" rel="noopener noreferrer">
						{ text || t( 'Open on Instagram' ) }
					</a>
				) : (
					text
				);
			},
		},
		{ key: 'media_type', header: t( 'Type' ), render: ( p ) => typeLabel( p.media_type ) },
		{ key: 'media_timestamp', header: t( 'Date' ), render: ( p ) => ( p.media_timestamp ? formatDate( p.media_timestamp ) : '' ) },
		{
			key: 'is_hidden',
			header: t( 'Shown' ),
			render: ( p ) => <span className={ `uxs-badge ${ p.is_hidden ? '' : 'is-success' }` }>{ p.is_hidden ? t( 'Hidden' ) : t( 'Yes' ) }</span>,
		},
		{
			key: 'actions',
			header: '',
			render: ( p ) => (
				<button type="button" className="uxs-btn uxs-btn--small" disabled={ toggle.isPending } onClick={ () => toggle.mutate( p.id ) } aria-label={ p.is_hidden ? t( 'Show post' ) : t( 'Hide post' ) } title={ p.is_hidden ? t( 'Show post' ) : t( 'Hide post' ) }>
					{ p.is_hidden ? <Eye size={ 14 } aria-hidden="true" /> : <EyeOff size={ 14 } aria-hidden="true" /> }
				</button>
			),
		},
	];

	const data = list.data;

	return (
		<>
			{ list.isLoading ? <Loading /> : null }
			{ list.error ? <p role="alert">{ list.error.message }</p> : null }
			{ data ? (
				<>
					<p className="uxs-muted uxs-list-toolbar__count">{ tn( '{n} post', '{n} posts', data.total ) }</p>
					<DataTable columns={ columns } rows={ data.items } rowKey={ ( p ) => p.id } page={ page } pages={ data.pages } onPage={ setPage } empty={ t( 'No posts stored yet. Connect an account and press "Synchronise now".' ) } />
				</>
			) : null }
		</>
	);
}

export default function Page( { module }: { module: ModuleInfo } ): JSX.Element {
	const [ tab, setTab ] = useState( 'overview' );
	const status = useQuery( { queryKey: [ 'instagram-feed', 'status' ], queryFn: () => api.get< Status >( 'instagram-feed/status' ) } );

	// Back from the Central App: ...#/module?id=instagram-feed&ig=connected (or ig=error&reason=...).
	useEffect( () => {
		const result = hashParam( 'ig' );
		if ( ! result ) {
			return;
		}
		if ( result === 'connected' ) {
			pushToast( 'success', t( 'Instagram is connected. Press "Synchronise now" to download the posts.' ) );
			void queryClient.invalidateQueries( { queryKey: [ 'instagram-feed' ] } );
			void queryClient.invalidateQueries( { queryKey: [ 'settings', 'instagram-feed' ] } );
		} else {
			pushToast( 'error', reasonLabel( hashParam( 'reason' ) ?? '' ) );
		}
		window.history.replaceState( null, '', window.location.pathname + MODULE_URL );
	}, [] );

	return (
		<>
			<Tabs
				label={ module.name }
				active={ tab }
				onChange={ setTab }
				items={ [
					{ id: 'overview', label: t( 'Overview' ) },
					{ id: 'posts', label: t( 'Posts' ) },
					{ id: 'settings', label: t( 'Settings' ) },
				] }
			/>
			{ tab === 'overview' ? (
				<TabPanel id="overview">
					{ status.isLoading ? <Loading /> : null }
					{ status.error ? <p role="alert">{ status.error.message }</p> : null }
					{ status.data ? <OverviewTab status={ status.data } /> : null }
				</TabPanel>
			) : null }
			{ tab === 'posts' ? (
				<TabPanel id="posts">
					<PostsTab />
				</TabPanel>
			) : null }
			{ tab === 'settings' ? (
				<TabPanel id="settings">
					<SettingsPanel moduleId={ module.id } />
				</TabPanel>
			) : null }
		</>
	);
}
