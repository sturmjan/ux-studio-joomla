/**
 * Security optimization screen: overview of the protections, IP bans (firewall), login protection (lock-outs and log),
 * Content-Security-Policy (policy preview + collected violation reports), hardening report, and the module settings.
 */
import { useState } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import { Copy, Plus, RefreshCw, Trash2, Unlock } from 'lucide-react';
import { api, ApiError, queryClient, type ModuleInfo } from '../../app/api';
import { createT, formatDate } from '../../app/i18n';
import { SettingsPanel } from '../../app/SettingsForm';
import { DataTable, Loading, Modal, TabPanel, Tabs, ToggleSwitch, confirm, pushToast, type Column } from '../../app/ui';

const { t } = createT( 'security-optimization' );

type Overview = {
	client_ip: string;
	remote_addr: string;
	ip_mode: string;
	edge_warning?: boolean;
	local_ip?: boolean;
	firewall: boolean;
	active_bans: number;
	login_stats: Record< 'fail' | 'lockout' | 'blocked' | 'captcha' | 'success' | 'secret', number >;
	active_locks: Lock[];
	csp_mode: string;
	csp_level: string;
	csp_reports: number;
	headers: boolean;
	hsts: boolean;
	generator_hidden: boolean;
	login_limit: boolean;
	turnstile: boolean;
	secret_url: string;
	secret_enabled: boolean;
	secret_valid: boolean;
	rescue_active: boolean;
};

type Lock = { ip: string; locked_until: string; strikes: number };

type Ban = {
	id: number;
	type: string;
	value: string;
	label: string;
	note: string;
	origin: string;
	is_active: boolean;
	expires_at: string | null;
	expired: boolean;
	hit_count: number;
	last_hit_at: string | null;
	created_at: string;
};

type BanList = { items: Ban[]; total: number; page: number; pages: number };

type LogRow = { id: number; created: string; kind: string; ip: string; username: string };
type LoginLog = { items: LogRow[]; locks: Lock[]; stats: Overview[ 'login_stats' ] };

type Violation = { id: number; directive: string; blocked: string; hits: number; first_seen: string; last_seen: string; sample: string };
type Csp = { mode: string; policy: string; violations: Violation[] };

type Check = { id: string; status: 'ok' | 'warn' | 'bad'; label: string; detail: string };
type Hardening = { checks: Check[]; leaky_files: string[]; htaccess: string; super_users: Array< { id: number; username: string; mfa: boolean; lastvisit: string | null } > };
type Probe = { files: Array< { file: string; kind: string; status: number; exposed: boolean } > };

const BADGE = { ok: 'is-success', warn: 'is-warning', bad: 'is-danger' } as const;

function errorMessage( e: unknown ): string {
	return e instanceof ApiError || e instanceof Error ? e.message : t( 'Something went wrong.' );
}

function Flag( { on, yes, no }: { on: boolean; yes?: string; no?: string } ): JSX.Element {
	return <span className={ `uxs-badge ${ on ? 'is-success' : 'is-warning' }` }>{ on ? yes ?? t( 'On' ) : no ?? t( 'Off' ) }</span>;
}

/* ---------------------------------------------------------------- overview */

function OverviewTab( { onGo }: { onGo: ( tab: string ) => void } ): JSX.Element {
	const q = useQuery( { queryKey: [ 'secopt', 'overview' ], queryFn: () => api.get< Overview >( 'security-optimization/overview' ) } );
	if ( q.isLoading ) {
		return <Loading />;
	}
	if ( q.error || ! q.data ) {
		return <p role="alert">{ errorMessage( q.error ) }</p>;
	}
	const o = q.data;
	const modeLabel: Record< string, string > = { none: t( 'Direct connection' ), cloudflare: t( 'Behind Cloudflare' ), xff: t( 'Behind a reverse proxy' ) };
	const cspLabel: Record< string, string > = { off: t( 'Off' ), 'report-only': t( 'Report only' ), enforce: t( 'Enforced' ) };

	return (
		<>
			{ o.local_ip ? (
				<div className="uxs-notice" role="alert">
					<strong>{ t( 'The visitor address is a private or loopback address.' ) }</strong> { t( 'The site is probably behind a reverse proxy: check Settings, IP mode. Such an address is never banned automatically.' ) }
				</div>
			) : null }
			{ o.edge_warning ? (
				<div className="uxs-notice" role="alert">
					<strong>{ t( 'The visitor address is a proxy / Cloudflare edge address.' ) }</strong> { t( 'Check Settings, IP mode and trusted proxies, otherwise all visitors look like one.' ) }
				</div>
			) : null }
			{ o.rescue_active ? (
				<div className="uxs-notice" role="alert">
					<strong>{ t( 'Login protection is switched off by UXSTUDIO_DISABLE_LOGIN_PROTECTION.' ) }</strong> { t( 'Lock-outs, Turnstile and the secret URL do nothing until the constant is removed.' ) }
				</div>
			) : null }
			{ o.secret_enabled && ! o.secret_valid ? (
				<div className="uxs-notice" role="alert">{ t( 'The secret administrator URL is turned on but the parameter is invalid, so the gate is OFF. Use 4-40 letters, digits or underscores.' ) }</div>
			) : null }
			<div className="uxs-cards">
				<div className="uxs-card">
					<span className="uxs-card__label">{ t( 'Active IP bans' ) }</span>
					<span className="uxs-card__num">{ o.active_bans }</span>
				</div>
				<div className="uxs-card">
					<span className="uxs-card__label">{ t( 'Failed logins (24 h)' ) }</span>
					<span className="uxs-card__num">{ o.login_stats.fail + o.login_stats.lockout }</span>
				</div>
				<div className="uxs-card">
					<span className="uxs-card__label">{ t( 'Lock-outs now' ) }</span>
					<span className="uxs-card__num">{ o.active_locks.length }</span>
				</div>
				<div className="uxs-card">
					<span className="uxs-card__label">{ t( 'CSP reports collected' ) }</span>
					<span className="uxs-card__num">{ o.csp_reports }</span>
				</div>
			</div>
			<ul className="uxs-status-list">
				<li className="uxs-status-list__row">
					<strong>{ t( 'Your IP address' ) }</strong> <code>{ o.client_ip || '-' }</code>
					<span className="uxs-muted">{ modeLabel[ o.ip_mode ] ?? o.ip_mode }{ o.remote_addr && o.remote_addr !== o.client_ip ? ` (${ t( 'connection from' ) } ${ o.remote_addr })` : '' }</span>
				</li>
				<li className="uxs-status-list__row"><strong>{ t( 'IP firewall' ) }</strong> <Flag on={ o.firewall } /></li>
				<li className="uxs-status-list__row"><strong>{ t( 'Security headers' ) }</strong> <Flag on={ o.headers } /> <span className="uxs-muted">HSTS: { o.hsts ? t( 'On' ) : t( 'Off' ) }</span></li>
				<li className="uxs-status-list__row"><strong>{ t( 'Version information hidden' ) }</strong> <Flag on={ o.generator_hidden } /></li>
				<li className="uxs-status-list__row"><strong>{ t( 'Content-Security-Policy' ) }</strong> <span className={ `uxs-badge ${ o.csp_mode === 'enforce' ? 'is-success' : 'is-warning' }` }>{ cspLabel[ o.csp_mode ] ?? o.csp_mode }</span></li>
				<li className="uxs-status-list__row"><strong>{ t( 'Login attempts limited' ) }</strong> <Flag on={ o.login_limit } /></li>
				<li className="uxs-status-list__row"><strong>{ t( 'Turnstile on the login' ) }</strong> <Flag on={ o.turnstile } /></li>
				<li className="uxs-status-list__row">
					<strong>{ t( 'Secret administrator URL' ) }</strong> <Flag on={ o.secret_valid } />
					{ o.secret_url ? <code>{ o.secret_url }</code> : null }
				</li>
			</ul>
			<div className="uxs-actions" style={ { marginTop: 'var(--uxs-sp-4)' } }>
				<button type="button" className="uxs-btn" onClick={ () => void q.refetch() } disabled={ q.isFetching }>
					<RefreshCw size={ 14 } aria-hidden="true" /> { t( 'Refresh' ) }
				</button>
				<button type="button" className="uxs-btn" onClick={ () => onGo( 'hardening' ) }>{ t( 'Open the hardening report' ) }</button>
			</div>
		</>
	);
}

/* ---------------------------------------------------------------- firewall */

type BanDraft = { value: string; label: string; note: string; expires: string };

function BanModal( { onClose }: { onClose: () => void } ): JSX.Element {
	const [ d, setD ] = useState< BanDraft >( { value: '', label: '', note: '', expires: '' } );
	const [ error, setError ] = useState( '' );
	const save = useMutation( {
		mutationFn: () => api.post( 'security-optimization/ip-bans', { value: d.value.trim(), label: d.label, note: d.note, expires_at: d.expires || null } ),
		onSuccess: () => {
			void queryClient.invalidateQueries( { queryKey: [ 'secopt' ] } );
			pushToast( 'success', t( 'Ban saved.' ) );
			onClose();
		},
		onError: ( e ) => setError( errorMessage( e ) ),
	} );
	return (
		<Modal
			open
			onClose={ onClose }
			title={ t( 'Ban an IP address or range' ) }
			actions={
				<>
					<button type="button" className="uxs-btn" onClick={ onClose }>{ t( 'Cancel' ) }</button>
					<button type="button" className="uxs-btn uxs-btn--primary" disabled={ ! d.value.trim() || save.isPending } onClick={ () => save.mutate() }>{ t( 'Ban' ) }</button>
				</>
			}
		>
			<div className="uxs-modal__body">
				{ error ? <div className="uxs-notice is-error" role="alert">{ error }</div> : null }
				<div className="uxs-form__row">
					<label htmlFor="uxs-so-ban-value">{ t( 'IP address or CIDR range' ) }</label>
					<input id="uxs-so-ban-value" type="text" value={ d.value } placeholder="203.0.113.0/24" onChange={ ( e ) => setD( { ...d, value: e.target.value } ) } />
					<p className="uxs-form__help">{ t( 'IPv4 or IPv6, a single address or a range such as 203.0.113.0/24. Your own address is refused.' ) }</p>
				</div>
				<div className="uxs-form__row">
					<label htmlFor="uxs-so-ban-label">{ t( 'Label' ) }</label>
					<input id="uxs-so-ban-label" type="text" value={ d.label } maxLength={ 190 } onChange={ ( e ) => setD( { ...d, label: e.target.value } ) } />
				</div>
				<div className="uxs-form__row">
					<label htmlFor="uxs-so-ban-note">{ t( 'Note' ) }</label>
					<input id="uxs-so-ban-note" type="text" value={ d.note } maxLength={ 1000 } onChange={ ( e ) => setD( { ...d, note: e.target.value } ) } />
				</div>
				<div className="uxs-form__row">
					<label htmlFor="uxs-so-ban-exp">{ t( 'Expires' ) }</label>
					<input id="uxs-so-ban-exp" type="datetime-local" value={ d.expires } onChange={ ( e ) => setD( { ...d, expires: e.target.value } ) } />
					<p className="uxs-form__help">{ t( 'Empty = never. The time is UTC.' ) }</p>
				</div>
			</div>
		</Modal>
	);
}

function FirewallTab(): JSX.Element {
	const [ page, setPage ] = useState( 1 );
	const [ search, setSearch ] = useState( '' );
	const [ origin, setOrigin ] = useState( '' );
	const [ adding, setAdding ] = useState( false );
	const q = useQuery( {
		queryKey: [ 'secopt', 'bans', page, search, origin ],
		queryFn: () => api.get< BanList >( 'security-optimization/ip-bans', { page, search, origin } ),
	} );
	const refresh = (): void => void queryClient.invalidateQueries( { queryKey: [ 'secopt' ] } );
	const toggle = useMutation( {
		mutationFn: ( b: Ban ) => api.post( `security-optimization/ip-bans/${ b.id }/toggle`, { active: ! b.is_active } ),
		onSuccess: refresh,
		onError: ( e ) => pushToast( 'error', errorMessage( e ) ),
	} );
	const remove = useMutation( {
		mutationFn: ( b: Ban ) => api.post( `security-optimization/ip-bans/${ b.id }/delete`, {} ),
		onSuccess: refresh,
		onError: ( e ) => pushToast( 'error', errorMessage( e ) ),
	} );

	const columns: Column< Ban >[] = [
		{ key: 'value', header: t( 'Address' ), render: ( b ) => <code>{ b.value }</code> },
		{ key: 'label', header: t( 'Label' ), render: ( b ) => b.label || b.note || '' },
		{
			key: 'origin',
			header: t( 'Origin' ),
			render: ( b ) => <span className={ `uxs-badge ${ b.origin === 'central' ? 'is-info' : b.origin === 'auto' ? 'is-warning' : '' }` }>{ b.origin === 'central' ? t( 'Central App' ) : b.origin === 'auto' ? t( 'Automatic' ) : t( 'Manual' ) }</span>,
		},
		{ key: 'expires_at', header: t( 'Expires' ), render: ( b ) => ( b.expires_at ? <span className={ b.expired ? 'uxs-muted' : '' }>{ formatDate( b.expires_at ) }</span> : t( 'Never' ) ) },
		{ key: 'hit_count', header: t( 'Blocked requests' ), render: ( b ) => b.hit_count },
		{
			key: 'is_active',
			header: t( 'Active' ),
			render: ( b ) => ( b.origin === 'central' ? <Flag on={ b.is_active } /> : <ToggleSwitch checked={ b.is_active } onChange={ () => toggle.mutate( b ) } label={ t( 'Active' ) } /> ),
		},
		{
			key: 'actions',
			header: '',
			render: ( b ) =>
				b.origin === 'central' ? null : (
					<button
						type="button"
						className="uxs-btn uxs-btn--small uxs-btn--danger"
						aria-label={ t( 'Delete the ban of {value}', { value: b.value } ) }
						onClick={ () => {
							void confirm( { message: t( 'Delete the ban of {value}?', { value: b.value } ), danger: true, confirmLabel: t( 'Delete' ) } ).then( ( ok ) => ok && remove.mutate( b ) );
						} }
					>
						<Trash2 size={ 14 } aria-hidden="true" />
					</button>
				),
		},
	];

	return (
		<>
			<div className="uxs-actions uxs-list-toolbar">
				<input type="search" className="uxs-input" value={ search } placeholder={ t( 'Search address, label, note' ) } aria-label={ t( 'Search' ) } onChange={ ( e ) => { setSearch( e.target.value ); setPage( 1 ); } } />
				<select className="uxs-input" value={ origin } aria-label={ t( 'Origin' ) } onChange={ ( e ) => { setOrigin( e.target.value ); setPage( 1 ); } }>
					<option value="">{ t( 'All origins' ) }</option>
					<option value="local">{ t( 'Local (manual and automatic)' ) }</option>
					<option value="central">{ t( 'Central App' ) }</option>
				</select>
				<button type="button" className="uxs-btn uxs-btn--primary" onClick={ () => setAdding( true ) }>
					<Plus size={ 14 } aria-hidden="true" /> { t( 'Add ban' ) }
				</button>
			</div>
			<p className="uxs-muted">{ t( 'Bans pushed by the Central App are replaced as a whole on every sync and cannot be edited here. The firewall never blocks loopback, allowlisted addresses or a logged-in super user.' ) }</p>
			{ q.isLoading ? <Loading /> : null }
			{ q.error ? <p role="alert">{ errorMessage( q.error ) }</p> : null }
			{ q.data ? <DataTable columns={ columns } rows={ q.data.items } rowKey={ ( b ) => b.id } empty={ t( 'No IP bans.' ) } page={ q.data.page } pages={ q.data.pages } onPage={ setPage } /> : null }
			{ adding ? <BanModal onClose={ () => setAdding( false ) } /> : null }
		</>
	);
}

/* ------------------------------------------------------------------- login */

function LoginTab(): JSX.Element {
	const [ kind, setKind ] = useState( '' );
	const q = useQuery( {
		queryKey: [ 'secopt', 'login', kind ],
		queryFn: () => api.get< LoginLog >( 'security-optimization/login-log', { kind, limit: 100 } ),
	} );
	const unlock = useMutation( {
		mutationFn: ( ip: string ) => api.post( 'security-optimization/login/unlock', { ip } ),
		onSuccess: () => {
			void queryClient.invalidateQueries( { queryKey: [ 'secopt' ] } );
			pushToast( 'success', t( 'Address unlocked.' ) );
		},
		onError: ( e ) => pushToast( 'error', errorMessage( e ) ),
	} );
	if ( q.isLoading ) {
		return <Loading />;
	}
	if ( q.error || ! q.data ) {
		return <p role="alert">{ errorMessage( q.error ) }</p>;
	}
	const kinds: Record< string, string > = {
		fail: t( 'Failed login' ),
		lockout: t( 'Lock-out' ),
		blocked: t( 'Blocked while locked out' ),
		captcha: t( 'Turnstile refused' ),
		success: t( 'Successful login' ),
		secret: t( 'Missing secret URL' ),
	};
	const tone: Record< string, string > = { fail: 'is-warning', lockout: 'is-danger', blocked: 'is-danger', captcha: 'is-warning', success: 'is-success', secret: 'is-info' };
	const lockCols: Column< Lock >[] = [
		{ key: 'ip', header: t( 'Address' ), render: ( l ) => <code>{ l.ip }</code> },
		{ key: 'locked_until', header: t( 'Locked until' ), render: ( l ) => formatDate( l.locked_until ) },
		{ key: 'strikes', header: t( 'Lock-outs in 24 h' ) },
		{
			key: 'actions',
			header: '',
			render: ( l ) => (
				<button type="button" className="uxs-btn uxs-btn--small" onClick={ () => unlock.mutate( l.ip ) } disabled={ unlock.isPending }>
					<Unlock size={ 14 } aria-hidden="true" /> { t( 'Unlock' ) }
				</button>
			),
		},
	];
	const logCols: Column< LogRow >[] = [
		{ key: 'created', header: t( 'Time' ), sortable: true, render: ( r ) => formatDate( r.created ) },
		{ key: 'kind', header: t( 'Event' ), render: ( r ) => <span className={ `uxs-badge ${ tone[ r.kind ] ?? '' }` }>{ kinds[ r.kind ] ?? r.kind }</span> },
		{ key: 'ip', header: t( 'Address' ), render: ( r ) => <code>{ r.ip }</code> },
		{ key: 'username', header: t( 'User name' ) },
	];

	return (
		<>
			<div className="uxs-cards">
				{ ( [ 'fail', 'lockout', 'blocked', 'captcha' ] as const ).map( ( k ) => (
					<div className="uxs-card" key={ k }>
						<span className="uxs-card__label">{ kinds[ k ] } (24 h)</span>
						<span className="uxs-card__num">{ q.data.stats[ k ] }</span>
					</div>
				) ) }
			</div>
			<h2 className="uxs-form__section">{ t( 'Locked out now' ) }</h2>
			<DataTable columns={ lockCols } rows={ q.data.locks } rowKey={ ( l ) => l.ip } empty={ t( 'Nobody is locked out.' ) } />
			<h2 className="uxs-form__section">{ t( 'Login log' ) }</h2>
			<div className="uxs-actions uxs-list-toolbar">
				<select className="uxs-input" value={ kind } aria-label={ t( 'Event' ) } onChange={ ( e ) => setKind( e.target.value ) }>
					<option value="">{ t( 'All events' ) }</option>
					{ Object.entries( kinds ).map( ( [ k, label ] ) => <option key={ k } value={ k }>{ label }</option> ) }
				</select>
			</div>
			<DataTable columns={ logCols } rows={ q.data.items } rowKey={ ( r ) => r.id } empty={ t( 'No events yet.' ) } pageSize={ 20 } />
			<p className="uxs-muted">{ t( 'The log keeps 30 days. Addresses are stored as they are so you can recognise a visitor; this is a security log.' ) }</p>
		</>
	);
}

/* --------------------------------------------------------------------- CSP */

function CspTab( { onGo }: { onGo: ( tab: string ) => void } ): JSX.Element {
	const q = useQuery( { queryKey: [ 'secopt', 'csp' ], queryFn: () => api.get< Csp >( 'security-optimization/csp' ) } );
	const clear = useMutation( {
		mutationFn: () => api.post( 'security-optimization/csp/clear', {} ),
		onSuccess: () => void queryClient.invalidateQueries( { queryKey: [ 'secopt' ] } ),
		onError: ( e ) => pushToast( 'error', errorMessage( e ) ),
	} );
	if ( q.isLoading ) {
		return <Loading />;
	}
	if ( q.error || ! q.data ) {
		return <p role="alert">{ errorMessage( q.error ) }</p>;
	}
	const cols: Column< Violation >[] = [
		{ key: 'directive', header: t( 'Directive' ), render: ( v ) => <code>{ v.directive }</code> },
		{ key: 'blocked', header: t( 'Blocked source' ), render: ( v ) => <code>{ v.blocked }</code> },
		{ key: 'hits', header: t( 'Reports' ), sortable: true },
		{ key: 'sample', header: t( 'Example page' ), render: ( v ) => v.sample || '' },
		{ key: 'last_seen', header: t( 'Last seen' ), sortable: true, render: ( v ) => formatDate( v.last_seen ) },
	];
	const label: Record< string, string > = { off: t( 'Off' ), 'report-only': t( 'Report only (nothing is blocked)' ), enforce: t( 'Enforced' ) };

	return (
		<>
			<p>
				<strong>{ t( 'Mode' ) }:</strong> <span className={ `uxs-badge ${ q.data.mode === 'enforce' ? 'is-success' : 'is-warning' }` }>{ label[ q.data.mode ] ?? q.data.mode }</span>{ ' ' }
				<button type="button" className="uxs-btn uxs-btn--small" onClick={ () => onGo( 'settings' ) }>{ t( 'Change in settings' ) }</button>
			</p>
			<h2 className="uxs-form__section">{ t( 'Policy that is sent' ) }</h2>
			<pre className="uxs-code" style={ { whiteSpace: 'pre-wrap', wordBreak: 'break-word' } }>{ q.data.policy.split( '; ' ).join( ';\n' ) }</pre>
			<p className="uxs-muted">{ t( 'NONCE stands for the random value generated for every page view. CSP is sent on the public site only, never in the administrator.' ) }</p>
			<h2 className="uxs-form__section">{ t( 'What browsers reported' ) }</h2>
			<p className="uxs-muted">{ t( 'In Report only mode every blocked source is listed here. Add the ones you need to Allowed services or Custom allowed sources before you switch to Enforce.' ) }</p>
			<div className="uxs-actions" style={ { marginBottom: 'var(--uxs-sp-3)' } }>
				<button
					type="button"
					className="uxs-btn"
					disabled={ q.data.violations.length === 0 || clear.isPending }
					onClick={ () => {
						void confirm( { message: t( 'Delete all collected reports?' ), danger: true, confirmLabel: t( 'Delete' ) } ).then( ( ok ) => ok && clear.mutate() );
					} }
				>
					<Trash2 size={ 14 } aria-hidden="true" /> { t( 'Clear reports' ) }
				</button>
			</div>
			<DataTable columns={ cols } rows={ q.data.violations } rowKey={ ( v ) => v.id } empty={ t( 'No reports yet.' ) } pageSize={ 25 } />
		</>
	);
}

/* --------------------------------------------------------------- hardening */

function HardeningTab(): JSX.Element {
	const q = useQuery( { queryKey: [ 'secopt', 'hardening' ], queryFn: () => api.get< Hardening >( 'security-optimization/hardening' ) } );
	const probe = useMutation( { mutationFn: () => api.get< Probe >( 'security-optimization/hardening/probe' ), onError: ( e ) => pushToast( 'error', errorMessage( e ) ) } );
	if ( q.isLoading ) {
		return <Loading />;
	}
	if ( q.error || ! q.data ) {
		return <p role="alert">{ errorMessage( q.error ) }</p>;
	}
	const h = q.data;
	const copy = (): void => {
		void navigator.clipboard.writeText( h.htaccess ).then( () => pushToast( 'success', t( 'Copied.' ) ) );
	};

	return (
		<>
			<p className="uxs-muted">{ t( 'This report only looks. UX Studio never edits your files, .htaccess or configuration.' ) }</p>
			<ul className="uxs-status-list">
				{ h.checks.map( ( c ) => (
					<li key={ c.id } className="uxs-status-list__row">
						<span className={ `uxs-badge ${ BADGE[ c.status ] }` }>{ c.status === 'ok' ? t( 'OK' ) : c.status === 'warn' ? t( 'Check' ) : t( 'Problem' ) }</span>
						<strong>{ c.label }</strong>
						{ c.detail ? <span className="uxs-muted">{ c.detail }</span> : null }
					</li>
				) ) }
			</ul>

			<h2 className="uxs-form__section">{ t( 'Files that reveal the CMS or its version' ) }</h2>
			{ h.leaky_files.length === 0 ? (
				<p>{ t( 'None of the usual files are present.' ) }</p>
			) : (
				<>
					<p className="uxs-muted">{ t( 'These are static files, so the web server serves them before Joomla runs. Remove them or deny access with the rules below, after you have checked them.' ) }</p>
					<ul>{ h.leaky_files.map( ( f ) => <li key={ f }><code>{ f }</code></li> ) }</ul>
					<div className="uxs-actions">
						<button type="button" className="uxs-btn" disabled={ probe.isPending } onClick={ () => probe.mutate() }>
							<RefreshCw size={ 14 } aria-hidden="true" /> { t( 'Test what the web server serves' ) }
						</button>
					</div>
					{ probe.data ? (
						<ul className="uxs-status-list" style={ { marginTop: 'var(--uxs-sp-3)' } }>
							{ probe.data.files.map( ( f ) => (
								<li key={ f.file } className="uxs-status-list__row">
									<span className={ `uxs-badge ${ f.exposed ? 'is-danger' : f.status === 0 ? 'is-warning' : 'is-success' }` }>{ f.status === 0 ? t( 'Unknown' ) : f.exposed ? t( 'Public' ) : `HTTP ${ f.status }` }</span> <code>{ f.file }</code>
								</li>
							) ) }
						</ul>
					) : null }
				</>
			) }

			<h2 className="uxs-form__section">{ t( 'Recommended .htaccess rules (Apache)' ) }</h2>
			<p className="uxs-muted">{ t( 'Review them and paste them into your .htaccess yourself. On nginx use the same ideas in the server configuration.' ) }</p>
			<pre className="uxs-code" style={ { whiteSpace: 'pre-wrap', wordBreak: 'break-word' } }>{ h.htaccess }</pre>
			<div className="uxs-actions">
				<button type="button" className="uxs-btn" onClick={ copy }>
					<Copy size={ 14 } aria-hidden="true" /> { t( 'Copy' ) }
				</button>
			</div>

			<h2 className="uxs-form__section">{ t( 'Super users' ) }</h2>
			<DataTable
				columns={ [
					{ key: 'username', header: t( 'User name' ) },
					{ key: 'mfa', header: t( 'Multi-factor authentication' ), render: ( u ) => <Flag on={ Boolean( u.mfa ) } yes={ t( 'Enabled' ) } no={ t( 'Not set up' ) } /> },
					{ key: 'lastvisit', header: t( 'Last visit' ), render: ( u ) => ( u.lastvisit ? formatDate( String( u.lastvisit ) ) : t( 'Never' ) ) },
				] }
				rows={ h.super_users }
				rowKey={ ( u ) => u.id }
				empty={ t( 'No super users.' ) }
			/>
		</>
	);
}

/* -------------------------------------------------------------------- page */

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
					{ id: 'firewall', label: t( 'IP bans' ) },
					{ id: 'login', label: t( 'Login' ) },
					{ id: 'csp', label: t( 'CSP' ) },
					{ id: 'hardening', label: t( 'Hardening' ) },
					{ id: 'settings', label: t( 'Settings' ) },
				] }
			/>
			<TabPanel id={ tab }>
				{ tab === 'overview' ? <OverviewTab onGo={ setTab } /> : null }
				{ tab === 'firewall' ? <FirewallTab /> : null }
				{ tab === 'login' ? <LoginTab /> : null }
				{ tab === 'csp' ? <CspTab onGo={ setTab } /> : null }
				{ tab === 'hardening' ? <HardeningTab /> : null }
				{ tab === 'settings' ? <SettingsPanel moduleId={ module.id } /> : null }
			</TabPanel>
		</>
	);
}
