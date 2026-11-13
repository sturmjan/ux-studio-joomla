/**
 * Popup Manager screen: list of popups (status switch, stats, duplicate, preview, delete), an inline editor with
 * content / appearance / trigger / targeting / schedule sections and the module settings.
 */
import { useEffect, useRef, useState } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import { ArrowLeft, Copy, ExternalLink, Pencil, Plus, RotateCcw, Trash2 } from 'lucide-react';
import { api, ApiError, queryClient, type ModuleInfo } from '../../app/api';
import { createT } from '../../app/i18n';
import { SettingsFields, SettingsPanel, type Field, type Value } from '../../app/SettingsForm';
import { DataTable, Loading, TabPanel, Tabs, ToggleSwitch, confirm, pushToast, type Column } from '../../app/ui';

const { t } = createT( 'popup-manager' );

type State = 'draft' | 'live' | 'scheduled' | 'expired';

type Popup = {
	id: number;
	title: string;
	status: 'publish' | 'draft';
	state: State;
	priority: number;
	starts_at: string;
	ends_at: string;
	created: string;
	modified: string;
	headline: string;
	trigger_type: string;
	delay_seconds: number;
	scroll_percent: number;
	stats: { shown: number; closed: number; clicked: number };
	[ key: string ]: unknown;
};

interface MenuItems {
	items: Array< { id: number; label: string } >;
}

const KEY = [ 'popup-manager', 'popups' ];

/** The shared .uxs-link-btn does not reset the browser/Joomla button background (light grey patch, unreadable in dark mode). */
const LINK_BTN = { background: 'none', border: 0, color: 'var(--uxs-text)', textAlign: 'left' } as const;

function errorMessage( e: unknown ): string {
	return e instanceof ApiError || e instanceof Error ? e.message : t( 'Something went wrong.' );
}

function fields( menu: Record< string, string > ): Field[] {
	const content = t( 'Content' );
	const look = t( 'Appearance' );
	const trigger = t( 'Trigger' );
	const target = t( 'Targeting' );
	const schedule = t( 'Schedule' );
	return [
		{ key: 'title', type: 'text', section: content, label: t( 'Name' ), help: t( 'The name of the popup. It is for you in the list, visitors do not see it.' ), maxlength: 190 },
		{ key: 'headline', type: 'text', section: content, label: t( 'Headline' ), maxlength: 200 },
		{ key: 'body', type: 'richtext', section: content, label: t( 'Text' ), help: t( 'Basic HTML is allowed (paragraphs, bold, lists, links, images). Scripts and unsafe attributes are removed when you save.' ), maxlength: 20000 },
		{ key: 'image', type: 'media', section: content, label: t( 'Image' ), help: t( 'Path of an image in the Joomla media folder (for example images/offer.jpg) or a full https address.' ) },
		{ key: 'image_alt', type: 'text', section: content, label: t( 'Image description' ), help: t( 'Read aloud by screen readers. Leave empty for a decorative image.' ), maxlength: 200 },
		{ key: 'cta_text', type: 'text', section: content, label: t( 'Button label' ), maxlength: 80 },
		{ key: 'cta_url', type: 'text', section: content, label: t( 'Button address' ), help: t( 'A full address (https://...), a path on your site (/contact), mailto:, tel: or # to only close the popup.' ), maxlength: 500 },
		{ key: 'cta_new_tab', type: 'toggle', section: content, label: t( 'Open the button in a new tab' ), show_if: { key: 'cta_url', truthy: true } },

		{
			key: 'position', type: 'select', section: look, label: t( 'Position' ),
			options: { center: t( 'Centre (modal window)' ), top: t( 'Bar at the top' ), bottom: t( 'Bar at the bottom' ), 'bottom-right': t( 'Corner: bottom right' ), 'bottom-left': t( 'Corner: bottom left' ) },
			help: t( 'Only the centred popup darkens the page and keeps the focus inside. Bars and corners do not block the page.' ),
		},
		{ key: 'animation', type: 'select', section: look, label: t( 'Animation' ), options: { fade: t( 'Fade' ), slide: t( 'Slide in' ), none: t( 'None' ) } },
		{ key: 'width', type: 'number', section: look, label: t( 'Width (px)' ), min: 240, max: 1200, step: 10 },
		{ key: 'bg_color', type: 'color', section: look, label: t( 'Background color' ) },
		{ key: 'text_color', type: 'color', section: look, label: t( 'Text color' ) },
		{ key: 'accent_color', type: 'color', section: look, label: t( 'Button and link color' ) },
		{ key: 'cta_text_color', type: 'color', section: look, label: t( 'Button text color' ) },
		{ key: 'overlay_opacity', type: 'number', section: look, label: t( 'Overlay darkness (%)' ), min: 0, max: 100, show_if: { key: 'position', equals: 'center' } },

		{
			key: 'trigger_type', type: 'select', section: trigger, label: t( 'When to show' ),
			options: { immediate: t( 'Immediately after the page loads' ), delay: t( 'After a delay' ), scroll: t( 'After scrolling' ), exit: t( 'When the visitor is about to leave (exit intent)' ) },
			help: t( 'Exit intent: on a computer when the mouse leaves through the top of the window, on a phone after a quick scroll back up.' ),
		},
		{ key: 'delay_seconds', type: 'number', section: trigger, label: t( 'Delay (seconds)' ), min: 0, max: 600, show_if: { key: 'trigger_type', equals: 'delay' } },
		{ key: 'scroll_percent', type: 'number', section: trigger, label: t( 'Scrolled part of the page (%)' ), min: 1, max: 100, show_if: { key: 'trigger_type', equals: 'scroll' }, help: t( 'The popup cannot appear on a page that is too short to scroll.' ) },
		{ key: 'exit_delay', type: 'number', section: trigger, label: t( 'Arm the exit detection after (seconds)' ), min: 0, max: 600, show_if: { key: 'trigger_type', equals: 'exit' }, help: t( 'Stops the popup from jumping at someone who has just arrived.' ) },
		{
			key: 'frequency', type: 'select', section: trigger, label: t( 'How often the same visitor sees it' ),
			options: { always: t( 'On every page view' ), session: t( 'Once per browser session' ), days: t( 'Once every N days' ), once: t( 'Only once' ) },
			help: t( 'Remembered in the visitor\'s browser (see the storage choice in Settings), so mention it in your privacy notice.' ),
		},
		{ key: 'frequency_days', type: 'number', section: trigger, label: t( 'Days between two appearances' ), min: 1, max: 365, show_if: { key: 'frequency', equals: 'days' } },
		{ key: 'stop_after_click', type: 'toggle', section: trigger, label: t( 'Never show again after the button was clicked' ), show_if: { key: 'frequency', in: [ 'session', 'days', 'once' ] } },

		{
			key: 'menu_mode', type: 'select', section: target, label: t( 'Pages' ),
			options: { all: t( 'Everywhere' ), include: t( 'Only on the selected pages' ), exclude: t( 'Everywhere except the selected pages' ) },
		},
		{ key: 'menu_items', type: 'multiselect', section: target, label: t( 'Menu items' ), options: menu, show_if: { key: 'menu_mode', in: [ 'include', 'exclude' ] }, help: t( 'Pages are recognised by the menu item they belong to.' ) },
		{ key: 'url_patterns', type: 'textarea', section: target, label: t( 'Address fragments' ), show_if: { key: 'menu_mode', in: [ 'include', 'exclude' ] }, help: t( 'One per line, for example /checkout or ?view=article. The page matches when its address contains the fragment.' ) },
		{
			key: 'devices', type: 'multiselect', section: target, label: t( 'Devices' ),
			options: { desktop: t( 'Computer' ), tablet: t( 'Tablet' ), mobile: t( 'Phone' ) },
			help: t( 'Leave all unchecked to show the popup on every device.' ),
		},
		{ key: 'audience', type: 'select', section: target, label: t( 'Visitors' ), options: { all: t( 'Everyone' ), guests: t( 'Only visitors who are not logged in' ), members: t( 'Only logged-in users' ) } },
		{ key: 'priority', type: 'number', section: target, label: t( 'Priority' ), min: -100, max: 100, help: t( 'When more popups match a page, the highest number wins (see Popups per page in Settings).' ) },

		{ key: 'starts_at', type: 'datetime', section: schedule, label: t( 'Show from' ), help: t( 'Time of your site. Leave empty to start at once.' ) },
		{ key: 'ends_at', type: 'datetime', section: schedule, label: t( 'Show until' ), help: t( 'Time of your site. Leave empty for no end.' ) },
	];
}

const DEFAULTS: Record< string, Value > = {
	title: '', headline: '', body: '', image: '', image_alt: '', cta_text: '', cta_url: '', cta_new_tab: false,
	position: 'center', animation: 'fade', width: 520, bg_color: '#ffffff', text_color: '#1f2937', accent_color: '#2563eb', cta_text_color: '#ffffff', overlay_opacity: 60,
	trigger_type: 'delay', delay_seconds: 4, scroll_percent: 50, exit_delay: 3, frequency: 'session', frequency_days: 7, stop_after_click: true,
	menu_mode: 'all', menu_items: [], url_patterns: '', devices: [], audience: 'all', priority: 0, starts_at: '', ends_at: '',
};

function PopupEditor( { id, onDone }: { id: number; onDone: () => void } ): JSX.Element {
	const [ draft, setDraft ] = useState< Record< string, Value > >( id === 0 ? { ...DEFAULTS } : {} );
	const [ status, setStatus ] = useState< 'publish' | 'draft' >( 'draft' );
	const [ loaded, setLoaded ] = useState( id === 0 );

	const menu = useQuery( { queryKey: [ 'popup-manager', 'menu' ], queryFn: () => api.get< MenuItems >( 'popup-manager/menu-items' ) } );
	const popup = useQuery( {
		queryKey: [ 'popup-manager', 'popup', id ],
		queryFn: () => api.get< Popup >( `popup-manager/popups/${ id }` ),
		enabled: id > 0,
		staleTime: 0,
		gcTime: 0,
	} );

	useEffect( () => {
		const data = popup.data;
		if ( loaded || ! data ) {
			return;
		}
		const merged: Record< string, Value > = { ...DEFAULTS };
		for ( const key of Object.keys( DEFAULTS ) ) {
			const v = data[ key ];
			if ( v !== undefined && v !== null ) {
				merged[ key ] = Array.isArray( v ) ? v.map( String ) : ( v as Value );
			}
		}
		setDraft( merged );
		setStatus( data.status );
		setLoaded( true );
	}, [ popup.data, loaded ] );

	const busy = useRef( false );
	const save = useMutation( {
		mutationFn: () => api.post< Popup >( id ? `popup-manager/popups/${ id }` : 'popup-manager/popups', { ...draft, status } ),
		onSuccess: () => {
			pushToast( 'success', t( 'Popup saved.' ) );
			void queryClient.invalidateQueries( { queryKey: KEY } );
			void queryClient.invalidateQueries( { queryKey: [ 'popup-manager', 'popup', id ] } );
			onDone();
		},
		onError: ( e ) => pushToast( 'error', errorMessage( e ) ),
		onSettled: () => {
			busy.current = false;
		},
	} );

	if ( ! loaded ) {
		return popup.isError ? <p role="alert">{ errorMessage( popup.error ) }</p> : <Loading />;
	}
	const menuOptions: Record< string, string > = Object.fromEntries( ( menu.data?.items ?? [] ).map( ( m ) => [ String( m.id ), m.label ] ) );

	return (
		<form
			onSubmit={ ( e ) => {
				e.preventDefault();
				if ( busy.current ) {
					return;
				}
				busy.current = true;
				save.mutate();
			} }
		>
			<div className="uxs-actions" style={ { marginBottom: 'var(--uxs-sp-4)' } }>
				<button type="button" className="uxs-link-btn" style={ LINK_BTN } onClick={ onDone }>
					<ArrowLeft size={ 14 } aria-hidden="true" /> { t( 'Back to the list' ) }
				</button>
				<h2 style={ { margin: 0 } }>{ id ? t( 'Edit popup' ) : t( 'New popup' ) }</h2>
				<span className="uxs-actions">
					<ToggleSwitch checked={ status === 'publish' } onChange={ ( on ) => setStatus( on ? 'publish' : 'draft' ) } label={ t( 'Published' ) } />
					<span>{ status === 'publish' ? t( 'Published' ) : t( 'Draft' ) }</span>
				</span>
			</div>
			<SettingsFields schema={ fields( menuOptions ) } draft={ draft } setDraft={ setDraft } />
			<div className="uxs-form__actions">
				<button type="submit" className="uxs-btn uxs-btn--primary" disabled={ save.isPending }>
					{ t( 'Save' ) }
				</button>
				<button type="button" className="uxs-btn" onClick={ onDone }>
					{ t( 'Cancel' ) }
				</button>
			</div>
		</form>
	);
}

function PopupList( { onEdit }: { onEdit: ( id: number ) => void } ): JSX.Element {
	const list = useQuery( { queryKey: KEY, queryFn: () => api.get< { popups: Popup[] } >( 'popup-manager/popups' ) } );
	const meta = useQuery( { queryKey: [ 'core', 'meta' ], queryFn: () => api.get< { canAdmin: boolean } >( 'core/meta' ) } );
	const canAdmin = meta.data?.canAdmin ?? false;
	const refresh = (): Promise< void > => queryClient.invalidateQueries( { queryKey: KEY } );
	const runBusy = useRef( false );
	const run = useMutation( {
		mutationFn: ( p: { route: string; body?: unknown } ) => api.post< unknown >( p.route, p.body ),
		onSuccess: refresh,
		onSettled: () => {
			runBusy.current = false;
		},
		onError: ( e ) => pushToast( 'error', errorMessage( e ) ),
	} );

	const preview = async ( id: number ): Promise< void > => {
		try {
			const { url } = await api.get< { url: string } >( `popup-manager/popups/${ id }/preview-url` );
			window.open( url, '_blank', 'noopener' );
		} catch ( e ) {
			pushToast( 'error', errorMessage( e ) );
		}
	};
	const remove = async ( p: Popup ): Promise< void > => {
		if ( await confirm( { message: t( 'Delete the popup "{name}" and its statistics?', { name: p.title } ), confirmLabel: t( 'Delete' ), danger: true } ) ) {
			run.mutate( { route: `popup-manager/popups/${ p.id }/delete` } );
		}
	};
	const resetStats = async ( p: Popup ): Promise< void > => {
		if ( await confirm( { message: t( 'Reset the statistics of "{name}"?', { name: p.title } ), confirmLabel: t( 'Reset' ), danger: true } ) ) {
			run.mutate( { route: `popup-manager/popups/${ p.id }/reset-stats` } );
		}
	};

	const stateLabel: Record< State, string > = { draft: t( 'Draft' ), live: t( 'Live' ), scheduled: t( 'Scheduled' ), expired: t( 'Expired' ) };
	const stateClass: Record< State, string > = { draft: '', live: 'is-success', scheduled: 'is-info', expired: 'is-warning' };
	const triggerLabel = ( p: Popup ): string =>
		p.trigger_type === 'delay'
			? t( 'After {n} s', { n: p.delay_seconds } )
			: p.trigger_type === 'scroll'
				? t( 'After scrolling {n} %', { n: p.scroll_percent } )
				: p.trigger_type === 'exit'
					? t( 'Exit intent' )
					: t( 'Immediately' );

	const columns: Column< Popup >[] = [
		{ key: 'title', header: t( 'Name' ), sortable: true, render: ( p ) => <button type="button" className="uxs-link-btn" style={ LINK_BTN } onClick={ () => onEdit( p.id ) }>{ p.title }</button> },
		{
			key: 'state',
			header: t( 'Status' ),
			sortable: true,
			render: ( p ) => (
				<span className="uxs-actions">
					<ToggleSwitch checked={ p.status === 'publish' } label={ p.status === 'publish' ? t( 'Unpublish' ) : t( 'Publish' ) } onChange={ ( on ) => run.mutate( { route: `popup-manager/popups/${ p.id }/status`, body: { status: on ? 'publish' : 'draft' } } ) } />
					<span className={ `uxs-badge ${ stateClass[ p.state ] }` }>{ stateLabel[ p.state ] }</span>
				</span>
			),
		},
		{ key: 'trigger_type', header: t( 'Trigger' ), render: triggerLabel },
		{
			key: 'stats',
			header: t( 'Shown / clicked' ),
			render: ( p ) => {
				const ctr = p.stats.shown > 0 ? ` (${ Math.round( ( p.stats.clicked / p.stats.shown ) * 1000 ) / 10} %)` : '';
				return `${ p.stats.shown } / ${ p.stats.clicked }${ ctr }`;
			},
		},
		{
			key: 'actions',
			header: '',
			render: ( p ) => (
				<span className="uxs-actions">
					<button type="button" className="uxs-btn uxs-btn--small" onClick={ () => onEdit( p.id ) } aria-label={ t( 'Edit' ) } title={ t( 'Edit' ) }><Pencil size={ 14 } aria-hidden="true" /></button>
					<button type="button" className="uxs-btn uxs-btn--small" onClick={ () => void preview( p.id ) } aria-label={ t( 'Preview on the site' ) } title={ t( 'Preview on the site' ) }><ExternalLink size={ 14 } aria-hidden="true" /></button>
					<button type="button" className="uxs-btn uxs-btn--small" onClick={ () => {
						if ( runBusy.current ) {
							return;
						}
						runBusy.current = true;
						run.mutate( { route: `popup-manager/popups/${ p.id }/duplicate` } );
					} } aria-label={ t( 'Duplicate' ) } title={ t( 'Duplicate' ) }><Copy size={ 14 } aria-hidden="true" /></button>
					{ canAdmin ? (
						<>
							<button type="button" className="uxs-btn uxs-btn--small" onClick={ () => void resetStats( p ) } aria-label={ t( 'Reset statistics' ) } title={ t( 'Reset statistics' ) }><RotateCcw size={ 14 } aria-hidden="true" /></button>
							<button type="button" className="uxs-btn uxs-btn--small uxs-btn--danger" onClick={ () => void remove( p ) } aria-label={ t( 'Delete' ) } title={ t( 'Delete' ) }><Trash2 size={ 14 } aria-hidden="true" /></button>
						</>
					) : null }
				</span>
			),
		},
	];

	if ( list.isLoading ) {
		return <Loading />;
	}
	if ( list.error ) {
		return <p role="alert">{ errorMessage( list.error ) }</p>;
	}
	return (
		<>
			<div className="uxs-actions" style={ { marginBottom: 'var(--uxs-sp-4)' } }>
				<button type="button" className="uxs-btn uxs-btn--primary" onClick={ () => onEdit( 0 ) }>
					<Plus size={ 14 } aria-hidden="true" /> { t( 'Add popup' ) }
				</button>
			</div>
			<DataTable columns={ columns } rows={ list.data?.popups ?? [] } rowKey={ ( p ) => p.id } empty={ t( 'No popups yet.' ) } pageSize={ 20 } />
		</>
	);
}

export default function Page( { module }: { module: ModuleInfo } ): JSX.Element {
	const [ tab, setTab ] = useState( 'popups' );
	const [ editing, setEditing ] = useState< number | null >( null );

	return (
		<>
			<Tabs
				label={ module.name }
				active={ tab }
				onChange={ ( id ) => {
					setTab( id );
					setEditing( null );
				} }
				items={ [
					{ id: 'popups', label: t( 'Popups' ) },
					{ id: 'settings', label: t( 'Settings' ) },
				] }
			/>
			{ tab === 'settings' ? (
				<TabPanel id="settings">
					<SettingsPanel moduleId={ module.id } />
				</TabPanel>
			) : (
				<TabPanel id="popups">
					{ editing === null ? <PopupList onEdit={ setEditing } /> : <PopupEditor key={ editing } id={ editing } onDone={ () => setEditing( null ) } /> }
				</TabPanel>
			) }
		</>
	);
}
