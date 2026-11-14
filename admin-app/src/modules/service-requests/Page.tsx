/**
 * Service requests: file a request to UX1 Network app, follow its status and reply to the conversation; plus the settings.
 */
import { useState } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import { ArrowLeft, Plus, RefreshCw, Send, Trash2, TriangleAlert } from 'lucide-react';
import { api, queryClient, type ModuleInfo } from '../../app/api';
import { createT, formatDate } from '../../app/i18n';
import { SettingsPanel } from '../../app/SettingsForm';
import { DataTable, Loading, TabPanel, Tabs, confirm, pushToast, type Column } from '../../app/ui';

type SyncState = 'pending' | 'synced' | 'error';

interface ThreadMessage {
	id: number;
	author: string;
	author_kind: string;
	body: string;
	created_at: string;
}

type ServiceRequest = {
	id: number;
	created: string;
	title: string;
	description: string;
	status: string;
	requester_email: string;
	requester_name: string;
	page_url: string;
	type: string;
	priority: string;
	environment: Record< string, string >;
	central_ticket_id: number;
	central_number: string;
	central_status: string;
	thread: ThreadMessage[];
	pending_replies: number;
	failed_replies: number;
	failed_reply_error: string;
	warning?: string;
	sync_state: SyncState;
	sync_error: string;
	synced_at: string | null;
};

interface Counts {
	total: number;
	open: number;
	waiting_client: number;
	done: number;
	failed: number;
}

interface ItemList {
	items: ServiceRequest[];
	connected: boolean;
	counts: Counts;
}

const { t, tn } = createT( 'service-requests' );
/** The shared .uxs-link-btn rule sets no background or border, so Atum's grey button shows through in dark mode. */
const LINK_BTN = { background: 'none', border: 0, color: 'var(--uxs-brand-text)', cursor: 'pointer', textAlign: 'left' } as const;

const refresh = (): void => void queryClient.invalidateQueries( { queryKey: [ 'service-requests' ] } );

function centralStatusLabel( s: string ): string {
	switch ( s ) {
		case 'new':
			return t( 'New' );
		case 'triaged':
			return t( 'Triaged' );
		case 'in_progress':
			return t( 'In progress' );
		case 'waiting_client':
			return t( 'Waiting for you' );
		case 'waiting_vendor':
			return t( 'Waiting for a third party' );
		case 'resolved':
			return t( 'Resolved' );
		case 'closed':
			return t( 'Closed' );
		case 'rejected':
			return t( 'Rejected' );
		default:
			return s;
	}
}

function typeLabel( s: string ): string {
	switch ( s ) {
		case 'bug':
			return t( 'Something is broken' );
		case 'change':
			return t( 'Change request' );
		case 'question':
			return t( 'Question' );
		case 'task':
			return t( 'Task' );
		default:
			return s;
	}
}

function priorityLabel( s: string ): string {
	switch ( s ) {
		case 'low':
			return t( 'Low' );
		case 'normal':
			return t( 'Normal' );
		case 'high':
			return t( 'High' );
		case 'critical':
			return t( 'Critical - the site is unusable' );
		default:
			return s;
	}
}

/** What the administrator sees as the state of the request: UX1 Network app's word, or the delivery state. */
function localStatusLabel( s: string ): string {
	switch ( s ) {
		case 'in_progress':
			return t( 'In progress' );
		case 'done':
			return t( 'Done' );
		default:
			return t( 'Open' );
	}
}

function StatusBadge( { item, connected }: { item: ServiceRequest; connected: boolean } ): JSX.Element {
	if ( item.central_status ) {
		const done = [ 'resolved', 'closed', 'rejected' ].includes( item.central_status );
		const cls = done ? 'is-success' : item.central_status === 'waiting_client' ? 'is-warning' : 'is-info';
		return <span className={ `uxs-badge ${ cls }` }>{ centralStatusLabel( item.central_status ) }</span>;
	}
	if ( item.sync_state === 'error' ) {
		return <span className="uxs-badge is-danger" title={ item.sync_error }>{ t( 'Not delivered' ) }</span>;
	}
	if ( ! connected && item.central_ticket_id === 0 ) {
		// Without UX1 Network app the local copy is the only record, so its own status is what counts.
		return <span className={ `uxs-badge ${ item.status === 'done' ? 'is-success' : 'is-info' }` }>{ localStatusLabel( item.status ) }</span>;
	}
	return <span className="uxs-badge is-warning" title={ item.sync_error }>{ t( 'Sending…' ) }</span>;
}

function NewRequest( { onDone }: { onDone: () => void } ): JSX.Element {
	const [ title, setTitle ] = useState( '' );
	const [ description, setDescription ] = useState( '' );
	const [ pageUrl, setPageUrl ] = useState( '' );
	const [ type, setType ] = useState( 'bug' );
	const [ priority, setPriority ] = useState( 'normal' );
	const [ email, setEmail ] = useState( '' );

	const create = useMutation( {
		mutationFn: () => api.post< ServiceRequest >( 'service-requests/items', { title, description, page_url: pageUrl, type, priority, requester_email: email } ),
		onSuccess: ( r ) => {
			pushToast( 'success', r.central_number ? t( 'Request {number} was sent.', { number: r.central_number } ) : t( 'The request was saved and will be sent shortly.' ) );
			refresh();
			onDone();
		},
		onError: ( e: Error ) => pushToast( 'error', e.message ),
	} );

	return (
		<form
			className="uxs-form"
			onSubmit={ ( e ) => {
				e.preventDefault();
				if ( title.trim() !== '' ) {
					create.mutate();
				}
			} }
		>
			<div className="uxs-form__row">
				<label htmlFor="uxs-sr-title">{ t( 'What is it about?' ) }</label>
				<input id="uxs-sr-title" className="uxs-input" type="text" maxLength={ 255 } required value={ title } onChange={ ( e ) => setTitle( e.target.value ) } />
				<p className="uxs-form__help">{ t( 'A short title that tells us the problem in one line.' ) }</p>
			</div>
			<div className="uxs-form__row">
				<label htmlFor="uxs-sr-desc">{ t( 'Description' ) }</label>
				<textarea id="uxs-sr-desc" className="uxs-input" rows={ 5 } maxLength={ 20000 } value={ description } placeholder={ t( 'What happens, where it is visible, and what you expected instead.' ) } onChange={ ( e ) => setDescription( e.target.value ) } />
			</div>
			<div className="uxs-form__row">
				<label htmlFor="uxs-sr-url">{ t( 'Page it happens on' ) }</label>
				<input id="uxs-sr-url" className="uxs-input" type="url" maxLength={ 500 } value={ pageUrl } placeholder="https://…" onChange={ ( e ) => setPageUrl( e.target.value ) } />
				<p className="uxs-form__help">{ t( 'The address of the page where you see the problem. It saves time on finding it.' ) }</p>
			</div>
			<div className="uxs-form__row">
				<label htmlFor="uxs-sr-type">{ t( 'Type' ) }</label>
				<select id="uxs-sr-type" value={ type } onChange={ ( e ) => setType( e.target.value ) }>
					{ [ 'bug', 'change', 'question', 'task' ].map( ( v ) => <option key={ v } value={ v }>{ typeLabel( v ) }</option> ) }
				</select>
			</div>
			<div className="uxs-form__row">
				<label htmlFor="uxs-sr-priority">{ t( 'Urgency' ) }</label>
				<select id="uxs-sr-priority" value={ priority } onChange={ ( e ) => setPriority( e.target.value ) }>
					{ [ 'low', 'normal', 'high', 'critical' ].map( ( v ) => <option key={ v } value={ v }>{ priorityLabel( v ) }</option> ) }
				</select>
				<p className="uxs-form__help">{ t( 'How fast it needs an answer. Use the highest level only for a site that is down or loses orders.' ) }</p>
			</div>
			<div className="uxs-form__row">
				<label htmlFor="uxs-sr-email">{ t( 'Contact e-mail' ) }</label>
				<input id="uxs-sr-email" className="uxs-input" type="email" maxLength={ 255 } value={ email } placeholder={ t( 'Leave blank to use your account e-mail' ) } onChange={ ( e ) => setEmail( e.target.value ) } />
			</div>
			<p className="uxs-form__help">{ t( 'Details about this site (Joomla and PHP version, template, extensions, your browser) are attached automatically, so you do not have to look them up.' ) }</p>
			<div className="uxs-actions">
				<button type="submit" className="uxs-btn uxs-btn--primary" disabled={ title.trim() === '' || create.isPending }>
					<Plus size={ 14 } aria-hidden="true" /> { create.isPending ? t( 'Sending…' ) : t( 'Send request' ) }
				</button>
				<button type="button" className="uxs-btn" onClick={ onDone }>{ t( 'Cancel' ) }</button>
			</div>
		</form>
	);
}

/** One request with its conversation, as mirrored from UX1 Network app. */
function Detail( { id, onClose }: { id: number; onClose: () => void } ): JSX.Element {
	const [ body, setBody ] = useState( '' );
	const query = useQuery( { queryKey: [ 'service-requests', 'item', id ], queryFn: () => api.get< ServiceRequest >( `service-requests/items/${ id }` ) } );
	const item = query.data;
	const state = useQuery( { queryKey: [ 'service-requests', 'state' ], queryFn: () => api.get< { connected: boolean } >( 'service-requests/state' ) } );
	const connected = state.data?.connected ?? true;

	const setStatus = useMutation( {
		mutationFn: ( status: string ) => api.post< ServiceRequest >( `service-requests/items/${ id }/status`, { status } ),
		onSuccess: refresh,
		onError: ( e: Error ) => pushToast( 'error', e.message ),
	} );

	const reply = useMutation( {
		mutationFn: () => api.post< ServiceRequest >( `service-requests/items/${ id }/reply`, { body } ),
		onSuccess: () => {
			setBody( '' );
			refresh();
		},
		onError: ( e: Error ) => pushToast( 'error', e.message ),
	} );
	const resync = useMutation( {
		mutationFn: () => api.post< ServiceRequest >( `service-requests/items/${ id }/resync` ),
		onSuccess: ( r ) => {
			if ( r.warning ) {
				pushToast( 'error', t( 'UX1 Network app did not answer: {reason}', { reason: r.warning } ) );
			}
			refresh();
		},
		onError: ( e: Error ) => pushToast( 'error', e.message ),
	} );

	if ( query.isLoading || ! item ) {
		return <Loading />;
	}
	const env = Object.entries( item.environment ?? {} );

	return (
		<div>
			<button type="button" className="uxs-btn" onClick={ onClose }>
				<ArrowLeft size={ 14 } aria-hidden="true" /> { t( 'Back to the list' ) }
			</button>

			<h2 className="uxs-section-title">{ item.central_number ? `${ item.central_number } - ` : '' }{ item.title }</h2>
			<p className="uxs-muted">
				<StatusBadge item={ item } connected={ connected } /> { formatDate( item.created ) } · { typeLabel( item.type ) } · { priorityLabel( item.priority ) }
				{ item.page_url ? <> · <a href={ item.page_url } target="_blank" rel="noreferrer noopener">{ item.page_url }</a></> : null }
			</p>

			{ item.sync_state === 'error' ? (
				<div className="uxs-notice is-error">
					<TriangleAlert size={ 14 } aria-hidden="true" /> { t( 'This request has not reached UX1 Network app:' ) } { item.sync_error }
				</div>
			) : null }

			{ item.central_ticket_id === 0 ? (
				<div className="uxs-form__row">
					<label htmlFor="uxs-sr-status">{ t( 'Status' ) }</label>
					<select id="uxs-sr-status" value={ item.status } disabled={ setStatus.isPending } onChange={ ( e ) => setStatus.mutate( e.target.value ) }>
						{ [ 'open', 'in_progress', 'done' ].map( ( v ) => <option key={ v } value={ v }>{ localStatusLabel( v ) }</option> ) }
					</select>
					<p className="uxs-form__help">{ t( 'This request is only stored on this site, so you set its status yourself.' ) }</p>
				</div>
			) : null }

			{ item.description ? <p style={ { whiteSpace: 'pre-wrap' } }>{ item.description }</p> : null }

			{ env.length > 0 ? (
				<details style={ { background: 'transparent', border: 0, padding: 0 } }>
					<summary style={ { background: 'var(--uxs-surface-2)', color: 'var(--uxs-text)', padding: 'var(--uxs-sp-3) var(--uxs-sp-4)', borderRadius: 'var(--uxs-radius-s)', cursor: 'pointer' } }>{ t( 'Site details sent with this request' ) }</summary>
					<div className="uxs-table-wrap">
						<table className="uxs-table">
							<tbody>
								{ env.map( ( [ k, v ] ) => <tr key={ k }><th scope="row">{ k }</th><td>{ v }</td></tr> ) }
							</tbody>
						</table>
					</div>
				</details>
			) : null }

			<h3 className="uxs-section-title">{ t( 'Conversation' ) }</h3>
			{ item.thread.length === 0 ? (
				<p className="uxs-muted">{ t( 'No replies yet.' ) }</p>
			) : (
				<ul className="uxs-status-list">
					{ item.thread.map( ( m ) => (
						<li key={ m.id } className="uxs-status-list__row" style={ { display: 'block' } }>
							<strong>{ m.author }</strong> <span className="uxs-muted">{ formatDate( m.created_at ) }</span>
							<div style={ { whiteSpace: 'pre-wrap' } }>{ m.body }</div>
						</li>
					) ) }
				</ul>
			) }
			{ item.pending_replies > 0 ? (
				<p className="uxs-muted">{ tn( '{n} message is waiting to be delivered.', '{n} messages are waiting to be delivered.', item.pending_replies ) }</p>
			) : null }

			{ item.failed_replies > 0 ? (
				<div className="uxs-notice is-error">
					<TriangleAlert size={ 14 } aria-hidden="true" />{ ' ' }
					{ tn( '{n} message could not be delivered:', '{n} messages could not be delivered:', item.failed_replies ) } { item.failed_reply_error }{ ' ' }
					<button type="button" className="uxs-btn uxs-btn--small" disabled={ resync.isPending } onClick={ () => resync.mutate() }>{ t( 'Try again' ) }</button>
				</div>
			) : null }

			<div className="uxs-form">
				<div className="uxs-form__row">
					<label htmlFor="uxs-sr-reply">{ t( 'Add a message' ) }</label>
					<textarea id="uxs-sr-reply" className="uxs-input" rows={ 3 } maxLength={ 20000 } value={ body } disabled={ item.central_ticket_id === 0 } onChange={ ( e ) => setBody( e.target.value ) } />
					<p className="uxs-form__help">
						{ item.central_ticket_id === 0 ? t( 'You can reply once the request reaches UX1 Network app. Use Refresh to try again now.' ) : t( 'Your reply is added to the request and sent to the team.' ) }
					</p>
				</div>
				<div className="uxs-actions">
					<button type="button" className="uxs-btn uxs-btn--primary" disabled={ body.trim() === '' || reply.isPending || item.central_ticket_id === 0 } onClick={ () => reply.mutate() }>
						<Send size={ 14 } aria-hidden="true" /> { reply.isPending ? t( 'Sending…' ) : t( 'Send' ) }
					</button>
					<button type="button" className="uxs-btn" disabled={ resync.isPending } onClick={ () => resync.mutate() }>
						<RefreshCw size={ 14 } aria-hidden="true" /> { t( 'Refresh' ) }
					</button>
				</div>
			</div>
		</div>
	);
}

function Requests(): JSX.Element {
	const [ openId, setOpenId ] = useState< number | null >( null );
	const [ creating, setCreating ] = useState( false );
	// The status and the conversation live in UX1 Network app, so the list is refreshed on a timer.
	const list = useQuery( { queryKey: [ 'service-requests', 'items' ], queryFn: () => api.get< ItemList >( 'service-requests/items' ), refetchInterval: 60_000 } );
	const remove = useMutation( {
		mutationFn: ( id: number ) => api.post( `service-requests/items/${ id }/delete` ),
		onSuccess: refresh,
		onError: ( e: Error ) => pushToast( 'error', e.message ),
	} );

	if ( openId !== null ) {
		return <Detail id={ openId } onClose={ () => setOpenId( null ) } />;
	}
	if ( creating ) {
		return <NewRequest onDone={ () => setCreating( false ) } />;
	}

	const data = list.data;
	const columns: Column< ServiceRequest >[] = [
		{ key: 'central_number', header: t( 'Number' ), render: ( r ) => r.central_number || '-' },
		{
			key: 'title',
			header: t( 'Title' ),
			render: ( r ) => (
				<>
					<button type="button" className="uxs-link-btn" style={ LINK_BTN } onClick={ () => setOpenId( r.id ) }>{ r.title }</button>
					{ r.thread.length > 0 ? <span className="uxs-muted"> · { r.thread.length }</span> : null }
				</>
			),
		},
		{ key: 'status', header: t( 'Status' ), render: ( r ) => <StatusBadge item={ r } connected={ data?.connected ?? true } /> },
		{ key: 'created', header: t( 'Created' ), sortable: true, render: ( r ) => formatDate( r.created ) },
		{
			key: 'actions',
			header: '',
			render: ( r ) => (
				<button
					type="button"
					className="uxs-btn uxs-btn--small"
					aria-label={ t( 'Delete' ) }
					disabled={ remove.isPending }
					onClick={ () => void confirm( { message: r.central_ticket_id > 0 ? t( 'Delete this service request? Only your local copy is removed; the request itself stays with UX1 Network app.' ) : t( 'Delete this service request? It never reached UX1 Network app, so this is the only copy and it will be lost.' ), danger: true, confirmLabel: t( 'Delete' ) } ).then( ( ok ) => ok && remove.mutate( r.id ) ) }
				>
					<Trash2 size={ 14 } aria-hidden="true" />
				</button>
			),
		},
	];

	return (
		<>
			{ data && ! data.connected ? (
				<div className="uxs-notice">
					{ t( 'This site is not connected to UX1 Network app. Requests are saved here and sent as soon as the connection is set up (UX1 Network app connector module).' ) }
				</div>
			) : null }
			{ data ? (
				<div className="uxs-cards">
					<div className="uxs-card"><span className="uxs-card__label">{ t( 'Open' ) }</span><span className="uxs-card__num">{ data.counts.open }</span></div>
					<div className="uxs-card"><span className="uxs-card__label">{ t( 'Waiting for you' ) }</span><span className="uxs-card__num">{ data.counts.waiting_client }</span></div>
					<div className="uxs-card"><span className="uxs-card__label">{ t( 'Finished' ) }</span><span className="uxs-card__num">{ data.counts.done }</span></div>
				</div>
			) : null }
			<div className="uxs-actions uxs-list-toolbar">
				<button type="button" className="uxs-btn uxs-btn--primary" onClick={ () => setCreating( true ) }>
					<Plus size={ 14 } aria-hidden="true" /> { t( 'New request' ) }
				</button>
				<button type="button" className="uxs-btn" onClick={ refresh } disabled={ list.isFetching }>
					<RefreshCw size={ 14 } aria-hidden="true" /> { t( 'Refresh' ) }
				</button>
			</div>
			{ list.isLoading ? <Loading /> : null }
			{ list.error ? <p role="alert">{ list.error.message }</p> : null }
			{ data ? <DataTable columns={ columns } rows={ data.items } rowKey={ ( r ) => r.id } pageSize={ 15 } empty={ t( 'No service requests yet.' ) } /> : null }
		</>
	);
}

export default function Page( { module }: { module: ModuleInfo } ): JSX.Element {
	const [ tab, setTab ] = useState( 'requests' );

	return (
		<>
			<Tabs
				label={ module.name }
				active={ tab }
				onChange={ setTab }
				items={ [
					{ id: 'requests', label: t( 'Requests' ) },
					{ id: 'settings', label: t( 'Settings' ) },
				] }
			/>
			{ tab === 'requests' ? (
				<TabPanel id="requests">
					<Requests />
				</TabPanel>
			) : (
				<TabPanel id="settings">
					<SettingsPanel moduleId={ module.id } />
				</TabPanel>
			) }
		</>
	);
}
