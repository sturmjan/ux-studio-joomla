/**
 * Notice Board screen: notices (add / edit / archive), categories, e-mail subscribers, usage help and settings.
 */
import { useRef, useState } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import { Archive, Paperclip, Pencil, Plus, Trash2 } from 'lucide-react';
import { api, ApiError, queryClient, type ModuleInfo } from '../../app/api';
import { createT, formatDate } from '../../app/i18n';
import { SettingsPanel } from '../../app/SettingsForm';
import { DataTable, Loading, Modal, TabPanel, Tabs, ToggleSwitch, confirm, pushToast, type Column } from '../../app/ui';

const { t, tn } = createT( 'notice-board' );

type Attachment = { path: string; url: string; filename: string; mime: string; size: number; missing?: boolean };

type Notice = {
	id: number;
	created_at: string;
	title: string;
	body: string;
	category: string;
	reference: string;
	attachments: Attachment[];
	publish_date: string;
	expiry_date: string;
	is_archived: boolean;
};

type Category = { id: number; name: string; slug: string; sort_order: number; count: number };

type Subscriber = { id: number; created_at: string; email: string; confirmed: boolean; categories: string[] };

type NoticeDraft = {
	id: number | null;
	title: string;
	body: string;
	category: string;
	reference: string;
	attachments: string[];
	publish_date: string;
	expiry_date: string;
	is_archived: boolean;
};

const KEY = [ 'notice-board' ];
const EMPTY: NoticeDraft = { id: null, title: '', body: '', category: '', reference: '', attachments: [], publish_date: '', expiry_date: '', is_archived: false };

function errorMessage( e: unknown ): string {
	return e instanceof ApiError || e instanceof Error ? e.message : t( 'Something went wrong.' );
}

function invalidate(): void {
	void queryClient.invalidateQueries( { queryKey: KEY } );
}

function NoticeModal( { draft, categories, onClose }: { draft: NoticeDraft; categories: Category[]; onClose: () => void } ): JSX.Element {
	const [ d, setD ] = useState< NoticeDraft >( draft );
	const [ error, setError ] = useState( '' );

	// Guards a double click: `isPending` only flips after a re-render, so a second click in the same tick would post twice.
	const busy = useRef( false );
	const save = useMutation( {
		mutationFn: () => ( d.id ? api.post< Notice >( `notice-board/notices/${ d.id }`, d ) : api.post< Notice >( 'notice-board/notices', d ) ),
		onSuccess: () => {
			invalidate();
			pushToast( 'success', t( 'Notice saved.' ) );
			onClose();
		},
		onError: ( e ) => setError( errorMessage( e ) ),
		onSettled: () => {
			busy.current = false;
		},
	} );
	const submit = (): void => {
		if ( busy.current ) {
			return;
		}
		busy.current = true;
		save.mutate();
	};

	const setFile = ( i: number, value: string ): void => setD( { ...d, attachments: d.attachments.map( ( p, ix ) => ( ix === i ? value : p ) ) } );

	return (
		<Modal
			open
			wide
			onClose={ onClose }
			title={ d.id ? t( 'Edit notice' ) : t( 'Add notice' ) }
			actions={
				<>
					<button type="button" className="uxs-btn" onClick={ onClose }>
						{ t( 'Cancel' ) }
					</button>
					<button type="button" className="uxs-btn uxs-btn--primary" disabled={ ! d.title.trim() } aria-busy={ save.isPending } onClick={ submit }>
						{ t( 'Save' ) }
					</button>
				</>
			}
		>
			<div className="uxs-modal__body uxs-form uxs-form--grid" style={ { border: 'none', padding: 0, background: 'transparent' } }>
				<div className="uxs-form__row">
					<label htmlFor="uxs-nb-title">{ t( 'Title' ) }</label>
					<input id="uxs-nb-title" type="text" maxLength={ 255 } value={ d.title } onChange={ ( e ) => setD( { ...d, title: e.target.value } ) } />
				</div>
				<div className="uxs-form__row">
					<label htmlFor="uxs-nb-category">{ t( 'Category' ) }</label>
					<select id="uxs-nb-category" value={ d.category } onChange={ ( e ) => setD( { ...d, category: e.target.value } ) }>
						<option value="">{ t( 'No category' ) }</option>
						{ categories.map( ( c ) => (
							<option key={ c.slug } value={ c.slug }>
								{ c.name }
							</option>
						) ) }
					</select>
				</div>
				<div className="uxs-form__row">
					<label htmlFor="uxs-nb-reference">{ t( 'Reference number' ) }</label>
					<input id="uxs-nb-reference" type="text" maxLength={ 190 } value={ d.reference } onChange={ ( e ) => setD( { ...d, reference: e.target.value } ) } />
				</div>
				<div className="uxs-form__row">
					<label htmlFor="uxs-nb-body">{ t( 'Text' ) }</label>
					<textarea id="uxs-nb-body" rows={ 6 } value={ d.body } onChange={ ( e ) => setD( { ...d, body: e.target.value } ) } />
					<p className="uxs-form__help">{ t( 'Basic HTML is allowed.' ) }</p>
				</div>
				<div className="uxs-form__row">
					<label htmlFor="uxs-nb-publish">{ t( 'Publish date' ) }</label>
					<input id="uxs-nb-publish" type="date" value={ d.publish_date } onChange={ ( e ) => setD( { ...d, publish_date: e.target.value } ) } />
					<p className="uxs-form__help">{ t( 'Empty = today. A future date keeps the notice hidden (and subscribers un-notified) until then.' ) }</p>
				</div>
				<div className="uxs-form__row">
					<label htmlFor="uxs-nb-expiry">{ t( 'Expiry date' ) }</label>
					<input id="uxs-nb-expiry" type="date" value={ d.expiry_date } onChange={ ( e ) => setD( { ...d, expiry_date: e.target.value } ) } />
					<p className="uxs-form__help">{ t( 'After this day the notice moves to the archive automatically.' ) }</p>
				</div>
				<div className="uxs-form__row">
					<label>{ t( 'Attachments' ) }</label>
					{ d.attachments.map( ( p, i ) => (
						// eslint-disable-next-line react/no-array-index-key
						<span key={ i } className="uxs-actions" style={ { marginBottom: 'var(--uxs-sp-1)' } }>
							<input type="text" aria-label={ t( 'File path' ) } placeholder="images/documents/file.pdf" value={ p } onChange={ ( e ) => setFile( i, e.target.value ) } />
							<button type="button" className="uxs-btn uxs-btn--small uxs-btn--danger" aria-label={ t( 'Remove' ) } title={ t( 'Remove' ) } onClick={ () => setD( { ...d, attachments: d.attachments.filter( ( _, ix ) => ix !== i ) } ) }>
								<Trash2 size={ 14 } aria-hidden="true" />
							</button>
						</span>
					) ) }
					<span>
						<button type="button" className="uxs-btn uxs-btn--small" onClick={ () => setD( { ...d, attachments: [ ...d.attachments, '' ] } ) }>
							<Paperclip size={ 12 } aria-hidden="true" /> { t( 'Add file' ) }
						</button>
					</span>
					<p className="uxs-form__help">{ t( 'Path of a file in the Joomla media folder (upload it in Content > Media first). Only documents and images are accepted; a missing file is dropped on save.' ) }</p>
				</div>
				<div className="uxs-form__row">
					<label htmlFor="uxs-nb-archived">{ t( 'Archived' ) }</label>
					<ToggleSwitch checked={ d.is_archived } onChange={ ( v ) => setD( { ...d, is_archived: v } ) } label={ t( 'Archived' ) } />
				</div>
				{ error ? (
					<p role="alert" className="uxs-inline-warning">
						{ error }
					</p>
				) : null }
			</div>
		</Modal>
	);
}

function NoticesTab( { categories }: { categories: Category[] } ): JSX.Element {
	const [ draft, setDraft ] = useState< NoticeDraft | null >( null );
	const list = useQuery( { queryKey: [ ...KEY, 'notices' ], queryFn: () => api.get< { items: Notice[] } >( 'notice-board/notices' ) } );
	const names = Object.fromEntries( categories.map( ( c ) => [ c.slug, c.name ] ) );

	const toggleArchive = useMutation( {
		mutationFn: ( n: Notice ) => api.post( `notice-board/notices/${ n.id }`, { is_archived: ! n.is_archived } ),
		onSuccess: invalidate,
		onError: ( e ) => pushToast( 'error', errorMessage( e ) ),
	} );
	const remove = useMutation( {
		mutationFn: ( id: number ) => api.post( `notice-board/notices/${ id }/delete`, {} ),
		onSuccess: () => {
			invalidate();
			pushToast( 'success', t( 'Notice deleted.' ) );
		},
		onError: ( e ) => pushToast( 'error', errorMessage( e ) ),
	} );

	const columns: Column< Notice >[] = [
		{
			key: 'title',
			header: t( 'Title' ),
			sortable: true,
			render: ( n ) => (
				<>
					<strong>{ n.title }</strong>
					{ n.reference ? <span className="uxs-muted"> { n.reference }</span> : null }
					{ n.attachments.length > 0 ? (
						<span className="uxs-badge" title={ n.attachments.map( ( a ) => a.filename ).join( ', ' ) }>
							{ ' ' }
							<Paperclip size={ 11 } aria-hidden="true" /> { n.attachments.length }
						</span>
					) : null }
				</>
			),
		},
		{ key: 'category', header: t( 'Category' ), render: ( n ) => ( n.category ? names[ n.category ] ?? n.category : '-' ) },
		{ key: 'publish_date', header: t( 'Published' ), sortable: true },
		{ key: 'expiry_date', header: t( 'Expires' ), render: ( n ) => n.expiry_date || '-' },
		{ key: 'is_archived', header: t( 'State' ), render: ( n ) => <span className={ `uxs-badge ${ n.is_archived ? '' : 'is-success' }` }>{ n.is_archived ? t( 'Archived' ) : t( 'Active' ) }</span> },
		{
			key: 'actions',
			header: '',
			render: ( n ) => (
				<span style={ { whiteSpace: 'nowrap' } }>
					<button
						type="button"
						className="uxs-btn uxs-btn--small"
						aria-label={ t( 'Edit' ) }
						title={ t( 'Edit' ) }
						onClick={ () =>
							setDraft( {
								id: n.id,
								title: n.title,
								body: n.body,
								category: n.category,
								reference: n.reference,
								attachments: n.attachments.map( ( a ) => a.path ),
								publish_date: n.publish_date,
								expiry_date: n.expiry_date,
								is_archived: n.is_archived,
							} )
						}
					>
						<Pencil size={ 14 } aria-hidden="true" />
					</button>{ ' ' }
					<button
						type="button"
						className="uxs-btn uxs-btn--small"
						aria-label={ n.is_archived ? t( 'Restore from archive' ) : t( 'Move to archive' ) }
						title={ n.is_archived ? t( 'Restore from archive' ) : t( 'Move to archive' ) }
						disabled={ toggleArchive.isPending }
						onClick={ () => toggleArchive.mutate( n ) }
					>
						<Archive size={ 14 } aria-hidden="true" />
					</button>{ ' ' }
					<button
						type="button"
						className="uxs-btn uxs-btn--small uxs-btn--danger"
						aria-label={ t( 'Delete' ) }
						title={ t( 'Delete' ) }
						onClick={ () => {
							void confirm( { message: t( 'Delete this notice?' ), confirmLabel: t( 'Delete' ), danger: true } ).then( ( ok ) => ok && remove.mutate( n.id ) );
						} }
					>
						<Trash2 size={ 14 } aria-hidden="true" />
					</button>
				</span>
			),
		},
	];

	return (
		<>
			<div className="uxs-actions uxs-list-toolbar">
				<button type="button" className="uxs-btn uxs-btn--primary" onClick={ () => setDraft( EMPTY ) }>
					<Plus size={ 14 } aria-hidden="true" /> { t( 'Add notice' ) }
				</button>
				{ list.data ? <span className="uxs-muted uxs-list-toolbar__count">{ tn( '{n} notice', '{n} notices', list.data.items.length ) }</span> : null }
			</div>
			{ list.isLoading ? <Loading /> : null }
			{ list.error ? <p role="alert">{ errorMessage( list.error ) }</p> : null }
			{ list.data ? <DataTable columns={ columns } rows={ list.data.items } rowKey={ ( n ) => n.id } empty={ t( 'No notices yet.' ) } pageSize={ 25 } /> : null }
			<h2 className="uxs-section-title">{ t( 'How to show it on the site' ) }</h2>
			<ul className="uxs-muted">
				<li>
					{ t( 'Put the tag into an article or a custom module (Prepare content on):' ) } <code>{ '{uxs-notice-board}' }</code>, <code>{ '{uxs-notice-board category="slug" limit="10" subscribe="0" filters="0"}' }</code>
				</li>
				<li>
					RSS: <code>index.php?uxs_nb=feed</code> ({ t( 'a category: add' ) } <code>&amp;cat=slug</code>)
				</li>
				<li>
					{ t( 'Open data (OFN, JSON-LD):' ) } <code>index.php?uxs_nb=open-data</code>
				</li>
			</ul>
			{ draft ? <NoticeModal key={ draft.id ?? 'new' } draft={ draft } categories={ categories } onClose={ () => setDraft( null ) } /> : null }
		</>
	);
}

function CategoriesTab(): JSX.Element {
	const [ name, setName ] = useState( '' );
	const [ editing, setEditing ] = useState< Category | null >( null );
	const list = useQuery( { queryKey: [ ...KEY, 'categories' ], queryFn: () => api.get< { items: Category[] } >( 'notice-board/categories' ) } );

	const catBusy = useRef( false );
	const add = useMutation( {
		mutationFn: () => api.post( 'notice-board/categories', { name } ),
		onSuccess: () => {
			setName( '' );
			invalidate();
		},
		onError: ( e ) => pushToast( 'error', errorMessage( e ) ),
		onSettled: () => { catBusy.current = false; },
	} );
	const rename = useMutation( {
		mutationFn: ( c: Category ) => api.post( `notice-board/categories/${ c.id }`, { name: c.name, sort_order: c.sort_order } ),
		onSuccess: () => {
			setEditing( null );
			invalidate();
		},
		onError: ( e ) => pushToast( 'error', errorMessage( e ) ),
	} );
	const remove = useMutation( {
		mutationFn: ( id: number ) => api.post( `notice-board/categories/${ id }/delete`, {} ),
		onSuccess: invalidate,
		onError: ( e ) => pushToast( 'error', errorMessage( e ) ),
	} );

	const columns: Column< Category >[] = [
		{ key: 'name', header: t( 'Name' ), render: ( c ) => ( editing?.id === c.id ? <input type="text" aria-label={ t( 'Name' ) } value={ editing.name } onChange={ ( e ) => setEditing( { ...editing, name: e.target.value } ) } /> : c.name ) },
		{ key: 'slug', header: t( 'Slug' ), render: ( c ) => <code>{ c.slug }</code> },
		{ key: 'sort_order', header: t( 'Order' ), render: ( c ) => ( editing?.id === c.id ? <input type="number" aria-label={ t( 'Order' ) } value={ editing.sort_order } onChange={ ( e ) => setEditing( { ...editing, sort_order: Number( e.target.value ) } ) } /> : c.sort_order ) },
		{ key: 'count', header: t( 'Notices' ) },
		{
			key: 'actions',
			header: '',
			render: ( c ) =>
				editing?.id === c.id ? (
					<span style={ { whiteSpace: 'nowrap' } }>
						<button type="button" className="uxs-btn uxs-btn--small uxs-btn--primary" disabled={ rename.isPending } onClick={ () => rename.mutate( editing ) }>
							{ t( 'Save' ) }
						</button>{ ' ' }
						<button type="button" className="uxs-btn uxs-btn--small" onClick={ () => setEditing( null ) }>
							{ t( 'Cancel' ) }
						</button>
					</span>
				) : (
					<span style={ { whiteSpace: 'nowrap' } }>
						<button type="button" className="uxs-btn uxs-btn--small" aria-label={ t( 'Edit' ) } title={ t( 'Edit' ) } onClick={ () => setEditing( c ) }>
							<Pencil size={ 14 } aria-hidden="true" />
						</button>{ ' ' }
						<button
							type="button"
							className="uxs-btn uxs-btn--small uxs-btn--danger"
							aria-label={ t( 'Delete' ) }
							title={ t( 'Delete' ) }
							onClick={ () => {
								void confirm( { message: t( 'Delete this category? Its notices stay, but lose the category.' ), confirmLabel: t( 'Delete' ), danger: true } ).then( ( ok ) => ok && remove.mutate( c.id ) );
							} }
						>
							<Trash2 size={ 14 } aria-hidden="true" />
						</button>
					</span>
				),
		},
	];

	return (
		<>
			<form
				className="uxs-actions uxs-list-toolbar"
				onSubmit={ ( e ) => {
					e.preventDefault();
					if ( name.trim() && ! catBusy.current ) {
						catBusy.current = true;
						add.mutate();
					}
				} }
			>
				<input type="text" aria-label={ t( 'New category' ) } placeholder={ t( 'New category' ) } maxLength={ 190 } value={ name } onChange={ ( e ) => setName( e.target.value ) } />
				<button type="submit" className="uxs-btn uxs-btn--primary" disabled={ ! name.trim() || add.isPending }>
					<Plus size={ 14 } aria-hidden="true" /> { t( 'Add category' ) }
				</button>
			</form>
			{ list.isLoading ? <Loading /> : null }
			{ list.error ? <p role="alert">{ errorMessage( list.error ) }</p> : null }
			{ list.data ? <DataTable columns={ columns } rows={ list.data.items } rowKey={ ( c ) => c.id } empty={ t( 'No categories yet.' ) } /> : null }
		</>
	);
}

function SubscribersTab(): JSX.Element {
	const list = useQuery( { queryKey: [ ...KEY, 'subscribers' ], queryFn: () => api.get< { items: Subscriber[]; counts: { confirmed: number; pending: number } } >( 'notice-board/subscribers' ) } );
	const remove = useMutation( {
		mutationFn: ( id: number ) => api.post( `notice-board/subscribers/${ id }/delete`, {} ),
		onSuccess: invalidate,
		onError: ( e ) => pushToast( 'error', errorMessage( e ) ),
	} );

	const columns: Column< Subscriber >[] = [
		{ key: 'email', header: t( 'E-mail' ), sortable: true },
		{ key: 'confirmed', header: t( 'State' ), render: ( s ) => <span className={ `uxs-badge ${ s.confirmed ? 'is-success' : 'is-warning' }` }>{ s.confirmed ? t( 'Confirmed' ) : t( 'Waiting for confirmation' ) }</span> },
		{ key: 'categories', header: t( 'Categories' ), render: ( s ) => ( s.categories.length ? s.categories.join( ', ' ) : t( 'All' ) ) },
		{ key: 'created_at', header: t( 'Subscribed' ), render: ( s ) => formatDate( s.created_at ) },
		{
			key: 'actions',
			header: '',
			render: ( s ) => (
				<button
					type="button"
					className="uxs-btn uxs-btn--small uxs-btn--danger"
					aria-label={ t( 'Delete' ) }
					title={ t( 'Delete' ) }
					onClick={ () => {
						void confirm( { message: t( 'Delete this subscriber?' ), confirmLabel: t( 'Delete' ), danger: true } ).then( ( ok ) => ok && remove.mutate( s.id ) );
					} }
				>
					<Trash2 size={ 14 } aria-hidden="true" />
				</button>
			),
		},
	];

	return (
		<>
			{ list.isLoading ? <Loading /> : null }
			{ list.error ? <p role="alert">{ errorMessage( list.error ) }</p> : null }
			{ list.data ? (
				<>
					<p className="uxs-muted">{ t( '{confirmed} confirmed, {pending} waiting for confirmation.', { confirmed: list.data.counts.confirmed, pending: list.data.counts.pending } ) }</p>
					<DataTable columns={ columns } rows={ list.data.items } rowKey={ ( s ) => s.id } empty={ t( 'No subscribers yet.' ) } pageSize={ 25 } />
				</>
			) : null }
		</>
	);
}

export default function Page( { module }: { module: ModuleInfo } ): JSX.Element {
	const [ tab, setTab ] = useState( 'notices' );
	const categories = useQuery( { queryKey: [ ...KEY, 'categories' ], queryFn: () => api.get< { items: Category[] } >( 'notice-board/categories' ) } );

	return (
		<>
			<Tabs
				label={ module.name }
				active={ tab }
				onChange={ setTab }
				items={ [
					{ id: 'notices', label: t( 'Notices' ) },
					{ id: 'categories', label: t( 'Categories' ) },
					{ id: 'subscribers', label: t( 'Subscribers' ) },
					{ id: 'settings', label: t( 'Settings' ) },
				] }
			/>
			<TabPanel id={ tab }>
				{ tab === 'notices' ? <NoticesTab categories={ categories.data?.items ?? [] } /> : null }
				{ tab === 'categories' ? <CategoriesTab /> : null }
				{ tab === 'subscribers' ? <SubscribersTab /> : null }
				{ tab === 'settings' ? <SettingsPanel moduleId={ module.id } /> : null }
			</TabPanel>
		</>
	);
}
