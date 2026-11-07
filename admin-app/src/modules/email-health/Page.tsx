/**
 * E-mail health: test delivery from the site and DNS check (MX / SPF / DKIM / DMARC) of the sender domain.
 */
import { useState } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import { Globe, Send } from 'lucide-react';
import { api, queryClient, type ModuleInfo } from '../../app/api';
import { createT, formatDate } from '../../app/i18n';
import { SettingsPanel } from '../../app/SettingsForm';
import { DataTable, Loading, TabPanel, Tabs, pushToast, type Column } from '../../app/ui';

type Level = 'ok' | 'info' | 'warn' | 'bad';

interface Attempt {
	mailer: string;
	kind: string;
	ok: boolean;
	error: string;
}

interface TestData {
	error: string;
	method: string;
	recipient: string;
	mailer: string;
	attempts: Attempt[];
}

interface Stored< D > {
	kind: string;
	created: string;
	success: boolean;
	summary: string;
	data: D;
}

interface Check {
	id: 'mx' | 'spf' | 'dkim' | 'dmarc';
	status: Level;
	summary: string;
	records: string[];
	notes: string[];
}

interface DnsData {
	domain: string;
	checked_at: string;
	overall: Level;
	checks: Check[];
}

interface Status {
	test: Stored< TestData > | null;
	dns: Stored< DnsData > | null;
	history: Array< Stored< TestData > >;
	sender: { email: string; domain: string };
	logging: boolean;
}

interface TestResult {
	success: boolean;
	error: string;
	method: string;
	recipient: string;
	tested_at: string;
}

type HistoryRow = {
	id: number;
	created: string;
	kind: string;
	success: boolean;
	method: string;
	recipient: string;
	error: string;
};

const { t } = createT( 'email-health' );

const BADGE: Record< Level, string > = { ok: 'is-success', info: 'is-info', warn: 'is-warning', bad: 'is-danger' };

function levelLabel( level: Level ): string {
	return level === 'ok' ? t( 'In order' ) : level === 'warn' ? t( 'Needs attention' ) : level === 'bad' ? t( 'Problem' ) : t( 'Info' );
}

function checkTitle( id: Check[ 'id' ] ): string {
	return id === 'mx' ? t( 'MX (receiving mail)' ) : id === 'spf' ? 'SPF' : id === 'dkim' ? 'DKIM' : 'DMARC';
}

function Overview( { status }: { status: Status } ): JSX.Element {
	const [ recipient, setRecipient ] = useState( '' );
	const [ domain, setDomain ] = useState( '' );
	const [ outcome, setOutcome ] = useState< TestResult | null >( null );

	const refresh = (): void => void queryClient.invalidateQueries( { queryKey: [ 'email-health' ] } );
	const test = useMutation( {
		mutationFn: () => api.post< TestResult >( 'email-health/test', { recipient: recipient.trim() } ),
		onSuccess: ( r ) => {
			setOutcome( r );
			refresh();
			void queryClient.invalidateQueries( { queryKey: [ 'email-log' ] } );
		},
		onError: ( e: Error ) => pushToast( 'error', e.message ),
	} );
	const dns = useMutation( {
		mutationFn: () => api.post( 'email-health/dns', { domain: domain.trim() } ),
		onSuccess: refresh,
		onError: ( e: Error ) => pushToast( 'error', e.message ),
	} );

	const last = status.test;
	const checked = status.dns?.data;

	const rows: HistoryRow[] = status.history.map( ( h, i ) => ( {
		id: i,
		created: h.created,
		kind: h.kind,
		success: h.success,
		method: h.data.method,
		recipient: h.data.recipient,
		error: h.data.error,
	} ) );
	const columns: Column< HistoryRow >[] = [
		{ key: 'created', header: t( 'Time' ), render: ( r ) => formatDate( r.created ) },
		{ key: 'success', header: t( 'Result' ), render: ( r ) => <span className={ `uxs-badge ${ r.success ? 'is-success' : 'is-danger' }` }>{ r.success ? t( 'Sent' ) : t( 'Failed' ) }</span> },
		{ key: 'method', header: t( 'Method' ) },
		{ key: 'recipient', header: t( 'Recipient' ) },
		{ key: 'kind', header: t( 'Started by' ), render: ( r ) => ( r.kind === 'scheduled' ? t( 'Scheduler' ) : t( 'Administrator' ) ) },
		{ key: 'error', header: t( 'Error' ) },
	];

	return (
		<>
			<div className="uxs-cards">
				<div className="uxs-card">
					<span className="uxs-card__label">{ t( 'Sending test' ) }</span>
					<span className="uxs-card__num">{ last ? ( last.success ? t( 'Works' ) : t( 'Fails' ) ) : '-' }</span>
					<span className="uxs-muted">{ last ? `${ last.data.method || '' } · ${ formatDate( last.created ) }` : t( 'No test has been run yet.' ) }</span>
				</div>
				<div className="uxs-card">
					<span className="uxs-card__label">{ t( 'Sender domain DNS' ) }</span>
					<span className="uxs-card__num">{ checked ? levelLabel( checked.overall ) : '-' }</span>
					<span className="uxs-muted">{ checked ? `${ checked.domain } · ${ formatDate( checked.checked_at ) }` : t( 'Not checked yet.' ) }</span>
				</div>
				<div className="uxs-card">
					<span className="uxs-card__label">{ t( 'Sender address' ) }</span>
					<span className="uxs-card__num">{ status.sender.email || '-' }</span>
				</div>
			</div>

			<h2 className="uxs-section-title">{ t( 'Test delivery' ) }</h2>
			<p className="uxs-form__help">
				{ t( 'Sends a short message through the same mailer as all other mail of the site. A passed test means the message left the site; it does not prove it reached the inbox, so look in the spam folder too.' ) }
			</p>
			<div className="uxs-actions" style={ { marginBottom: 'var(--uxs-sp-4)' } }>
				<input type="email" className="uxs-input" value={ recipient } placeholder={ t( 'Recipient (blank: your address)' ) } aria-label={ t( 'Recipient' ) } onChange={ ( e ) => setRecipient( e.target.value ) } />
				<button type="button" className="uxs-btn uxs-btn--primary" disabled={ test.isPending } onClick={ () => test.mutate() }>
					<Send size={ 14 } aria-hidden="true" /> { test.isPending ? t( 'Sending…' ) : t( 'Send test e-mail' ) }
				</button>
			</div>
			{ outcome ? (
				<div className={ `uxs-notice${ outcome.success ? '' : ' is-error' }` } role="status">
					<p>
						<strong>{ outcome.success ? t( 'The test e-mail to {to} was sent ({method}).', { to: outcome.recipient, method: outcome.method } ) : t( 'The test e-mail to {to} failed.', { to: outcome.recipient } ) }</strong>
					</p>
					{ ! outcome.success && outcome.error ? <p>{ outcome.error }</p> : null }
				</div>
			) : null }
			{ ! status.logging ? <p className="uxs-muted">{ t( 'Turn on the "E-mail log" module to see this message in the log.' ) }</p> : null }

			<h2 className="uxs-section-title">{ t( 'Sender domain DNS' ) }</h2>
			<p className="uxs-form__help">{ t( 'Checks the DNS records that mailbox providers use to decide whether your mail is trustworthy. Only DNS queries are made.' ) }</p>
			<div className="uxs-actions" style={ { marginBottom: 'var(--uxs-sp-4)' } }>
				<input type="text" className="uxs-input" value={ domain } placeholder={ status.sender.domain || 'example.com' } aria-label={ t( 'Domain' ) } onChange={ ( e ) => setDomain( e.target.value ) } />
				<button type="button" className="uxs-btn" disabled={ dns.isPending } onClick={ () => dns.mutate() }>
					<Globe size={ 14 } aria-hidden="true" /> { dns.isPending ? t( 'Checking…' ) : t( 'Check DNS' ) }
				</button>
			</div>
			{ checked ? (
				<div className="uxs-groups">
					{ checked.checks.map( ( c ) => (
						<div key={ c.id } className="uxs-card" style={ { marginBottom: 'var(--uxs-sp-3)' } }>
							<p>
								<span className={ `uxs-badge ${ BADGE[ c.status ] }` }>{ levelLabel( c.status ) }</span> <strong>{ checkTitle( c.id ) }</strong>
							</p>
							<p>{ c.summary }</p>
							{ c.notes.length > 0 ? <ul>{ c.notes.map( ( n, i ) => <li key={ i }>{ n }</li> ) }</ul> : null }
							{ c.records.length > 0 ? <pre className="uxs-code-editor" style={ { whiteSpace: 'pre-wrap', wordBreak: 'break-all' } }>{ c.records.join( '\n' ) }</pre> : null }
						</div>
					) ) }
				</div>
			) : null }

			<h2 className="uxs-section-title">{ t( 'Test history' ) }</h2>
			<DataTable columns={ columns } rows={ rows } rowKey={ ( r ) => r.id } pageSize={ 10 } empty={ t( 'No test has been run yet.' ) } />
		</>
	);
}

export default function Page( { module }: { module: ModuleInfo } ): JSX.Element {
	const [ tab, setTab ] = useState( 'overview' );
	const status = useQuery( { queryKey: [ 'email-health', 'status' ], queryFn: () => api.get< Status >( 'email-health/status' ) } );

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
