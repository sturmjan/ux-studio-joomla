/**
 * SMTP e-mail: overview of the connection, a test send (also through one chosen connection) and the settings.
 */
import { useState } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import { BadgeCheck, Send } from 'lucide-react';
import { api, queryClient, type ModuleInfo } from '../../app/api';
import { createT } from '../../app/i18n';
import { SettingsPanel } from '../../app/SettingsForm';
import { Loading, TabPanel, Tabs, pushToast } from '../../app/ui';

type Connection = 'smtp' | 'brevo' | 'joomla';

interface Status {
	mailer: Connection;
	fallback: Connection | '';
	ready: { smtp: boolean; brevo: boolean };
	host: string;
	from_email: string;
	joomla_mailer: string;
	logging: boolean;
	health: boolean;
	mail_online: boolean;
}

interface Attempt {
	mailer: string;
	kind: string;
	ok: boolean;
	error: string;
}

interface TestResult {
	success: boolean;
	to: string;
	error: string;
	mailer: string;
	attempts: Attempt[];
}

interface BrevoCheck {
	ok: boolean;
	account: string;
	error: string;
}

const { t } = createT( 'smtp-email' );

function label( id: string ): string {
	switch ( id ) {
		case 'smtp':
			return t( 'SMTP server' );
		case 'brevo':
			return t( 'Brevo API' );
		case 'joomla':
			return t( 'Joomla default' );
		case '':
			return t( 'None' );
		default:
			return id.startsWith( 'joomla/' ) ? t( 'Joomla default' ) + ' (' + id.slice( 7 ) + ')' : id;
	}
}

function kindLabel( kind: string ): string {
	return kind === 'primary' ? t( 'primary' ) : kind === 'fallback' ? t( 'fallback' ) : t( 'test' );
}

function Overview( { status }: { status: Status } ): JSX.Element {
	const [ to, setTo ] = useState( '' );
	const [ via, setVia ] = useState( '' );
	const [ result, setResult ] = useState< TestResult | null >( null );
	const [ brevo, setBrevo ] = useState< BrevoCheck | null >( null );

	const test = useMutation( {
		mutationFn: () => api.post< TestResult >( 'smtp-email/test', { to: to.trim(), mailer: via } ),
		onSuccess: ( r ) => {
			setResult( r );
			void queryClient.invalidateQueries( { queryKey: [ 'email-log' ] } );
		},
		onError: ( e: Error ) => pushToast( 'error', e.message ),
	} );
	const verify = useMutation( {
		mutationFn: () => api.post< BrevoCheck >( 'smtp-email/brevo/verify' ),
		onSuccess: setBrevo,
		onError: ( e: Error ) => pushToast( 'error', e.message ),
	} );

	const setUp = ( id: Connection ): boolean => id === 'joomla' || status.ready[ id ];

	return (
		<>
			<div className="uxs-cards">
				<div className="uxs-card">
					<span className="uxs-card__label">{ t( 'Connection' ) }</span>
					<span className="uxs-card__num">{ label( status.mailer ) }</span>
					<span className={ `uxs-badge ${ setUp( status.mailer ) ? 'is-success' : 'is-warning' }` }>{ setUp( status.mailer ) ? t( 'Set up' ) : t( 'Not set up' ) }</span>
				</div>
				<div className="uxs-card">
					<span className="uxs-card__label">{ t( 'Fallback connection' ) }</span>
					<span className="uxs-card__num">{ label( status.fallback ) }</span>
					{ status.fallback !== '' ? <span className={ `uxs-badge ${ setUp( status.fallback ) ? 'is-success' : 'is-warning' }` }>{ setUp( status.fallback ) ? t( 'Set up' ) : t( 'Not set up' ) }</span> : null }
				</div>
				<div className="uxs-card">
					<span className="uxs-card__label">{ t( 'From e-mail' ) }</span>
					<span className="uxs-card__num">{ status.from_email || t( 'From Joomla configuration' ) }</span>
				</div>
			</div>

			{ ! status.mail_online ? (
				<div className="uxs-notice is-error">
					<p>{ t( 'Sending mail is switched off in the Joomla global configuration ("Send Mail: No"). Nothing is sent until you turn it on.' ) }</p>
				</div>
			) : null }
			{ ! setUp( status.mailer ) ? (
				<div className="uxs-notice">
					<p>{ t( 'The chosen connection is not set up yet, so Joomla sends mail as configured in its global configuration ({mailer}). Fill in the connection on the Settings tab.', { mailer: status.joomla_mailer } ) }</p>
				</div>
			) : null }
			{ ! status.logging ? (
				<p className="uxs-muted">{ t( 'Turn on the "E-mail log" module to keep a record of every message and of the attempts through each connection.' ) }</p>
			) : null }

			<h2 className="uxs-section-title">{ t( 'Send a test e-mail' ) }</h2>
			<form
				className="uxs-form uxs-form--grid"
				onSubmit={ ( e ) => {
					e.preventDefault();
					test.mutate();
				} }
			>
				<div className="uxs-form__row">
					<label htmlFor="uxs-smtp-test-to">{ t( 'Recipient' ) }</label>
					<input id="uxs-smtp-test-to" type="email" value={ to } placeholder={ t( 'Leave blank to use your own address' ) } onChange={ ( e ) => setTo( e.target.value ) } />
				</div>
				<div className="uxs-form__row">
					<label htmlFor="uxs-smtp-test-via">{ t( 'Send through' ) }</label>
					<select id="uxs-smtp-test-via" value={ via } onChange={ ( e ) => setVia( e.target.value ) }>
						<option value="">{ t( 'As configured (with fallback)' ) }</option>
						<option value="smtp">{ t( 'SMTP server only' ) }</option>
						<option value="brevo">{ t( 'Brevo API only' ) }</option>
						<option value="joomla">{ t( 'Joomla default only' ) }</option>
					</select>
					<p className="uxs-form__help">{ t( 'Testing one connection uses the saved settings and never the fallback.' ) }</p>
				</div>
			</form>
			<div className="uxs-actions" style={ { marginBottom: 'var(--uxs-sp-4)' } }>
				<button type="button" className="uxs-btn uxs-btn--primary" disabled={ test.isPending } onClick={ () => test.mutate() }>
					<Send size={ 14 } aria-hidden="true" /> { test.isPending ? t( 'Sending…' ) : t( 'Send test e-mail' ) }
				</button>
				{ status.ready.brevo ? (
					<button type="button" className="uxs-btn" disabled={ verify.isPending } onClick={ () => verify.mutate() }>
						<BadgeCheck size={ 14 } aria-hidden="true" /> { t( 'Verify Brevo key' ) }
					</button>
				) : null }
				{ brevo ? (
					<span className={ `uxs-badge ${ brevo.ok ? 'is-success' : 'is-danger' }` }>
						{ brevo.ok ? t( 'Brevo key works ({account})', { account: brevo.account } ) : t( 'Brevo key rejected: {error}', { error: brevo.error } ) }
					</span>
				) : null }
			</div>

			{ result ? (
				<div className={ `uxs-notice${ result.success ? '' : ' is-error' }` } role="status">
					<p>
						<strong>{ result.success ? t( 'Sent to {to} through {mailer}.', { to: result.to, mailer: label( result.mailer ) } ) : t( 'Sending to {to} failed.', { to: result.to } ) }</strong>
					</p>
					{ ! result.success && result.error ? <p>{ result.error }</p> : null }
					{ result.attempts.length > 0 ? (
						<ul className="uxs-status-list">
							{ result.attempts.map( ( a, i ) => (
								<li key={ i } className="uxs-status-list__row">
									<span>{ label( a.mailer ) } ({ kindLabel( a.kind ) })</span>
									<span className={ `uxs-badge ${ a.ok ? 'is-success' : 'is-danger' }` }>{ a.ok ? t( 'Accepted' ) : t( 'Failed' ) }</span>
									{ a.error ? <span className="uxs-muted">{ a.error }</span> : null }
								</li>
							) ) }
						</ul>
					) : null }
				</div>
			) : null }
		</>
	);
}

export default function Page( { module }: { module: ModuleInfo } ): JSX.Element {
	const [ tab, setTab ] = useState( 'overview' );
	const status = useQuery( { queryKey: [ 'smtp-email', 'status', tab ], queryFn: () => api.get< Status >( 'smtp-email/status' ) } );

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
			{ tab === 'overview' ? (
				<TabPanel id="overview">
					{ status.isLoading ? <Loading /> : null }
					{ status.error ? <p role="alert">{ status.error.message }</p> : null }
					{ status.data ? <Overview status={ status.data } /> : null }
				</TabPanel>
			) : (
				<TabPanel id="settings">
					<SettingsPanel moduleId={ module.id } />
				</TabPanel>
			) }
		</>
	);
}
