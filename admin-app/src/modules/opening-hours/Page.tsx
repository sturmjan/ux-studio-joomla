/**
 * Opening Hours screen: locations with the live open / closed state, quick "closed for N minutes" override,
 * editor (hours, exceptions, announcements), usage help and settings.
 */
import { useState } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import { DoorClosed, DoorOpen, Pencil, Plus, Trash2 } from 'lucide-react';
import { api, ApiError, queryClient, type ModuleInfo } from '../../app/api';
import { createT, formatDate } from '../../app/i18n';
import { SettingsPanel } from '../../app/SettingsForm';
import { DataTable, Loading, Modal, TabPanel, Tabs, confirm, pushToast, type Column } from '../../app/ui';
import { LocationModal } from './LocationModal';
import { EMPTY_DRAFT, type Draft, type Location } from './types';

const { t } = createT( 'opening-hours' );

const KEY = [ 'opening-hours' ];
const DURATIONS = [ 15, 30, 60, 120, 240, 1440, 4320 ];

function errorMessage( e: unknown ): string {
	return e instanceof ApiError || e instanceof Error ? e.message : t( 'Something went wrong.' );
}

function durationLabel( minutes: number ): string {
	if ( minutes < 60 ) {
		return t( '{n} minutes', { n: minutes } );
	}
	if ( minutes < 1440 ) {
		return t( '{n} hours', { n: minutes / 60 } );
	}
	return t( '{n} days', { n: minutes / 1440 } );
}

function invalidate(): void {
	void queryClient.invalidateQueries( { queryKey: KEY } );
}

function OverrideModal( { location, onClose }: { location: Location; onClose: () => void } ): JSX.Element {
	const [ minutes, setMinutes ] = useState( 60 );
	const [ reason, setReason ] = useState( '' );
	const close = useMutation( {
		mutationFn: () => api.post( `opening-hours/locations/${ location.id }/override`, { minutes, reason } ),
		onSuccess: () => {
			invalidate();
			pushToast( 'success', t( 'The location is marked as closed.' ) );
			onClose();
		},
		onError: ( e ) => pushToast( 'error', errorMessage( e ) ),
	} );
	return (
		<Modal
			open
			onClose={ onClose }
			title={ t( 'Close "{name}" temporarily', { name: location.title } ) }
			actions={
				<>
					<button type="button" className="uxs-btn" onClick={ onClose }>
						{ t( 'Cancel' ) }
					</button>
					<button type="button" className="uxs-btn uxs-btn--danger" disabled={ close.isPending } onClick={ () => close.mutate() }>
						{ t( 'Close now' ) }
					</button>
				</>
			}
		>
			<div className="uxs-modal__body">
				<div className="uxs-form__row">
					<label htmlFor="uxs-oh-minutes">{ t( 'For' ) }</label>
					<select id="uxs-oh-minutes" value={ minutes } onChange={ ( e ) => setMinutes( Number( e.target.value ) ) }>
						{ DURATIONS.map( ( m ) => (
							<option key={ m } value={ m }>
								{ durationLabel( m ) }
							</option>
						) ) }
					</select>
				</div>
				<div className="uxs-form__row">
					<label htmlFor="uxs-oh-reason">{ t( 'Public reason (optional)' ) }</label>
					<input id="uxs-oh-reason" type="text" maxLength={ 255 } value={ reason } placeholder={ t( 'Lunch break' ) } onChange={ ( e ) => setReason( e.target.value ) } />
					<p className="uxs-form__help">{ t( 'Shown to visitors on the card. The location counts as closed until the time runs out, whatever the weekly hours say.' ) }</p>
				</div>
			</div>
		</Modal>
	);
}

function LocationsTab(): JSX.Element {
	const [ draft, setDraft ] = useState< Draft | null >( null );
	const [ overriding, setOverriding ] = useState< Location | null >( null );
	const list = useQuery( { queryKey: [ ...KEY, 'locations' ], queryFn: () => api.get< { items: Location[] } >( 'opening-hours/locations' ) } );

	const remove = useMutation( {
		mutationFn: ( id: number ) => api.post( `opening-hours/locations/${ id }/delete`, {} ),
		onSuccess: () => {
			invalidate();
			pushToast( 'success', t( 'Location deleted.' ) );
		},
		onError: ( e ) => pushToast( 'error', errorMessage( e ) ),
	} );
	const reopen = useMutation( {
		mutationFn: ( id: number ) => api.post( `opening-hours/locations/${ id }/override/clear`, {} ),
		onSuccess: () => {
			invalidate();
			pushToast( 'success', t( 'The temporary closure was cancelled.' ) );
		},
		onError: ( e ) => pushToast( 'error', errorMessage( e ) ),
	} );

	const columns: Column< Location >[] = [
		{
			key: 'title',
			header: t( 'Location' ),
			render: ( l ) => (
				<>
					<strong>{ l.title }</strong> <span className="uxs-muted">#{ l.id }</span>
					{ l.address ? <div className="uxs-muted">{ l.address }</div> : null }
				</>
			),
		},
		{
			key: 'status',
			header: t( 'State now' ),
			render: ( l ) => (
				<>
					<span className={ `uxs-badge ${ l.status.open ? 'is-success' : 'is-danger' }` }>{ l.status.open ? t( 'Open' ) : t( 'Closed' ) }</span>
					{ l.override ? (
						<div className="uxs-muted">
							{ t( 'Closed until {time}', { time: formatDate( new Date( l.override.until * 1000 ).toISOString().slice( 0, 19 ).replace( 'T', ' ' ) ) } ) }
							{ l.override.reason ? ` (${ l.override.reason })` : '' }
						</div>
					) : null }
				</>
			),
		},
		{ key: 'exceptions', header: t( 'Exceptions' ), render: ( l ) => l.exceptions.length },
		{
			key: 'actions',
			header: '',
			render: ( l ) => (
				<span style={ { whiteSpace: 'nowrap' } }>
					{ l.override ? (
						<button type="button" className="uxs-btn uxs-btn--small" disabled={ reopen.isPending } onClick={ () => reopen.mutate( l.id ) }>
							<DoorOpen size={ 14 } aria-hidden="true" /> { t( 'Reopen' ) }
						</button>
					) : (
						<button type="button" className="uxs-btn uxs-btn--small" onClick={ () => setOverriding( l ) }>
							<DoorClosed size={ 14 } aria-hidden="true" /> { t( 'Close for…' ) }
						</button>
					) }{ ' ' }
					<button
						type="button"
						className="uxs-btn uxs-btn--small"
						aria-label={ t( 'Edit' ) }
						title={ t( 'Edit' ) }
						onClick={ () => setDraft( { id: l.id, title: l.title, address: l.address, phone: l.phone, lat: l.lat, lng: l.lng, hours: l.hours, exceptions: l.exceptions, announcements: l.announcements } ) }
					>
						<Pencil size={ 14 } aria-hidden="true" />
					</button>{ ' ' }
					<button
						type="button"
						className="uxs-btn uxs-btn--small uxs-btn--danger"
						aria-label={ t( 'Delete' ) }
						title={ t( 'Delete' ) }
						onClick={ () => {
							void confirm( { message: t( 'Delete the location "{name}"?', { name: l.title } ), confirmLabel: t( 'Delete' ), danger: true } ).then( ( ok ) => ok && remove.mutate( l.id ) );
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
				<button type="button" className="uxs-btn uxs-btn--primary" onClick={ () => setDraft( EMPTY_DRAFT ) }>
					<Plus size={ 14 } aria-hidden="true" /> { t( 'Add location' ) }
				</button>
			</div>
			{ list.isLoading ? <Loading /> : null }
			{ list.error ? <p role="alert">{ errorMessage( list.error ) }</p> : null }
			{ list.data ? <DataTable columns={ columns } rows={ list.data.items } rowKey={ ( l ) => l.id } empty={ t( 'No locations yet.' ) } /> : null }
			<h2 className="uxs-section-title">{ t( 'How to show it on the site' ) }</h2>
			<ul className="uxs-muted">
				<li>{ t( 'Joomla module "UX Studio - Opening Hours" (Content > Site Modules > New): choose the location and a template position.' ) }</li>
				<li>
					{ t( 'In an article or a custom module:' ) } <code>{ '{opening-hours id=1}' }</code>, <code>{ '{opening-hours id=all}' }</code>, <code>{ '{opening-hours-status id=1}' }</code>
				</li>
				<li>
					{ t( 'Public live status (JSON):' ) } <code>index.php?option=com_uxstudio&amp;task=public.dispatch&amp;format=json&amp;route=opening-hours/locations/1/status</code>
				</li>
			</ul>
			{ draft ? <LocationModal key={ draft.id ?? 'new' } draft={ draft } onClose={ () => setDraft( null ) } onSaved={ invalidate } /> : null }
			{ overriding ? <OverrideModal key={ overriding.id } location={ overriding } onClose={ () => setOverriding( null ) } /> : null }
		</>
	);
}

export default function Page( { module }: { module: ModuleInfo } ): JSX.Element {
	const [ tab, setTab ] = useState( 'locations' );
	return (
		<>
			<Tabs
				label={ module.name }
				active={ tab }
				onChange={ setTab }
				items={ [
					{ id: 'locations', label: t( 'Locations' ) },
					{ id: 'settings', label: t( 'Settings' ) },
				] }
			/>
			<TabPanel id={ tab }>{ tab === 'settings' ? <SettingsPanel moduleId={ module.id } /> : <LocationsTab /> }</TabPanel>
		</>
	);
}
