/**
 * Google Review Request: send review request e-mails (one, a pasted list, or a test to yourself), log of everything
 * sent, and the settings (link, message, rules, signed endpoint for shops / booking systems).
 */
import { useState } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import { Eye, RefreshCw, Send, SendHorizontal } from 'lucide-react';
import { api, queryClient, type ModuleInfo } from '../../app/api';
import { createT, formatDate } from '../../app/i18n';
import { SettingsPanel } from '../../app/SettingsForm';
import { DataTable, Loading, Modal, TabPanel, Tabs, pushToast, type Column } from '../../app/ui';

type Result = { email: string; status: string; reason: string };

interface SendResponse {
	results: Result[];
	sent: number;
	failed: number;
	skipped: number;
}

interface Stats {
	sent_30d: number;
	failed_30d: number;
	skipped_30d: number;
	total: number;
	sent_today: number;
	daily_limit: number;
	link: string;
	api_enabled: boolean;
	api_url: string;
	batch_max: number;
	mail_on: boolean;
	mailer: string;
}

type LogRow = { id: number; created: string; email: string; name: string; status: string; reason: string; channel: string; ref: string };

interface LogPage {
	items: LogRow[];
	total: number;
	page: number;
	pages: number;
}

interface Preview {
	subject: string;
	body: string;
	link: string;
}

const { t, tn } = createT( 'google-review-request' );
const PER_PAGE = 20;

function StatusBadge( { status }: { status: string } ): JSX.Element {
	const cls = status === 'sent' ? 'is-success' : status === 'failed' ? 'is-danger' : 'is-warning';
	const label = status === 'sent' ? t( 'Sent' ) : status === 'failed' ? t( 'Failed' ) : t( 'Skipped' );
	return <span className={ `uxs-badge ${ cls }` }>{ label }</span>;
}

function reasonLabel( reason: string ): string {
	const labels: Record< string, string > = {
		invalid_email: t( 'Not a valid e-mail address' ),
		no_link: t( 'The review link is not set' ),
		blocked: t( 'On the "never send to" list' ),
		cooldown: t( 'Asked recently (cool-down)' ),
		daily_limit: t( 'Daily limit reached' ),
		duplicate_in_list: t( 'Listed twice' ),
		empty_message: t( 'The message is empty' ),
		unsubscribed: t( 'The customer unsubscribed' ),
		hourly_cap: t( 'Hourly safety cap reached' ),
	};
	return labels[ reason ] ?? reason;
}

function channelLabel( channel: string ): string {
	const labels: Record< string, string > = { manual: t( 'One address' ), bulk: t( 'List' ), api: t( 'Automatic call' ), test: t( 'Test' ) };
	return labels[ channel ] ?? channel;
}

/** One line = "email; name; reference" (comma, semicolon or tab separated). */
function parseRecipients( text: string ): Array< { email: string; name: string; ref: string } > {
	return text
		.split( /\r?\n/ )
		.map( ( line ) => line.trim() )
		.filter( ( line ) => line !== '' )
		.map( ( line ) => {
			const [ email = '', name = '', ref = '' ] = line.split( /[;,\t]/ ).map( ( p ) => p.trim() );
			return { email, name, ref };
		} );
}

function SendTab( { stats }: { stats: Stats | undefined } ): JSX.Element {
	const [ text, setText ] = useState( '' );
	const [ force, setForce ] = useState( false );
	const [ results, setResults ] = useState< Result[] >( [] );
	const [ progress, setProgress ] = useState< { done: number; total: number } | null >( null );
	const [ preview, setPreview ] = useState< Preview | null >( null );

	const refresh = (): void => void queryClient.invalidateQueries( { queryKey: [ 'google-review-request' ] } );
	const recipients = parseRecipients( text );
	const batch = stats?.batch_max ?? 25;

	const send = useMutation( {
		mutationFn: async () => {
			const all: Result[] = [];
			setResults( [] );
			setProgress( { done: 0, total: recipients.length } );
			for ( let i = 0; i < recipients.length; i += batch ) {
				const r = await api.post< SendResponse >( 'google-review-request/send', { recipients: recipients.slice( i, i + batch ), force } );
				all.push( ...r.results );
				setResults( [ ...all ] );
				setProgress( { done: Math.min( recipients.length, i + batch ), total: recipients.length } );
			}
			return all;
		},
		onSuccess: ( all ) => {
			const sent = all.filter( ( r ) => r.status === 'sent' ).length;
			pushToast( sent > 0 ? 'success' : 'error', t( '{sent} of {total} sent.', { sent, total: all.length } ) );
			if ( sent === all.length ) {
				setText( '' );
			}
			refresh();
		},
		onError: ( e: Error ) => {
			pushToast( 'error', e.message );
			refresh();
		},
		onSettled: () => setProgress( null ),
	} );

	const test = useMutation( {
		mutationFn: () => api.post< SendResponse >( 'google-review-request/test' ),
		onSuccess: ( r ) => {
			const res = r.results[ 0 ];
			pushToast( res?.status === 'sent' ? 'success' : 'error', res?.status === 'sent' ? t( 'Test sent to {email}.', { email: res.email } ) : t( 'The test was not sent: {reason}', { reason: res ? reasonLabel( res.reason ) : '' } ) );
			refresh();
		},
		onError: ( e: Error ) => pushToast( 'error', e.message ),
	} );

	const show = useMutation( {
		mutationFn: () => api.post< Preview >( 'google-review-request/preview', { name: recipients[ 0 ]?.name ?? '' } ),
		onSuccess: setPreview,
		onError: ( e: Error ) => pushToast( 'error', e.message ),
	} );

	const columns: Column< Result >[] = [
		{ key: 'email', header: t( 'E-mail' ) },
		{ key: 'status', header: t( 'Result' ), render: ( r ) => <StatusBadge status={ r.status } /> },
		{ key: 'reason', header: t( 'Detail' ), render: ( r ) => ( r.reason ? reasonLabel( r.reason ) : '' ) },
	];

	return (
		<>
			{ stats ? (
				<>
					<div className="uxs-cards">
						<div className="uxs-card">
							<span className="uxs-card__label">{ t( 'Sent in 30 days' ) }</span>
							<span className="uxs-card__num">{ stats.sent_30d }</span>
						</div>
						<div className="uxs-card">
							<span className="uxs-card__label">{ t( 'Failed in 30 days' ) }</span>
							<span className="uxs-card__num">{ stats.failed_30d }</span>
						</div>
						<div className="uxs-card">
							<span className="uxs-card__label">{ t( 'Skipped in 30 days' ) }</span>
							<span className="uxs-card__num">{ stats.skipped_30d }</span>
						</div>
						<div className="uxs-card">
							<span className="uxs-card__label">{ t( 'Last 24 hours' ) }</span>
							<span className="uxs-card__num">{ stats.daily_limit > 0 ? `${ stats.sent_today } / ${ stats.daily_limit }` : stats.sent_today }</span>
						</div>
					</div>
					{ stats.link === '' ? (
						<div className="uxs-notice" role="alert">
							{ t( 'The review link is not set, so nothing can be sent. Fill in "Google review link or Place ID" in the Settings tab.' ) }
						</div>
					) : null }
					{ ! stats.mail_on ? (
						<div className="uxs-notice is-error" role="alert">
							{ t( 'Sending e-mail is switched off in Global Configuration (Send Mail = No).' ) }
						</div>
					) : null }
				</>
			) : null }

			<h3 className="uxs-section-title">{ t( 'Ask for a review' ) }</h3>
			<div className="uxs-form">
				<div className="uxs-form__row">
					<label htmlFor="grr-list">{ t( 'Recipients' ) }</label>
					<textarea
						id="grr-list"
						className="uxs-input"
						rows={ 6 }
						value={ text }
						spellCheck={ false }
						placeholder={ 'jana@example.com; Jana Nováková; objednavka-1234\npetr@example.com; Petr' }
						onChange={ ( e ) => setText( e.target.value ) }
					/>
					<p className="uxs-form__help">{ t( 'One customer per line: e-mail; name (optional); reference such as an order number (optional, only for the log). The e-mails are sent immediately, so check the addresses.' ) }</p>
				</div>
				<div className="uxs-form__row">
					<label className="uxs-form__line" htmlFor="grr-force">
						<input id="grr-force" type="checkbox" checked={ force } onChange={ ( e ) => setForce( e.target.checked ) } /> { t( 'Ignore the cool-down (the daily limit, the block list and unsubscribed addresses still apply)' ) }
					</label>
				</div>
				<div className="uxs-form__actions">
					<button type="button" className="uxs-btn uxs-btn--primary" disabled={ send.isPending || recipients.length === 0 || stats?.link === '' } onClick={ () => send.mutate() }>
						<Send size={ 14 } aria-hidden="true" /> { send.isPending ? t( 'Sending…' ) : tn( 'Send {n} request', 'Send {n} requests', recipients.length ) }
					</button>
					<button type="button" className="uxs-btn" disabled={ show.isPending } onClick={ () => show.mutate() }>
						<Eye size={ 14 } aria-hidden="true" /> { t( 'Preview' ) }
					</button>
					<button type="button" className="uxs-btn" disabled={ test.isPending || stats?.link === '' } onClick={ () => test.mutate() }>
						<SendHorizontal size={ 14 } aria-hidden="true" /> { t( 'Send a test to me' ) }
					</button>
				</div>
			</div>

			{ progress ? (
				<p className="uxs-muted" role="status">
					{ t( 'Sending… {done} of {total}', progress ) }
				</p>
			) : null }
			{ results.length > 0 ? (
				<>
					<h3 className="uxs-section-title">{ t( 'Result' ) }</h3>
					<DataTable columns={ columns } rows={ results } rowKey={ ( r ) => r.email + r.status + r.reason } pageSize={ 25 } />
				</>
			) : null }

			{ stats ? (
				<>
					<h3 className="uxs-section-title">{ t( 'From a shop or booking system' ) }</h3>
					{ stats.api_enabled ? (
						<>
							<p className="uxs-form__help">{ t( 'POST a JSON body {"email":"...","name":"...","ref":"..."} to the address below. Sign it: header X-UXS-Timestamp = current Unix time, header X-UXS-Signature = hex HMAC-SHA256 of "<timestamp>.<raw body>" with your secret. The same rules (cool-down, block list, daily limit) apply.' ) }</p>
							<pre className="uxs-code-editor" style={ { whiteSpace: 'pre-wrap', wordBreak: 'break-all' } }>{ stats.api_url }</pre>
						</>
					) : (
						<p className="uxs-form__help">{ t( 'Off. Set "Secret for automatic requests" in the Settings tab to let another system trigger the e-mails.' ) }</p>
					) }
				</>
			) : null }

			<Modal open={ preview !== null } onClose={ () => setPreview(null) } wide title={ t( 'Preview' ) } actions={ <button type="button" className="uxs-btn" onClick={ () => setPreview( null ) }>{ t( 'Close' ) }</button> }>
				{ preview ? (
					<>
						<p>
							<strong>{ t( 'Subject' ) }:</strong> { preview.subject }
						</p>
						<iframe title={ t( 'E-mail body' ) } sandbox="" srcDoc={ '<meta name="color-scheme" content="light">' + preview.body } style={ { width: '100%', height: 280, border: '1px solid var(--uxs-border)', borderRadius: 'var(--uxs-radius-s)', colorScheme: 'light' } } />
					</>
				) : null }
			</Modal>
		</>
	);
}

function LogTab(): JSX.Element {
	const [ page, setPage ] = useState( 1 );
	const [ search, setSearch ] = useState( '' );
	const [ term, setTerm ] = useState( '' );
	const [ status, setStatus ] = useState( '' );

	const list = useQuery( {
		queryKey: [ 'google-review-request', 'log', page, term, status ],
		queryFn: () => api.get< LogPage >( 'google-review-request/log', { page, per_page: PER_PAGE, q: term, status } ),
	} );

	const columns: Column< LogRow >[] = [
		{ key: 'created', header: t( 'Date' ), render: ( r ) => formatDate( r.created ) },
		{ key: 'email', header: t( 'E-mail' ), render: ( r ) => ( r.name ? `${ r.name } <${ r.email }>` : r.email ) },
		{ key: 'ref', header: t( 'Reference' ) },
		{ key: 'channel', header: t( 'How' ), render: ( r ) => channelLabel( r.channel ) },
		{
			key: 'status',
			header: t( 'Result' ),
			render: ( r ) => (
				<>
					<StatusBadge status={ r.status } />
					{ r.reason ? <span className="uxs-form__help" style={ { display: 'block' } }>{ r.status === 'failed' ? r.reason : reasonLabel( r.reason ) }</span> : null }
				</>
			),
		},
	];

	const data = list.data;

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
				<input type="search" className="uxs-input" value={ search } placeholder={ t( 'Search e-mail, name or reference' ) } aria-label={ t( 'Search' ) } onChange={ ( e ) => setSearch( e.target.value ) } />
				<select className="uxs-input" value={ status } aria-label={ t( 'Result' ) } onChange={ ( e ) => { setPage( 1 ); setStatus( e.target.value ); } }>
					<option value="">{ t( 'All results' ) }</option>
					<option value="sent">{ t( 'Sent' ) }</option>
					<option value="failed">{ t( 'Failed' ) }</option>
					<option value="skipped">{ t( 'Skipped' ) }</option>
				</select>
				<button type="submit" className="uxs-btn">
					{ t( 'Search' ) }
				</button>
				<button type="button" className="uxs-btn" disabled={ list.isFetching } onClick={ () => void queryClient.invalidateQueries( { queryKey: [ 'google-review-request' ] } ) }>
					<RefreshCw size={ 14 } aria-hidden="true" /> { t( 'Refresh' ) }
				</button>
			</form>
			{ list.isLoading ? <Loading /> : null }
			{ list.error ? <p role="alert">{ list.error.message }</p> : null }
			{ data ? (
				<>
					<p className="uxs-muted uxs-list-toolbar__count">{ tn( '{n} entry', '{n} entries', data.total ) }</p>
					<DataTable columns={ columns } rows={ data.items } rowKey={ ( r ) => r.id } page={ page } pages={ data.pages } onPage={ setPage } empty={ t( 'No review requests have been sent yet.' ) } />
				</>
			) : null }
		</>
	);
}

export default function Page( { module }: { module: ModuleInfo } ): JSX.Element {
	const [ tab, setTab ] = useState( 'send' );
	const stats = useQuery( { queryKey: [ 'google-review-request', 'stats' ], queryFn: () => api.get< Stats >( 'google-review-request/stats' ) } );

	return (
		<>
			<Tabs
				label={ module.name }
				active={ tab }
				onChange={ ( id ) => {
					setTab( id );
					if ( id === 'send' ) {
						void stats.refetch();
					}
				} }
				items={ [
					{ id: 'send', label: t( 'Send' ) },
					{ id: 'log', label: t( 'Log' ) },
					{ id: 'settings', label: t( 'Settings' ) },
				] }
			/>
			{ tab === 'send' ? <TabPanel id="send">{ stats.isLoading ? <Loading /> : <SendTab stats={ stats.data } /> }</TabPanel> : null }
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
