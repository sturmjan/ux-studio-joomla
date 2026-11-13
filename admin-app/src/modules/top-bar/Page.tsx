/**
 * Top Bar screen: live state of the bar, list of the further (rotating) messages with their own schedule, settings.
 */
import { useRef, useState } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import { Pencil, Plus, Trash2 } from 'lucide-react';
import { api, ApiError, queryClient, type ModuleInfo } from '../../app/api';
import { createT } from '../../app/i18n';
import { SettingsPanel } from '../../app/SettingsForm';
import { DataTable, Loading, Modal, TabPanel, Tabs, ToggleSwitch, confirm, pushToast, type Column } from '../../app/ui';

const { t } = createT( 'top-bar' );

type Mode = 'text' | 'title_text';

type Slide = {
	id: number;
	published: boolean;
	mode: Mode;
	title: string;
	text: string;
	from: string;
	to: string;
	ordering: number;
};

type Preview = { active: boolean; count: number };

type Draft = Omit< Slide, 'id' > & { id: number | null };

const KEY = [ 'top-bar' ];
const EMPTY: Draft = { id: null, published: true, mode: 'text', title: '', text: '', from: '', to: '', ordering: 0 };

function errorMessage( e: unknown ): string {
	return e instanceof ApiError || e instanceof Error ? e.message : t( 'Something went wrong.' );
}

function invalidate(): void {
	void queryClient.invalidateQueries( { queryKey: KEY } );
}

function SlideModal( { draft, onClose }: { draft: Draft; onClose: () => void } ): JSX.Element {
	const [ d, setD ] = useState< Draft >( draft );
	const [ error, setError ] = useState( '' );

	// Guards a double click: `isPending` only flips after a re-render, so a second click in the same tick would post twice.
	const busy = useRef( false );
	const save = useMutation( {
		mutationFn: () => ( d.id ? api.post< Slide >( `top-bar/slides/${ d.id }`, d ) : api.post< Slide >( 'top-bar/slides', d ) ),
		onSuccess: () => {
			invalidate();
			pushToast( 'success', t( 'Message saved.' ) );
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

	return (
		<Modal
			open
			wide
			onClose={ onClose }
			title={ d.id ? t( 'Edit message' ) : t( 'Add message' ) }
			actions={
				<>
					<button type="button" className="uxs-btn" onClick={ onClose }>
						{ t( 'Cancel' ) }
					</button>
					<button type="button" className="uxs-btn uxs-btn--primary" disabled={ ! d.text.trim() } aria-busy={ save.isPending } onClick={ submit }>
						{ t( 'Save' ) }
					</button>
				</>
			}
		>
			<div className="uxs-modal__body uxs-form uxs-form--grid" style={ { border: 'none', padding: 0, background: 'transparent' } }>
				<div className="uxs-form__row">
					<label htmlFor="uxs-tb-published">{ t( 'Published' ) }</label>
					<ToggleSwitch checked={ d.published } onChange={ ( v ) => setD( { ...d, published: v } ) } label={ t( 'Published' ) } />
				</div>
				<div className="uxs-form__row">
					<label htmlFor="uxs-tb-mode">{ t( 'Layout' ) }</label>
					<select id="uxs-tb-mode" value={ d.mode } onChange={ ( e ) => setD( { ...d, mode: e.target.value === 'title_text' ? 'title_text' : 'text' } ) }>
						<option value="text">{ t( 'Text only' ) }</option>
						<option value="title_text">{ t( 'Heading and text' ) }</option>
					</select>
				</div>
				{ d.mode === 'title_text' ? (
					<div className="uxs-form__row">
						<label htmlFor="uxs-tb-title">{ t( 'Heading' ) }</label>
						<input id="uxs-tb-title" type="text" maxLength={ 190 } value={ d.title } onChange={ ( e ) => setD( { ...d, title: e.target.value } ) } />
					</div>
				) : null }
				<div className="uxs-form__row">
					<label htmlFor="uxs-tb-text">{ t( 'Text' ) }</label>
					<textarea id="uxs-tb-text" rows={ 4 } value={ d.text } onChange={ ( e ) => setD( { ...d, text: e.target.value } ) } />
					<p className="uxs-form__help">{ t( 'Basic HTML (links, bold) is allowed.' ) }</p>
				</div>
				<div className="uxs-form__row">
					<label htmlFor="uxs-tb-from">{ t( 'Show from' ) }</label>
					<input id="uxs-tb-from" type="datetime-local" value={ d.from } onChange={ ( e ) => setD( { ...d, from: e.target.value } ) } />
					<p className="uxs-form__help">{ t( 'Time of the site. Leave empty to show it immediately.' ) }</p>
				</div>
				<div className="uxs-form__row">
					<label htmlFor="uxs-tb-to">{ t( 'Show until' ) }</label>
					<input id="uxs-tb-to" type="datetime-local" value={ d.to } onChange={ ( e ) => setD( { ...d, to: e.target.value } ) } />
					<p className="uxs-form__help">{ t( 'Time of the site. Leave empty to show it until you unpublish or remove it.' ) }</p>
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

function MessagesTab(): JSX.Element {
	const [ draft, setDraft ] = useState< Draft | null >( null );
	const list = useQuery( { queryKey: [ ...KEY, 'slides' ], queryFn: () => api.get< { items: Slide[] } >( 'top-bar/slides' ) } );
	const preview = useQuery( { queryKey: [ ...KEY, 'preview' ], queryFn: () => api.get< Preview >( 'top-bar/preview' ) } );

	const toggle = useMutation( {
		mutationFn: ( s: Slide ) => api.post( `top-bar/slides/${ s.id }`, { published: ! s.published } ),
		onSuccess: invalidate,
		onError: ( e ) => pushToast( 'error', errorMessage( e ) ),
	} );
	const remove = useMutation( {
		mutationFn: ( id: number ) => api.post( `top-bar/slides/${ id }/delete`, {} ),
		onSuccess: () => {
			invalidate();
			pushToast( 'success', t( 'Message deleted.' ) );
		},
		onError: ( e ) => pushToast( 'error', errorMessage( e ) ),
	} );

	const columns: Column< Slide >[] = [
		{
			key: 'text',
			header: t( 'Message' ),
			render: ( s ) => (
				<>
					{ s.mode === 'title_text' && s.title ? <strong>{ s.title } </strong> : null }
					{ s.text.replace( /<[^>]*>/g, ' ' ).replace( /\s+/g, ' ' ).trim().slice( 0, 120 ) }
				</>
			),
		},
		{
			key: 'schedule',
			header: t( 'Schedule' ),
			render: ( s ) => ( s.from || s.to ? `${ s.from ? s.from.replace( 'T', ' ' ) : '…' } – ${ s.to ? s.to.replace( 'T', ' ' ) : '…' }` : t( 'Always' ) ),
		},
		{ key: 'published', header: t( 'Published' ), render: ( s ) => <ToggleSwitch checked={ s.published } onChange={ () => toggle.mutate( s ) } label={ t( 'Published' ) } /> },
		{
			key: 'actions',
			header: '',
			render: ( s ) => (
				<span style={ { whiteSpace: 'nowrap' } }>
					<button type="button" className="uxs-btn uxs-btn--small" aria-label={ t( 'Edit' ) } title={ t( 'Edit' ) } onClick={ () => setDraft( s ) }>
						<Pencil size={ 14 } aria-hidden="true" />
					</button>{ ' ' }
					<button
						type="button"
						className="uxs-btn uxs-btn--small uxs-btn--danger"
						aria-label={ t( 'Delete' ) }
						title={ t( 'Delete' ) }
						onClick={ () => {
							void confirm( { message: t( 'Delete this message?' ), confirmLabel: t( 'Delete' ), danger: true } ).then( ( ok ) => ok && remove.mutate( s.id ) );
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
			{ preview.data ? (
				<p>
					<span className={ `uxs-badge ${ preview.data.active ? 'is-success' : 'is-warning' }` }>{ preview.data.active ? t( 'Visible on the site' ) : t( 'Not shown' ) }</span>{ ' ' }
					{ preview.data.active ? t( '{n} message(s) rotating right now.', { n: preview.data.count } ) : t( 'Nothing is in its schedule or the bar text is empty.' ) }
				</p>
			) : null }
			<p className="uxs-muted">{ t( 'The bar text from Settings is always the first message. With two or more active messages the bar switches between them like a slideshow.' ) }</p>
			<div className="uxs-actions uxs-list-toolbar">
				<button type="button" className="uxs-btn uxs-btn--primary" onClick={ () => setDraft( EMPTY ) }>
					<Plus size={ 14 } aria-hidden="true" /> { t( 'Add message' ) }
				</button>
			</div>
			{ list.isLoading ? <Loading /> : null }
			{ list.error ? <p role="alert">{ errorMessage( list.error ) }</p> : null }
			{ list.data ? <DataTable columns={ columns } rows={ list.data.items } rowKey={ ( s ) => s.id } empty={ t( 'No further messages.' ) } /> : null }
			{ draft ? <SlideModal key={ draft.id ?? 'new' } draft={ draft } onClose={ () => setDraft( null ) } /> : null }
		</>
	);
}

export default function Page( { module }: { module: ModuleInfo } ): JSX.Element {
	const [ tab, setTab ] = useState( 'settings' );
	return (
		<>
			<Tabs
				label={ module.name }
				active={ tab }
				onChange={ setTab }
				items={ [
					{ id: 'settings', label: t( 'Settings' ) },
					{ id: 'messages', label: t( 'More messages' ) },
				] }
			/>
			<TabPanel id={ tab }>{ tab === 'messages' ? <MessagesTab /> : <SettingsPanel moduleId={ module.id } /> }</TabPanel>
		</>
	);
}
