/**
 * UX1 Network app connector: pairing with UX1 Network app, health of the connection, SSO tokens and the audit log.
 */
import { useEffect, useState } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import { Link2, RefreshCw, ShieldCheck, Unplug } from 'lucide-react';
import { api, queryClient, type ModuleInfo } from '../../app/api';
import { createT, formatDate } from '../../app/i18n';
import { hashParam } from '../../app/route';
import { SettingsPanel } from '../../app/SettingsForm';
import { DataTable, Loading, TabPanel, Tabs, confirm, pushToast, type Column } from '../../app/ui';

interface Status {
	ca_url: string;
	ca_url_effective: string;
	default_url: string;
	key_set: boolean;
	paired: boolean;
	awaiting: boolean;
	paired_at: string | null;
	prepared_at: string | null;
	last_contact: string | null;
	site_url: string;
	site_name: string;
	site_uid: string;
	node_url: string;
	capabilities: string[];
	enabled_modules: string[];
	sso: { allow_admin: boolean; provision: boolean; outstanding: number };
}

interface PairForm {
	form: { action: string; fields: Record< string, string > };
	ca_url: string;
}

interface SelfCheck {
	ok: boolean;
	status: number;
	error: string;
	capabilities: string[];
}

type LogRow = {
	id: number;
	created: string;
	event: string;
	ok: number;
	detail: string;
	ip: string;
	user_id: number;
};

const { t } = createT( 'ca-connector' );

const CAPABILITY_LABELS: Record< string, string > = {
	updates: 'Updates',
	php_errors: 'PHP errors',
	sso: 'Single sign-on',
	email_health: 'E-mail health',
	email_log: 'E-mail log',
	security_monitor: 'Security monitor',
	ip_bans: 'IP bans',
	vulnerabilities: 'Vulnerabilities',
	bot_bans: 'Bot bans',
};

/** The same cross-site form POST the browser does in the WordPress plugin: UX1 Network app needs the visitor's session. */
function submitForm( form: PairForm[ 'form' ] ): void {
	const el = document.createElement( 'form' );
	el.method = 'post';
	el.action = form.action;
	for ( const [ name, value ] of Object.entries( form.fields ) ) {
		const input = document.createElement( 'input' );
		input.type = 'hidden';
		input.name = name;
		input.value = value;
		el.appendChild( input );
	}
	document.body.appendChild( el );
	el.submit();
}

function Overview( { status }: { status: Status } ): JSX.Element {
	const [ url, setUrl ] = useState( status.ca_url );
	// The address is also a setting: follow it when it is saved there.
	useEffect( () => setUrl( status.ca_url ), [ status.ca_url ] );
	const refresh = (): void => void queryClient.invalidateQueries( { queryKey: [ 'ca-connector' ] } );

	const connect = useMutation( {
		mutationFn: ( rotate: boolean ) => api.post< PairForm >( 'ca-connector/prepare', { ca_url: url.trim(), rotate } ),
		onSuccess: ( r ) => {
			refresh();
			submitForm( r.form );
		},
		onError: ( e: Error ) => pushToast( 'error', e.message ),
	} );
	const check = useMutation( {
		mutationFn: () => api.post< SelfCheck >( 'ca-connector/self-check' ),
		onSuccess: ( r ) => {
			refresh();
			pushToast( r.ok ? 'success' : 'error', r.ok ? t( 'The endpoint answers and signs correctly.' ) : t( 'The endpoint check failed: {error}', { error: r.error || `HTTP ${ r.status }` } ) );
		},
		onError: ( e: Error ) => pushToast( 'error', e.message ),
	} );
	const revoke = useMutation( {
		mutationFn: () => api.post< { revoked: number } >( 'ca-connector/revoke-sso' ),
		onSuccess: ( r ) => {
			refresh();
			pushToast( 'success', t( '{n} sign-in link(s) revoked.', { n: r.revoked } ) );
		},
		onError: ( e: Error ) => pushToast( 'error', e.message ),
	} );
	const disconnect = useMutation( {
		mutationFn: () => api.post( 'ca-connector/disconnect' ),
		onSuccess: () => {
			refresh();
			pushToast( 'success', t( 'Disconnected. Remove the site in UX1 Network app too.' ) );
		},
		onError: ( e: Error ) => pushToast( 'error', e.message ),
	} );

	const onDisconnect = async (): Promise< void > => {
		if ( await confirm( { message: t( 'Forget the key and the pairing on this site? UX1 Network app will no longer be able to reach it.' ), danger: true } ) ) {
			disconnect.mutate();
		}
	};
	const onReconnect = async (): Promise< void > => {
		if ( await confirm( { message: t( 'A new key will be generated and UX1 Network app has to confirm the pairing again. Continue?' ) } ) ) {
			connect.mutate( true );
		}
	};

	return (
		<>
			<div className="uxs-cards">
				<div className="uxs-card">
					<span className="uxs-card__label">{ t( 'Status' ) }</span>
					<span className="uxs-card__num">{ status.paired ? t( 'Connected' ) : status.awaiting ? t( 'Awaiting confirmation' ) : t( 'Not connected' ) }</span>
					<span className="uxs-muted">{ status.paired_at ? formatDate( status.paired_at ) : status.awaiting ? t( 'Waiting for UX1 Network app to confirm the pairing.' ) : t( 'Press Connect to pair this site.' ) }</span>
				</div>
				<div className="uxs-card">
					<span className="uxs-card__label">{ t( 'Last contact from UX1 Network app' ) }</span>
					<span className="uxs-card__num">{ status.last_contact ? formatDate( status.last_contact ) : '-' }</span>
				</div>
				<div className="uxs-card">
					<span className="uxs-card__label">{ t( 'Sign-in links waiting' ) }</span>
					<span className="uxs-card__num">{ status.sso.outstanding }</span>
				</div>
			</div>

			<div className="uxs-actions" style={ { marginBottom: 'var(--uxs-sp-4)' } }>
				<label htmlFor="ca-connector-url">{ t( 'UX1 Network app address' ) }</label>
				<input id="ca-connector-url" className="uxs-input" type="url" value={ url } placeholder={ status.default_url } onChange={ ( e ) => setUrl( e.target.value ) } />
				<span className="uxs-muted">{ t( 'Leave empty for the production UX1 Network app ({url}).', { url: status.default_url } ) }</span>
			</div>

			<div className="uxs-actions" style={ { marginBottom: 'var(--uxs-sp-4)' } }>
				<button type="button" className="uxs-btn uxs-btn--primary" onClick={ () => connect.mutate( false ) } disabled={ connect.isPending }>
					<Link2 size={ 14 } aria-hidden="true" /> { status.paired ? t( 'Pair again' ) : t( 'Connect to UX1 Network app' ) }
				</button>
				{ status.key_set ? (
					<>
						<button type="button" className="uxs-btn" onClick={ () => check.mutate() } disabled={ check.isPending }>
							<RefreshCw size={ 14 } aria-hidden="true" /> { t( 'Check endpoint' ) }
						</button>
						<button type="button" className="uxs-btn" onClick={ () => void onReconnect() }>
							<ShieldCheck size={ 14 } aria-hidden="true" /> { t( 'New key and pair again' ) }
						</button>
						<button type="button" className="uxs-btn" onClick={ () => revoke.mutate() } disabled={ revoke.isPending || status.sso.outstanding === 0 }>
							{ t( 'Revoke sign-in links' ) }
						</button>
						<button type="button" className="uxs-btn uxs-btn--danger" onClick={ () => void onDisconnect() }>
							<Unplug size={ 14 } aria-hidden="true" /> { t( 'Disconnect' ) }
						</button>
					</>
				) : null }
			</div>

			{ ! status.sso.allow_admin ? (
				<div className="uxs-notice">
					<p>{ t( 'Single sign-on into Super User and Administrator accounts is off. Turn it on in Settings once you want to open this administration from UX1 Network app.' ) }</p>
				</div>
			) : null }

			<h3>{ t( 'This site' ) }</h3>
			<p className="uxs-muted">
				{ t( 'Address' ) }: <code>{ status.site_url }</code>
				<br />
				{ t( 'Endpoint' ) }: <code>{ status.node_url }</code>
				<br />
				{ t( 'Site ID' ) }: <code>{ status.site_uid || '-' }</code>
			</p>

			<h3>{ t( 'Offered to UX1 Network app' ) }</h3>
			<p>
				{ status.capabilities.map( ( c ) => (
					<span key={ c } className="uxs-badge is-info" style={ { marginRight: 'var(--uxs-sp-2)' } }>
						{ t( CAPABILITY_LABELS[ c ] ?? c ) }
					</span>
				) ) }
			</p>
			<p className="uxs-muted">{ t( 'Features of modules that are switched off are not offered (their routes answer 404).' ) }</p>
		</>
	);
}

function Activity(): JSX.Element {
	const log = useQuery( { queryKey: [ 'ca-connector', 'log' ], queryFn: () => api.get< { items: LogRow[] } >( 'ca-connector/log', { limit: 100 } ) } );
	const columns: Column< LogRow >[] = [
		{ key: 'created', header: t( 'Time' ), render: ( r ) => formatDate( r.created ) },
		{ key: 'event', header: t( 'Event' ) },
		{ key: 'ok', header: t( 'Result' ), render: ( r ) => <span className={ `uxs-badge ${ r.ok === 1 ? 'is-success' : 'is-danger' }` }>{ r.ok === 1 ? t( 'OK' ) : t( 'Failed' ) }</span> },
		{ key: 'detail', header: t( 'Detail' ) },
		{ key: 'ip', header: 'IP' },
	];
	if ( log.isLoading ) {
		return <Loading />;
	}
	return <DataTable columns={ columns } rows={ log.data?.items ?? [] } rowKey={ ( r ) => r.id } empty={ t( 'Nothing logged yet.' ) } pageSize={ 15 } />;
}

export default function Page( { module }: { module: ModuleInfo } ): JSX.Element {
	const [ tab, setTab ] = useState( 'overview' );
	const status = useQuery( { queryKey: [ 'ca-connector', 'status' ], queryFn: () => api.get< Status >( 'ca-connector/status' ) } );

	// Back from UX1 Network app: ...#/module?id=ca-connector&ux1_connected=1 (or ux1_connect_cancelled=1).
	useEffect( () => {
		const fromQuery = new URLSearchParams( window.location.search );
		const connected = hashParam( 'ux1_connected' ) ?? fromQuery.get( 'ux1_connected' );
		const cancelled = hashParam( 'ux1_connect_cancelled' ) ?? fromQuery.get( 'ux1_connect_cancelled' );
		if ( connected ) {
			void api.post< { confirmed: boolean } >( 'ca-connector/connected' ).then( ( r ) => {
				pushToast( r.confirmed ? 'success' : 'error', r.confirmed ? t( 'The site is connected to UX1 Network app.' ) : t( 'Waiting for UX1 Network app to confirm the pairing.' ) );
				void queryClient.invalidateQueries( { queryKey: [ 'ca-connector' ] } );
			}, ( e: Error ) => pushToast( 'error', e.message ) );
		} else if ( cancelled ) {
			pushToast( 'error', t( 'Pairing was cancelled in UX1 Network app.' ) );
		}
		if ( connected || cancelled ) {
			window.history.replaceState( null, '', window.location.pathname + '?option=com_uxstudio#/module?id=ca-connector' );
		}
	}, [] );

	return (
		<>
			<Tabs
				label={ module.name }
				active={ tab }
				onChange={ setTab }
				items={ [
					{ id: 'overview', label: t( 'Overview' ) },
					{ id: 'settings', label: t( 'Settings' ) },
					{ id: 'activity', label: t( 'Activity' ) },
				] }
			/>
			{ tab === 'overview' ? (
				<TabPanel id="overview">
					{ status.isLoading ? <Loading /> : null }
					{ status.error ? <p role="alert">{ status.error.message }</p> : null }
					{ status.data ? <Overview status={ status.data } /> : null }
				</TabPanel>
			) : tab === 'settings' ? (
				<TabPanel id="settings">
					<SettingsPanel moduleId={ module.id } />
				</TabPanel>
			) : (
				<TabPanel id="activity">
					<Activity />
				</TabPanel>
			) }
		</>
	);
}
