/**
 * Dashboard widgets: cards on the Joomla administration home screen. Per-module summary cards can be hidden or limited
 * to user groups, own notes and links are managed here; a preview shows what the current user sees; plus the settings.
 */
import { useState } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import { ExternalLink, Pencil, Plus, RefreshCw, Trash2, TriangleAlert } from 'lucide-react';
import { api, queryClient, type ModuleInfo } from '../../app/api';
import { createT } from '../../app/i18n';
import { SettingsPanel } from '../../app/SettingsForm';
import { DataTable, Loading, Modal, TabPanel, Tabs, ToggleSwitch, confirm, pushToast, type Column } from '../../app/ui';

type Status = 'ok' | 'warn' | 'bad' | '';

type ModuleCard = {
	module: string;
	name: string;
	title: string;
	value: string;
	text: string;
	status: Status;
	hidden: boolean;
	groups: number[];
};

type Item = {
	id: number;
	kind: 'note' | 'link';
	title: string;
	body: string;
	url: string;
	groups: number[];
	published: boolean;
	ordering: number;
};

type Group = { id: number; title: string; level: number };

interface Overview {
	module_cards: ModuleCard[];
	joomla_cards: ModuleCard[];
	items: Item[];
	groups: Group[];
	cpanel: { installed: boolean; published: boolean };
}

type PreviewCard = { kind: string; title: string; value: string; text: string; status: string; url: string; body: string };

const { t, tn } = createT( 'dashboard-widgets' );

const refresh = (): void => void queryClient.invalidateQueries( { queryKey: [ 'dashboard-widgets' ] } );

function groupsSummary( ids: number[], groups: Group[] ): string {
	if ( ids.length === 0 ) {
		return t( 'Everyone' );
	}
	return ids.map( ( id ) => groups.find( ( g ) => g.id === id )?.title ?? `#${ id }` ).join( ', ' );
}

/** Check list of the Joomla user groups; nothing checked means everyone who can see the home screen. */
function GroupPicker( { groups, value, onChange }: { groups: Group[]; value: number[]; onChange: ( next: number[] ) => void } ): JSX.Element {
	return (
		<>
			<div className="uxs-checklist">
				{ groups.map( ( g ) => (
					<label key={ g.id } className="uxs-checklist__item" style={ { paddingLeft: `${ g.level * 16 }px` } }>
						<input
							type="checkbox"
							checked={ value.includes( g.id ) }
							onChange={ ( e ) => onChange( e.target.checked ? [ ...value, g.id ] : value.filter( ( v ) => v !== g.id ) ) }
						/>
						{ g.title }
					</label>
				) ) }
			</div>
			<p className="uxs-form__help">{ t( 'Nothing selected means every user who can open the home screen. A group also covers the groups below it.' ) }</p>
		</>
	);
}

function RuleModal( { card, groups, onClose }: { card: ModuleCard | null; groups: Group[]; onClose: () => void } ): JSX.Element {
	const [ selected, setSelected ] = useState< number[] | null >( null );
	const current = selected ?? card?.groups ?? [];
	const save = useMutation( {
		mutationFn: () => api.post( `dashboard-widgets/rules/${ card?.module ?? '' }`, { hidden: card?.hidden ?? false, groups: current } ),
		onSuccess: () => {
			refresh();
			setSelected( null );
			onClose();
		},
		onError: ( e: Error ) => pushToast( 'error', e.message ),
	} );

	return (
		<Modal
			open={ card !== null }
			onClose={ () => {
				setSelected( null );
				onClose();
			} }
			title={ t( 'Who sees this card?' ) }
			actions={
				<>
					<button type="button" className="uxs-btn" onClick={ onClose }>{ t( 'Cancel' ) }</button>
					<button type="button" className="uxs-btn uxs-btn--primary" disabled={ save.isPending } onClick={ () => save.mutate() }>{ t( 'Save' ) }</button>
				</>
			}
		>
			<p>{ card?.name }</p>
			<GroupPicker groups={ groups } value={ current } onChange={ setSelected } />
		</Modal>
	);
}

function ItemModal( { item, groups, open, onClose }: { item: Partial< Item > | null; groups: Group[]; open: boolean; onClose: () => void } ): JSX.Element {
	const [ draft, setDraft ] = useState< Partial< Item > | null >( null );
	const d: Partial< Item > = draft ?? item ?? {};
	const kind = d.kind ?? 'note';
	const set = ( patch: Partial< Item > ): void => setDraft( { ...d, ...patch } );
	const close = (): void => {
		setDraft( null );
		onClose();
	};

	const save = useMutation( {
		mutationFn: () => {
			const payload = { kind, title: d.title ?? '', body: d.body ?? '', url: d.url ?? '', groups: d.groups ?? [], published: d.published ?? true, ordering: d.ordering ?? 0 };
			return d.id ? api.post( `dashboard-widgets/items/${ d.id }`, payload ) : api.post( 'dashboard-widgets/items', payload );
		},
		onSuccess: () => {
			refresh();
			close();
		},
		onError: ( e: Error ) => pushToast( 'error', e.message ),
	} );

	return (
		<Modal
			open={ open }
			onClose={ close }
			title={ d.id ? t( 'Edit card' ) : t( 'New card' ) }
			actions={
				<>
					<button type="button" className="uxs-btn" onClick={ close }>{ t( 'Cancel' ) }</button>
					<button type="button" className="uxs-btn uxs-btn--primary" disabled={ save.isPending || ( d.title ?? '' ).trim() === '' } onClick={ () => save.mutate() }>{ t( 'Save' ) }</button>
				</>
			}
		>
			<div className="uxs-form">
				<div className="uxs-form__row">
					<label htmlFor="dw-kind">{ t( 'Type' ) }</label>
					<select id="dw-kind" value={ kind } disabled={ Boolean( d.id ) } onChange={ ( e ) => set( { kind: e.target.value === 'link' ? 'link' : 'note' } ) }>
						<option value="note">{ t( 'Note' ) }</option>
						<option value="link">{ t( 'Link' ) }</option>
					</select>
				</div>
				<div className="uxs-form__row">
					<label htmlFor="dw-title">{ t( 'Title' ) }</label>
					<input id="dw-title" className="uxs-input" type="text" maxLength={ 190 } value={ d.title ?? '' } onChange={ ( e ) => set( { title: e.target.value } ) } />
				</div>
				{ kind === 'note' ? (
					<div className="uxs-form__row">
						<label htmlFor="dw-body">{ t( 'Text' ) }</label>
						<textarea id="dw-body" className="uxs-input" rows={ 5 } maxLength={ 5000 } value={ d.body ?? '' } onChange={ ( e ) => set( { body: e.target.value } ) } />
					</div>
				) : (
					<div className="uxs-form__row">
						<label htmlFor="dw-url">{ t( 'Address' ) }</label>
						<input id="dw-url" className="uxs-input" type="text" maxLength={ 500 } value={ d.url ?? '' } placeholder="https://… / index.php?option=com_content" onChange={ ( e ) => set( { url: e.target.value } ) } />
						<p className="uxs-form__help">{ t( 'A web address (http or https), a path on this site, or an administration link such as index.php?option=com_content.' ) }</p>
					</div>
				) }
				<div className="uxs-form__row">
					<label htmlFor="dw-ordering">{ t( 'Order' ) }</label>
					<input id="dw-ordering" className="uxs-input" type="number" min={ -9999 } max={ 9999 } value={ d.ordering ?? 0 } onChange={ ( e ) => set( { ordering: Number( e.target.value ) || 0 } ) } />
					<p className="uxs-form__help">{ t( 'Lower numbers are shown first.' ) }</p>
				</div>
				<div className="uxs-form__row">
					<label>{ t( 'Visible to' ) }</label>
					<GroupPicker groups={ groups } value={ d.groups ?? [] } onChange={ ( next ) => set( { groups: next } ) } />
				</div>
				<label className="uxs-checklist__item">
					<input type="checkbox" checked={ d.published ?? true } onChange={ ( e ) => set( { published: e.target.checked } ) } /> { t( 'Published' ) }
				</label>
			</div>
		</Modal>
	);
}

function Cards(): JSX.Element {
	const query = useQuery( { queryKey: [ 'dashboard-widgets', 'overview' ], queryFn: () => api.get< Overview >( 'dashboard-widgets/overview' ) } );
	const [ ruleFor, setRuleFor ] = useState< ModuleCard | null >( null );
	const [ editing, setEditing ] = useState< Partial< Item > | null >( null );

	const hide = useMutation( {
		mutationFn: ( c: ModuleCard ) => api.post( `dashboard-widgets/rules/${ c.module }`, { hidden: ! c.hidden, groups: c.groups } ),
		onSuccess: refresh,
		onError: ( e: Error ) => pushToast( 'error', e.message ),
	} );
	const toggleItem = useMutation( {
		mutationFn: ( i: Item ) => api.post( `dashboard-widgets/items/${ i.id }`, { ...i, published: ! i.published } ),
		onSuccess: refresh,
		onError: ( e: Error ) => pushToast( 'error', e.message ),
	} );
	const remove = useMutation( {
		mutationFn: ( id: number ) => api.post( `dashboard-widgets/items/${ id }/delete` ),
		onSuccess: refresh,
		onError: ( e: Error ) => pushToast( 'error', e.message ),
	} );
	const publish = useMutation( {
		mutationFn: () => api.post( 'dashboard-widgets/publish' ),
		onSuccess: () => {
			pushToast( 'success', t( 'The block is on the Joomla home screen.' ) );
			refresh();
		},
		onError: ( e: Error ) => pushToast( 'error', e.message ),
	} );

	if ( query.isLoading || ! query.data ) {
		return query.error ? <p role="alert">{ query.error.message }</p> : <Loading />;
	}
	const { module_cards: moduleCards, joomla_cards: joomlaCards, items, groups, cpanel } = query.data;

	const moduleColumns: Column< ModuleCard >[] = [
		{ key: 'name', header: t( 'Module' ), sortable: true },
		{
			key: 'title',
			header: t( 'Card' ),
			render: ( c ) => (
				<>
					<span className={ `uxs-badge ${ c.status === 'bad' ? 'is-danger' : c.status === 'warn' ? 'is-warning' : 'is-success' }` }>{ c.value || c.title }</span>{ ' ' }
					<span className="uxs-muted">{ c.text }</span>
				</>
			),
		},
		{
			key: 'groups',
			header: t( 'Visible to' ),
			render: ( c ) => (
				<button type="button" className="uxs-link-btn" onClick={ () => setRuleFor( c ) }>
					{ groupsSummary( c.groups, groups ) }
				</button>
			),
		},
		{
			key: 'hidden',
			header: t( 'Shown' ),
			render: ( c ) => <ToggleSwitch checked={ ! c.hidden } label={ t( 'Show the card of {module}', { module: c.name } ) } disabled={ hide.isPending } onChange={ () => hide.mutate( c ) } />,
		},
	];

	const joomlaColumns: Column< ModuleCard >[] = [
		{ key: 'name', header: t( 'Block' ), sortable: true },
		{ key: 'text', header: t( 'Position' ), render: ( c ) => <span className="uxs-muted">{ c.text }</span> },
		...moduleColumns.filter( ( c ) => c.key === 'groups' || c.key === 'hidden' ),
	];

	const itemColumns: Column< Item >[] = [
		{ key: 'title', header: t( 'Title' ), render: ( i ) => <button type="button" className="uxs-link-btn" onClick={ () => setEditing( i ) }>{ i.title }</button> },
		{ key: 'kind', header: t( 'Type' ), render: ( i ) => ( i.kind === 'link' ? t( 'Link' ) : t( 'Note' ) ) },
		{ key: 'groups', header: t( 'Visible to' ), render: ( i ) => groupsSummary( i.groups, groups ) },
		{ key: 'ordering', header: t( 'Order' ), sortable: true },
		{
			key: 'published',
			header: t( 'Published' ),
			render: ( i ) => <ToggleSwitch checked={ i.published } label={ t( 'Publish {title}', { title: i.title } ) } disabled={ toggleItem.isPending } onChange={ () => toggleItem.mutate( i ) } />,
		},
		{
			key: 'actions',
			header: '',
			render: ( i ) => (
				<span className="uxs-actions">
					<button type="button" className="uxs-btn uxs-btn--small" aria-label={ t( 'Edit' ) } onClick={ () => setEditing( i ) }>
						<Pencil size={ 14 } aria-hidden="true" />
					</button>
					<button
						type="button"
						className="uxs-btn uxs-btn--small"
						aria-label={ t( 'Delete' ) }
						onClick={ () => void confirm( { message: t( 'Delete this card?' ), danger: true, confirmLabel: t( 'Delete' ) } ).then( ( ok ) => ok && remove.mutate( i.id ) ) }
					>
						<Trash2 size={ 14 } aria-hidden="true" />
					</button>
				</span>
			),
		},
	];

	return (
		<>
			{ ! cpanel.installed || ! cpanel.published ? (
				<div className="uxs-notice">
					<TriangleAlert size={ 14 } aria-hidden="true" />{ ' ' }
					{ cpanel.installed ? t( 'The block is not published on the Joomla home screen.' ) : t( 'The administrator module "UX Studio - Dashboard" is not installed.' ) }{ ' ' }
					{ cpanel.installed ? (
						<button type="button" className="uxs-btn uxs-btn--small" disabled={ publish.isPending } onClick={ () => publish.mutate() }>{ t( 'Publish it' ) }</button>
					) : null }
				</div>
			) : null }

			<h3 className="uxs-section-title">{ t( 'Module summaries' ) }</h3>
			<DataTable columns={ moduleColumns } rows={ moduleCards } rowKey={ ( c ) => c.module } pageSize={ 12 } empty={ t( 'No enabled module supplies a card.' ) } />

			<h3 className="uxs-section-title">{ t( 'Other blocks on the Joomla home screen' ) }</h3>
			<p className="uxs-muted">{ t( 'Hide a block of another administrator module, or show it only to some user groups.' ) }</p>
			<DataTable columns={ joomlaColumns } rows={ joomlaCards } rowKey={ ( c ) => c.module } pageSize={ 12 } empty={ t( 'No other blocks are published on the home screen.' ) } />

			<h3 className="uxs-section-title">{ t( 'Own notes and links' ) }</h3>
			<div className="uxs-actions uxs-list-toolbar">
				<button type="button" className="uxs-btn uxs-btn--primary" onClick={ () => setEditing( { kind: 'note', published: true, ordering: 0, groups: [] } ) }>
					<Plus size={ 14 } aria-hidden="true" /> { t( 'New card' ) }
				</button>
			</div>
			<DataTable columns={ itemColumns } rows={ items } rowKey={ ( i ) => i.id } pageSize={ 12 } empty={ t( 'No own cards yet.' ) } />

			<RuleModal key={ ruleFor?.module ?? 'none' } card={ ruleFor } groups={ groups } onClose={ () => setRuleFor( null ) } />
			<ItemModal key={ editing?.id ?? 'new' } item={ editing } open={ editing !== null } groups={ groups } onClose={ () => setEditing( null ) } />
		</>
	);
}

function Preview(): JSX.Element {
	const query = useQuery( { queryKey: [ 'dashboard-widgets', 'preview' ], queryFn: () => api.get< { cards: PreviewCard[] } >( 'dashboard-widgets/preview' ) } );
	if ( query.isLoading || ! query.data ) {
		return <Loading />;
	}
	const cards = query.data.cards;

	return (
		<>
			<div className="uxs-actions uxs-list-toolbar">
				<button type="button" className="uxs-btn" onClick={ refresh } disabled={ query.isFetching }>
					<RefreshCw size={ 14 } aria-hidden="true" /> { t( 'Refresh' ) }
				</button>
				<span className="uxs-muted">{ tn( '{n} card is shown to you on the Joomla home screen.', '{n} cards are shown to you on the Joomla home screen.', cards.length ) }</span>
			</div>
			<div className="uxs-cards">
				{ cards.map( ( c, i ) => (
					<div key={ i } className="uxs-card">
						<span className="uxs-card__label">{ c.title }</span>
						{ c.value ? <span className="uxs-card__num">{ c.value }</span> : null }
						{ c.text || c.body ? <span className="uxs-card__label" style={ { whiteSpace: 'pre-line' } }>{ c.text || c.body }</span> : null }
						{ c.kind === 'link' && c.url ? (
							<span className="uxs-card__label"><ExternalLink size={ 12 } aria-hidden="true" /> { c.url }</span>
						) : null }
					</div>
				) ) }
			</div>
		</>
	);
}

export default function Page( { module }: { module: ModuleInfo } ): JSX.Element {
	const [ tab, setTab ] = useState( 'cards' );

	return (
		<>
			<Tabs
				label={ module.name }
				active={ tab }
				onChange={ setTab }
				items={ [
					{ id: 'cards', label: t( 'Cards' ) },
					{ id: 'preview', label: t( 'Preview' ) },
					{ id: 'settings', label: t( 'Settings' ) },
				] }
			/>
			{ tab === 'cards' ? (
				<TabPanel id="cards">
					<Cards />
				</TabPanel>
			) : tab === 'preview' ? (
				<TabPanel id="preview">
					<Preview />
				</TabPanel>
			) : (
				<TabPanel id="settings">
					<SettingsPanel moduleId={ module.id } />
				</TabPanel>
			) }
		</>
	);
}
