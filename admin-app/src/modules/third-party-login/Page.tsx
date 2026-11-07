/**
 * Third-party login screen: providers with the redirect URIs to register, the connections of the signed-in
 * administrator (link / unlink), all linked accounts, the sign-in log and the module settings.
 */
import { useState } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import { Copy, Link2, Unlink } from 'lucide-react';
import { api, ApiError, queryClient, type ModuleInfo } from '../../app/api';
import { createT, formatDate } from '../../app/i18n';
import { SettingsPanel } from '../../app/SettingsForm';
import { DataTable, Loading, TabPanel, Tabs, confirm, pushToast, type Column } from '../../app/ui';

const { t } = createT( 'third-party-login' );

type Provider = { id: string; label: string; enabled: boolean; configured: boolean };
type MyIdentity = { id: number; provider: string; email: string; created: string; last_login: string | null };
type Status = {
	providers: Provider[];
	redirect_uris: { administrator: string; site: string };
	areas: { administrator: boolean; site: boolean };
	me: { id: number; username: string; privileged: boolean; identities: MyIdentity[] };
	allow_privileged: boolean;
};
type Identity = {
	id: number;
	user_id: number;
	provider: string;
	email: string;
	created: string;
	last_login: string | null;
	name: string;
	username: string;
	blocked: boolean;
	orphan: boolean;
};
type LogRow = { id: number; created: string; event: string; provider: string; area: string; user_id: number; detail: string; ip: string };

const KEY = [ 'third-party-login' ];

function errorMessage( e: unknown ): string {
	return e instanceof ApiError || e instanceof Error ? e.message : t( 'Something went wrong.' );
}

/** Event codes as readable text (literal t() calls so the extractor sees them). */
function eventLabel( event: string ): string {
	switch ( event ) {
		case 'login':
			return t( 'Signed in' );
		case 'login_failed':
			return t( 'Sign-in refused' );
		case 'created':
			return t( 'Account created' );
		case 'linked_by_email':
			return t( 'Paired by e-mail' );
		case 'linked':
			return t( 'Linked' );
		case 'unlinked':
			return t( 'Unlinked' );
		default:
			return event;
	}
}

function areaLabel( area: string ): string {
	return area === 'administrator' ? t( 'Administration' ) : area === 'site' ? t( 'Website' ) : area;
}

function CopyUri( { label, uri, active }: { label: string; uri: string; active: boolean } ): JSX.Element {
	return (
		<div className="uxs-form__row uxs-form__row--wide">
			<label>
				{ label } { active ? null : <span className="uxs-muted">({ t( 'turned off' ) })</span> }
			</label>
			<span className="uxs-actions">
				<input type="text" readOnly value={ uri } aria-label={ label } onFocus={ ( e ) => e.currentTarget.select() } />
				<button
					type="button"
					className="uxs-btn uxs-btn--small"
					onClick={ () => {
						void navigator.clipboard.writeText( uri ).then( () => pushToast( 'success', t( 'Copied.' ) ) );
					} }
				>
					<Copy size={ 14 } aria-hidden="true" /> { t( 'Copy' ) }
				</button>
			</span>
		</div>
	);
}

function ProvidersTab( { status }: { status: Status } ): JSX.Element {
	const link = useMutation( {
		mutationFn: ( provider: string ) => api.post< { url: string } >( `third-party-login/link/${ provider }`, {} ),
		onSuccess: ( data ) => {
			window.location.href = data.url;
		},
		onError: ( e ) => pushToast( 'error', errorMessage( e ) ),
	} );
	const unlink = useMutation( {
		mutationFn: ( id: number ) => api.post( `third-party-login/identities/${ id }/unlink`, {} ),
		onSuccess: () => {
			pushToast( 'success', t( 'Connection removed.' ) );
			void queryClient.invalidateQueries( { queryKey: KEY } );
		},
		onError: ( e ) => pushToast( 'error', errorMessage( e ) ),
	} );
	const label = ( id: string ): string => status.providers.find( ( p ) => p.id === id )?.label ?? id;

	return (
		<>
			<h2 className="uxs-form__section">{ t( 'Redirect URIs' ) }</h2>
			<p className="uxs-muted">{ t( 'Register these addresses as authorized redirect URIs in the developer console of every provider you turn on.' ) }</p>
			<div className="uxs-form uxs-form--grid">
				<CopyUri label={ t( 'Administration' ) } uri={ status.redirect_uris.administrator } active={ status.areas.administrator } />
				<CopyUri label={ t( 'Website' ) } uri={ status.redirect_uris.site } active={ status.areas.site } />
			</div>

			<h2 className="uxs-form__section">{ t( 'Providers' ) }</h2>
			<div className="uxs-cards">
				{ status.providers.map( ( p ) => (
					<div key={ p.id } className="uxs-card">
						<span className="uxs-card__label">{ p.label }</span>
						<span className="uxs-card__num">
							<span className={ `uxs-badge ${ p.enabled && p.configured ? 'is-success' : p.enabled ? 'is-warning' : '' }` }>
								{ p.enabled && p.configured ? t( 'Active' ) : p.enabled ? t( 'Incomplete' ) : t( 'Off' ) }
							</span>
						</span>
						{ p.enabled && p.configured ? (
							<button type="button" className="uxs-btn uxs-btn--small" disabled={ link.isPending } onClick={ () => link.mutate( p.id ) }>
								<Link2 size={ 14 } aria-hidden="true" /> { t( 'Link my account' ) }
							</button>
						) : null }
					</div>
				) ) }
			</div>

			<h2 className="uxs-form__section">{ t( 'My connections' ) }</h2>
			{ status.me.privileged && ! status.allow_privileged ? (
				<p className="uxs-muted">{ t( 'Your account is an administrator account. You can link a provider, but signing in with it stays off until "Allow administrators and super users" is turned on in the settings.' ) }</p>
			) : null }
			{ status.me.identities.length === 0 ? (
				<p className="uxs-muted">{ t( 'No external account is linked to your user yet.' ) }</p>
			) : (
				<div role="list">
					{ status.me.identities.map( ( i ) => (
						<div key={ i.id } className="uxs-actions" role="listitem">
							<strong>{ label( i.provider ) }</strong>
							<span className="uxs-muted">{ i.email }</span>
							<button
								type="button"
								className="uxs-btn uxs-btn--small uxs-btn--danger"
								onClick={ () => {
									void confirm( { message: t( 'Remove this connection?' ), confirmLabel: t( 'Remove' ), danger: true } ).then( ( ok ) => ok && unlink.mutate( i.id ) );
								} }
							>
								<Unlink size={ 14 } aria-hidden="true" /> { t( 'Remove' ) }
							</button>
						</div>
					) ) }
				</div>
			) }
		</>
	);
}

function AccountsTab(): JSX.Element {
	const list = useQuery( { queryKey: [ ...KEY, 'identities' ], queryFn: () => api.get< { items: Identity[] } >( 'third-party-login/identities' ) } );
	const unlink = useMutation( {
		mutationFn: ( id: number ) => api.post( `third-party-login/identities/${ id }/unlink`, {} ),
		onSuccess: () => {
			pushToast( 'success', t( 'Connection removed.' ) );
			void queryClient.invalidateQueries( { queryKey: KEY } );
		},
		onError: ( e ) => pushToast( 'error', errorMessage( e ) ),
	} );

	const columns: Column< Identity >[] = [
		{ key: 'provider', header: t( 'Provider' ), sortable: true },
		{
			key: 'username',
			header: t( 'Joomla user' ),
			sortable: true,
			render: ( r ) =>
				r.orphan ? (
					<span className="uxs-muted">{ t( 'deleted user' ) }</span>
				) : (
					<>
						{ r.name } <span className="uxs-muted">({ r.username })</span> { r.blocked ? <span className="uxs-badge is-danger">{ t( 'Blocked' ) }</span> : null }
					</>
				),
		},
		{ key: 'email', header: t( 'E-mail at the provider' ), sortable: true },
		{ key: 'created', header: t( 'Linked' ), sortable: true, render: ( r ) => formatDate( r.created ) },
		{ key: 'last_login', header: t( 'Last sign-in' ), sortable: true, render: ( r ) => ( r.last_login ? formatDate( r.last_login ) : '-' ) },
		{
			key: 'actions',
			header: '',
			render: ( r ) => (
				<button
					type="button"
					className="uxs-btn uxs-btn--small uxs-btn--danger"
					onClick={ () => {
						void confirm( { message: t( 'Remove this connection?' ), confirmLabel: t( 'Remove' ), danger: true } ).then( ( ok ) => ok && unlink.mutate( r.id ) );
					} }
				>
					{ t( 'Remove' ) }
				</button>
			),
		},
	];

	if ( list.isLoading ) {
		return <Loading />;
	}
	if ( list.error ) {
		return <p role="alert">{ errorMessage( list.error ) }</p>;
	}
	return <DataTable columns={ columns } rows={ list.data?.items ?? [] } rowKey={ ( r ) => r.id } empty={ t( 'No external account is linked yet.' ) } pageSize={ 25 } />;
}

function LogTab(): JSX.Element {
	const log = useQuery( { queryKey: [ ...KEY, 'log' ], queryFn: () => api.get< { items: LogRow[] } >( 'third-party-login/log' ) } );
	const columns: Column< LogRow >[] = [
		{ key: 'created', header: t( 'Time' ), sortable: true, render: ( r ) => formatDate( r.created ) },
		{ key: 'event', header: t( 'Event' ), render: ( r ) => <span className={ `uxs-badge ${ r.event === 'login_failed' ? 'is-danger' : 'is-success' }` }>{ eventLabel( r.event ) }</span> },
		{ key: 'provider', header: t( 'Provider' ) },
		{ key: 'area', header: t( 'Where' ), render: ( r ) => areaLabel( r.area ) },
		{ key: 'detail', header: t( 'Detail' ) },
		{ key: 'ip', header: 'IP' },
	];
	if ( log.isLoading ) {
		return <Loading />;
	}
	if ( log.error ) {
		return <p role="alert">{ errorMessage( log.error ) }</p>;
	}
	return <DataTable columns={ columns } rows={ log.data?.items ?? [] } rowKey={ ( r ) => r.id } empty={ t( 'Nothing logged yet.' ) } pageSize={ 25 } />;
}

export default function Page( { module }: { module: ModuleInfo } ): JSX.Element {
	const [ tab, setTab ] = useState( 'providers' );
	const status = useQuery( { queryKey: [ ...KEY, 'status' ], queryFn: () => api.get< Status >( 'third-party-login/status' ) } );

	return (
		<>
			<Tabs
				label={ module.name }
				active={ tab }
				onChange={ setTab }
				items={ [
					{ id: 'providers', label: t( 'Providers' ) },
					{ id: 'accounts', label: t( 'Linked accounts' ) },
					{ id: 'log', label: t( 'Log' ) },
					{ id: 'settings', label: t( 'Settings' ) },
				] }
			/>
			{ tab === 'providers' ? (
				<TabPanel id="providers">
					{ status.isLoading ? <Loading /> : null }
					{ status.error ? <p role="alert">{ errorMessage( status.error ) }</p> : null }
					{ status.data ? <ProvidersTab status={ status.data } /> : null }
				</TabPanel>
			) : null }
			{ tab === 'accounts' ? (
				<TabPanel id="accounts">
					<AccountsTab />
				</TabPanel>
			) : null }
			{ tab === 'log' ? (
				<TabPanel id="log">
					<LogTab />
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
