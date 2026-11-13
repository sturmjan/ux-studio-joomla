/**
 * E-mail log: every message the site sent, with detail, resend, delete, CSV export; plus the settings.
 */
import { useState } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import { Download, Mail, RefreshCw, Trash2 } from 'lucide-react';
import { api, queryClient, type ModuleInfo } from '../../app/api';
import { createT, formatDate } from '../../app/i18n';
import { SettingsPanel } from '../../app/SettingsForm';
import { DataTable, Loading, Modal, TabPanel, Tabs, confirm, pushToast, type Column } from '../../app/ui';

type Status = 'success' | 'error' | 'pending';

type Row = {
	id: number;
	created: string;
	to_email: string;
	from_email: string;
	subject: string;
	status: Status;
	error_message: string | null;
	source: string;
	mailer: string;
};

interface EntryPage {
	items: Row[];
	total: number;
	page: number;
	per_page: number;
}

interface Attempt {
	mailer: string;
	kind: string;
	ok: boolean;
	error: string;
}

interface Detail extends Row {
	message: string;
	headers: string;
	attachments: string[];
	attempts: Attempt[];
	meta: { html?: boolean };
}

interface Stats {
	total: number;
	success: number;
	error: number;
	pending: number;
	last_24h: number;
	failed_24h: number;
	retention_days: number;
}

const PER_PAGE = 20;
/** The shared .uxs-link-btn does not reset the browser/Joomla button background, which is a light grey patch in dark mode. */
const LINK_BTN = { border: 0, textAlign: 'left' } as const;
/** Longest error text shown inside a table row; the whole text is in the detail. */
const ERROR_PREVIEW = 120;
const { t, tn } = createT( 'email-log' );

/** Subject link: the inline style resets the Joomla button look, so the hover state is applied here (brand colour + underline). */
function SubjectButton( { children, onClick }: { children: string; onClick: () => void } ): JSX.Element {
	const [ hover, setHover ] = useState( false );

	return (
		<button
			type="button"
			className="uxs-link-btn"
			style={ { ...LINK_BTN, color: hover ? 'var(--uxs-brand-text)' : 'var(--uxs-text)', textDecoration: hover ? 'underline' : 'none', background: hover ? 'var(--uxs-surface-2)' : 'none' } }
			onMouseEnter={ () => setHover( true ) }
			onMouseLeave={ () => setHover( false ) }
			onClick={ onClick }
		>
			{ children }
		</button>
	);
}

function StatusBadge( { status }: { status: Status } ): JSX.Element {
	if ( status === 'success' ) {
		return <span className="uxs-badge is-success">{ t( 'Sent' ) }</span>;
	}
	if ( status === 'error' ) {
		return <span className="uxs-badge is-danger">{ t( 'Failed' ) }</span>;
	}
	return (
		<span className="uxs-badge is-warning" title={ t( 'No result was reported for this message. The request may have ended while sending.' ) }>
			{ t( 'Unconfirmed' ) }
		</span>
	);
}

/** "component: com_users" -> "Component: com_users" (stored raw, shown as words). */
function sourceLabel( source: string ): string {
	if ( source === '' ) {
		return '—';
	}
	const m = /^(plugin|component|module|template):\s*(.+)$/.exec( source );
	if ( ! m ) {
		return source === 'Joomla' ? t( 'Joomla itself' ) : source;
	}
	const kind = m[ 1 ] === 'plugin' ? t( 'Plugin' ) : m[ 1 ] === 'component' ? t( 'Component' ) : m[ 1 ] === 'module' ? t( 'Module' ) : t( 'Template' );
	return `${ kind }: ${ m[ 2 ] ?? '' }`;
}

function mailerLabel( id: string ): string {
	switch ( id ) {
		case 'smtp':
			return 'SMTP';
		case 'brevo':
			return 'Brevo API';
		case '':
			return '—';
		default:
			return id.startsWith( 'joomla/' ) ? 'Joomla (' + id.slice( 7 ) + ')' : id;
	}
}

/**
 * Document for the sandboxed preview. Remote images are blocked unless the admin asks for them: opening a message must not
 * fire tracking pixels (and reveal the admin's IP address) of whoever wrote it. The white page belongs to the message, not the UI.
 */
function previewDoc( html: string, images: boolean ): string {
	const csp = `default-src 'none'; style-src 'unsafe-inline'; img-src ${ images ? 'data: https: http:' : 'data:' }`;

	return `<meta http-equiv="Content-Security-Policy" content="${ csp }"><style>html{background:#fff;color:#111}</style>${ html }`;
}

function DetailModal( { id, onClose }: { id: number | null; onClose: () => void } ): JSX.Element {
	const query = useQuery( {
		queryKey: [ 'email-log', 'entry', id ],
		queryFn: () => api.get< Detail >( `email-log/entries/${ id }` ),
		enabled: id !== null,
	} );
	const resend = useMutation( {
		mutationFn: () => api.post< { message: string } >( `email-log/entries/${ id }/resend` ),
		onSuccess: ( r ) => {
			pushToast( 'success', r.message );
			void queryClient.invalidateQueries( { queryKey: [ 'email-log' ] } );
		},
		onError: ( e: Error ) => pushToast( 'error', e.message ),
	} );
	const [ images, setImages ] = useState( false );
	const d = query.data;
	const askResend = async (): Promise< void > => {
		if ( await confirm( { message: t( 'Send this message again to its original recipients?' ) } ) ) {
			resend.mutate();
		}
	};

	return (
		<Modal
			open={ id !== null }
			onClose={ onClose }
			wide
			title={ t( 'E-mail detail' ) }
			actions={
				<>
					<button type="button" className="uxs-btn" onClick={ onClose }>
						{ t( 'Close' ) }
					</button>
					<button type="button" className="uxs-btn uxs-btn--primary" disabled={ ! d || resend.isPending || d.message === '' } onClick={ () => void askResend() }>
						<Mail size={ 14 } aria-hidden="true" /> { resend.isPending ? t( 'Sending…' ) : t( 'Resend' ) }
					</button>
				</>
			}
		>
			{ query.isLoading || ! d ? (
				<Loading />
			) : (
				<>
					<div className="uxs-table-wrap">
						<table className="uxs-table">
							<tbody>
								<tr><th scope="row">{ t( 'Status' ) }</th><td><StatusBadge status={ d.status } /></td></tr>
								<tr><th scope="row">{ t( 'Date' ) }</th><td>{ formatDate( d.created ) }</td></tr>
								<tr><th scope="row">{ t( 'To' ) }</th><td>{ d.to_email }</td></tr>
								<tr><th scope="row">{ t( 'From' ) }</th><td>{ d.from_email }</td></tr>
								<tr><th scope="row">{ t( 'Subject' ) }</th><td>{ d.subject }</td></tr>
								<tr><th scope="row">{ t( 'Source' ) }</th><td>{ sourceLabel( d.source ) }</td></tr>
								<tr><th scope="row">{ t( 'Connection' ) }</th><td>{ mailerLabel( d.mailer ) }</td></tr>
								{ d.error_message ? <tr><th scope="row">{ t( 'Error' ) }</th><td>{ d.error_message }</td></tr> : null }
							</tbody>
						</table>
					</div>

					{ d.attempts.length > 1 ? (
						<>
							<h3 className="uxs-section-title">{ t( 'Attempts' ) }</h3>
							<ul className="uxs-status-list">
								{ d.attempts.map( ( a, i ) => (
									<li key={ i } className="uxs-status-list__row">
										<span>{ mailerLabel( a.mailer ) }</span>
										<span className={ `uxs-badge ${ a.ok ? 'is-success' : 'is-danger' }` }>{ a.ok ? t( 'Accepted' ) : t( 'Failed' ) }</span>
										{ a.error ? <span className="uxs-muted">{ a.error }</span> : null }
									</li>
								) ) }
							</ul>
						</>
					) : null }

					<h3 className="uxs-section-title">{ t( 'Headers' ) }</h3>
					<pre className="uxs-code-editor" style={ { whiteSpace: 'pre-wrap', wordBreak: 'break-word' } }>{ d.headers }</pre>

					{ d.attachments.length > 0 ? (
						<>
							<h3 className="uxs-section-title">{ t( 'Attachments' ) }</h3>
							<ul>{ d.attachments.map( ( n, i ) => <li key={ i }>{ n }</li> ) }</ul>
							<p className="uxs-form__help">{ t( 'Only file names are stored, so attachments are not attached again on resend.' ) }</p>
						</>
					) : null }

					<h3 className="uxs-section-title">{ t( 'Message' ) }</h3>
					{ d.message === '' ? (
						<p className="uxs-muted">{ t( 'The text of this message was not stored.' ) }</p>
					) : d.meta.html ? (
						<>
							<label className="uxs-checklist__item">
								<input type="checkbox" checked={ images } onChange={ ( e ) => setImages( e.target.checked ) } /> { t( 'Load remote images (the sender may see that you opened the message)' ) }
							</label>
							<iframe title={ t( 'E-mail body' ) } tabIndex={ -1 } sandbox="" srcDoc={ previewDoc( d.message, images ) } style={ { width: '100%', height: 320, border: '1px solid var(--uxs-border)', borderRadius: 'var(--uxs-radius-s)' } } />
						</>
					) : (
						<pre className="uxs-code-editor" style={ { whiteSpace: 'pre-wrap', wordBreak: 'break-word' } }>{ d.message }</pre>
					) }
				</>
			) }
		</Modal>
	);
}

function Messages(): JSX.Element {
	const [ page, setPage ] = useState( 1 );
	const [ search, setSearch ] = useState( '' );
	const [ term, setTerm ] = useState( '' );
	const [ status, setStatus ] = useState( '' );
	const [ detail, setDetail ] = useState< number | null >( null );

	const list = useQuery( {
		queryKey: [ 'email-log', 'entries', page, term, status ],
		queryFn: () => api.get< EntryPage >( 'email-log/entries', { page, per_page: PER_PAGE, search: term, status } ),
	} );
	const stats = useQuery( { queryKey: [ 'email-log', 'stats' ], queryFn: () => api.get< Stats >( 'email-log/stats' ) } );

	const refresh = (): void => void queryClient.invalidateQueries( { queryKey: [ 'email-log' ] } );
	const remove = useMutation( {
		mutationFn: ( id: number ) => api.post( `email-log/entries/${ id }/delete` ),
		onSuccess: refresh,
		onError: ( e: Error ) => pushToast( 'error', e.message ),
	} );
	const clear = useMutation( {
		mutationFn: () => api.post( 'email-log/clear' ),
		onSuccess: () => {
			setPage( 1 );
			refresh();
		},
		onError: ( e: Error ) => pushToast( 'error', e.message ),
	} );
	const download = useMutation( {
		mutationFn: () => api.get< { filename: string; csv: string } >( 'email-log/export' ),
		onSuccess: ( r ) => {
			const url = URL.createObjectURL( new Blob( [ r.csv ], { type: 'text/csv;charset=utf-8' } ) );
			const a = document.createElement( 'a' );
			a.href = url;
			a.download = r.filename;
			document.body.appendChild( a );
			a.click();
			a.remove();
			URL.revokeObjectURL( url );
		},
		onError: ( e: Error ) => pushToast( 'error', e.message ),
	} );

	const columns: Column< Row >[] = [
		{ key: 'created', header: t( 'Date' ), render: ( r ) => formatDate( r.created ) },
		{ key: 'to_email', header: t( 'To' ) },
		{
			key: 'subject',
			header: t( 'Subject' ),
				render: ( r ) => <SubjectButton onClick={ () => setDetail( r.id ) }>{ r.subject || t( '(no subject)' ) }</SubjectButton>,
		},
		{ key: 'source', header: t( 'Source' ), render: ( r ) => sourceLabel( r.source ) },
		{
			key: 'status',
			header: t( 'Status' ),
			render: ( r ) => (
				<>
					<StatusBadge status={ r.status } />
					{ r.mailer ? <span className="uxs-muted"> { mailerLabel( r.mailer ) }</span> : null }
					{ r.error_message ? <span className="uxs-form__help" style={ { display: 'block' } } title={ r.error_message }>{ r.error_message.length > ERROR_PREVIEW ? r.error_message.slice( 0, ERROR_PREVIEW ) + '…' : r.error_message }</span> : null }
				</>
			),
		},
		{
			key: 'actions',
			header: '',
			render: ( r ) => (
				<button
					type="button"
					className="uxs-btn uxs-btn--small"
					aria-label={ t( 'Delete entry' ) }
					onClick={ () => void confirm( { message: t( 'Delete this entry from the log?' ), danger: true, confirmLabel: t( 'Delete' ) } ).then( ( ok ) => ok && remove.mutate( r.id ) ) }
				>
					<Trash2 size={ 14 } aria-hidden="true" />
				</button>
			),
		},
	];

	const data = list.data;
	const pages = data ? Math.max( 1, Math.ceil( data.total / PER_PAGE ) ) : 1;

	return (
		<>
			{ stats.data ? (
				<div className="uxs-cards">
					<div className="uxs-card"><span className="uxs-card__label">{ t( 'Last 24 hours' ) }</span><span className="uxs-card__num">{ stats.data.last_24h }</span></div>
					<div className="uxs-card"><span className="uxs-card__label">{ t( 'Failed in 24 hours' ) }</span><span className="uxs-card__num">{ stats.data.failed_24h }</span></div>
					<div className="uxs-card"><span className="uxs-card__label">{ t( 'In the log' ) }</span><span className="uxs-card__num">{ stats.data.total }</span></div>
				</div>
			) : null }

			<form
				className="uxs-actions uxs-list-toolbar"
				onSubmit={ ( e ) => {
					e.preventDefault();
					setPage( 1 );
					setTerm( search.trim() );
				} }
			>
				<input type="search" className="uxs-input" value={ search } placeholder={ t( 'Search recipient, subject, source or error' ) } aria-label={ t( 'Search' ) } onChange={ ( e ) => setSearch( e.target.value ) } />
				<select className="uxs-input" value={ status } aria-label={ t( 'Status' ) } onChange={ ( e ) => { setPage( 1 ); setStatus( e.target.value ); } }>
					<option value="">{ t( 'All statuses' ) }</option>
					<option value="success">{ t( 'Sent' ) }</option>
					<option value="error">{ t( 'Failed' ) }</option>
					<option value="pending">{ t( 'Unconfirmed' ) }</option>
				</select>
				<button type="submit" className="uxs-btn">{ t( 'Search' ) }</button>
				<button type="button" className="uxs-btn" onClick={ refresh } disabled={ list.isFetching }>
					<RefreshCw size={ 14 } aria-hidden="true" /> { t( 'Refresh' ) }
				</button>
				<button type="button" className="uxs-btn" onClick={ () => download.mutate() } disabled={ download.isPending }>
					<Download size={ 14 } aria-hidden="true" /> { t( 'Export CSV' ) }
				</button>
				<button
					type="button"
					className="uxs-btn uxs-btn--danger"
					disabled={ clear.isPending || ! data || data.total === 0 }
					onClick={ () => void confirm( { message: t( 'Delete the whole e-mail log? This cannot be undone.' ), danger: true, confirmLabel: t( 'Delete all' ) } ).then( ( ok ) => ok && clear.mutate() ) }
				>
					<Trash2 size={ 14 } aria-hidden="true" /> { t( 'Clear all' ) }
				</button>
			</form>

			{ list.isLoading ? <Loading /> : null }
			{ list.error ? <p role="alert">{ list.error.message }</p> : null }
			{ data ? (
				<>
					<p className="uxs-muted uxs-list-toolbar__count">{ tn( '{n} message', '{n} messages', data.total ) }</p>
					<DataTable columns={ columns } rows={ data.items } rowKey={ ( r ) => r.id } page={ page } pages={ pages } onPage={ setPage } empty={ term !== '' || status !== '' ? t( 'No messages match the search.' ) : t( 'No mail has been sent yet.' ) } />
				</>
			) : null }

			<DetailModal id={ detail } onClose={ () => setDetail( null ) } />
		</>
	);
}

export default function Page( { module }: { module: ModuleInfo } ): JSX.Element {
	const [ tab, setTab ] = useState( 'messages' );

	return (
		<>
			<Tabs
				label={ module.name }
				active={ tab }
				onChange={ setTab }
				items={ [
					{ id: 'messages', label: t( 'Messages' ) },
					{ id: 'settings', label: t( 'Settings' ) },
				] }
			/>
			{ tab === 'messages' ? (
				<TabPanel id="messages">
					<Messages />
				</TabPanel>
			) : (
				<TabPanel id="settings">
					<SettingsPanel moduleId={ module.id } />
				</TabPanel>
			) }
		</>
	);
}
