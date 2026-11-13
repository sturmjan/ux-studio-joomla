/** Editor of one location: basics (+ geocoding), weekly hours, exceptions and dated announcements. */
import { useRef, useState } from 'react';
import { useMutation } from '@tanstack/react-query';
import { Copy, MapPin, Plus, Trash2 } from 'lucide-react';
import { api, ApiError } from '../../app/api';
import { createT } from '../../app/i18n';
import { Modal, Tabs, ToggleSwitch, pushToast } from '../../app/ui';
import { DAYS, type Announcement, type DayKey, type Draft, type Exception, type Location, type Range, type Severity } from './types';

const { t } = createT( 'opening-hours' );

function errorMessage( e: unknown ): string {
	return e instanceof ApiError || e instanceof Error ? e.message : t( 'Something went wrong.' );
}

function dayLabel( day: DayKey ): string {
	switch ( day ) {
		case 'mon':
			return t( 'Monday' );
		case 'tue':
			return t( 'Tuesday' );
		case 'wed':
			return t( 'Wednesday' );
		case 'thu':
			return t( 'Thursday' );
		case 'fri':
			return t( 'Friday' );
		case 'sat':
			return t( 'Saturday' );
		default:
			return t( 'Sunday' );
	}
}

/** The ranges of one day (or exception): time inputs with add / remove. */
function RangeList( { ranges, onChange, label }: { ranges: Range[]; onChange: ( next: Range[] ) => void; label: string } ): JSX.Element {
	const set = ( i: number, patch: Partial< Range > ): void => onChange( ranges.map( ( r, ix ) => ( ix === i ? { ...r, ...patch } : r ) ) );
	return (
		<span style={ { display: 'inline-flex', flexWrap: 'wrap', gap: 'var(--uxs-sp-2)', alignItems: 'center' } }>
			{ ranges.length === 0 ? <span className="uxs-muted">{ t( 'Closed' ) }</span> : null }
			{ ranges.map( ( r, i ) => (
				// eslint-disable-next-line react/no-array-index-key
				<span key={ i } style={ { display: 'inline-flex', gap: 'var(--uxs-sp-1)', alignItems: 'center' } }>
					<input type="time" aria-label={ `${ label }: ${ t( 'Opens' ) }` } value={ r.open } onChange={ ( e ) => set( i, { open: e.target.value } ) } />
					–
					<input type="time" aria-label={ `${ label }: ${ t( 'Closes' ) }` } value={ r.close } onChange={ ( e ) => set( i, { close: e.target.value } ) } />
					<button type="button" className="uxs-btn uxs-btn--small" aria-label={ t( 'Remove' ) } title={ t( 'Remove' ) } onClick={ () => onChange( ranges.filter( ( _, ix ) => ix !== i ) ) }>
						<Trash2 size={ 12 } aria-hidden="true" />
					</button>
				</span>
			) ) }
			<button type="button" className="uxs-btn uxs-btn--small" onClick={ () => onChange( [ ...ranges, { open: ranges.length ? ranges[ ranges.length - 1 ]?.close ?? '09:00' : '09:00', close: '17:00' } ] ) }>
				<Plus size={ 12 } aria-hidden="true" /> { t( 'Add hours' ) }
			</button>
		</span>
	);
}

export function LocationModal( { draft, onClose, onSaved }: { draft: Draft; onClose: () => void; onSaved: () => void } ): JSX.Element {
	const [ d, setD ] = useState< Draft >( draft );
	const [ tab, setTab ] = useState( 'basic' );
	const [ error, setError ] = useState( '' );

	// Guards a double click: `isPending` only flips after a re-render, so a second click in the same tick would post twice.
	const busy = useRef( false );
	const save = useMutation( {
		mutationFn: () => ( d.id ? api.post< Location >( `opening-hours/locations/${ d.id }`, d ) : api.post< Location >( 'opening-hours/locations', d ) ),
		onSuccess: () => {
			pushToast( 'success', t( 'Location saved.' ) );
			onSaved();
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

	const geocode = useMutation( {
		mutationFn: () => api.get< { lat: number; lng: number; formatted_address: string } >( 'opening-hours/geocode', { address: d.address } ),
		onSuccess: ( res ) => {
			setD( ( cur ) => ( { ...cur, lat: res.lat, lng: res.lng } ) );
			pushToast( 'success', t( 'Found: {address}', { address: res.formatted_address } ) );
		},
		onError: ( e ) => pushToast( 'error', errorMessage( e ) ),
	} );

	const setDay = ( day: DayKey, ranges: Range[] ): void => setD( ( cur ) => ( { ...cur, hours: { ...cur.hours, [ day ]: ranges } } ) );
	const setExc = ( i: number, patch: Partial< Exception > ): void => setD( ( cur ) => ( { ...cur, exceptions: cur.exceptions.map( ( x, ix ) => ( ix === i ? { ...x, ...patch } : x ) ) } ) );
	const setAnn = ( i: number, patch: Partial< Announcement > ): void => setD( ( cur ) => ( { ...cur, announcements: cur.announcements.map( ( x, ix ) => ( ix === i ? { ...x, ...patch } : x ) ) } ) );

	const copyMonday = (): void =>
		setD( ( cur ) => {
			const monday = cur.hours.mon;
			return { ...cur, hours: { ...cur.hours, tue: [ ...monday ], wed: [ ...monday ], thu: [ ...monday ], fri: [ ...monday ] } };
		} );

	return (
		<Modal
			open
			wide
			onClose={ onClose }
			title={ d.id ? t( 'Edit location' ) : t( 'Add location' ) }
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
			<Tabs
				label={ t( 'Location' ) }
				active={ tab }
				onChange={ setTab }
				items={ [
					{ id: 'basic', label: t( 'Basics' ) },
					{ id: 'hours', label: t( 'Weekly hours' ) },
					{ id: 'exceptions', label: t( 'Exceptions' ) },
					{ id: 'announcements', label: t( 'Announcements' ) },
				] }
			/>
			<div className="uxs-modal__body uxs-form uxs-form--grid" style={ { border: 'none', padding: 0, background: 'transparent' } }>
				{ tab === 'basic' ? (
					<>
						<div className="uxs-form__row">
							<label htmlFor="uxs-oh-title">{ t( 'Name' ) }</label>
							<input id="uxs-oh-title" type="text" maxLength={ 190 } value={ d.title } onChange={ ( e ) => setD( { ...d, title: e.target.value } ) } />
						</div>
						<div className="uxs-form__row">
							<label htmlFor="uxs-oh-address">{ t( 'Address' ) }</label>
							<span className="uxs-actions">
								<input id="uxs-oh-address" type="text" maxLength={ 255 } value={ d.address } onChange={ ( e ) => setD( { ...d, address: e.target.value } ) } />
								<button type="button" className="uxs-btn" disabled={ ! d.address.trim() || geocode.isPending } onClick={ () => geocode.mutate() }>
									<MapPin size={ 14 } aria-hidden="true" /> { t( 'Find coordinates' ) }
								</button>
							</span>
							<p className="uxs-form__help">{ t( 'Coordinates need a Google Maps or Mapy.cz API key in Settings. You can also type them in.' ) }</p>
						</div>
						<div className="uxs-form__row">
							<label htmlFor="uxs-oh-phone">{ t( 'Phone' ) }</label>
							<input id="uxs-oh-phone" type="text" maxLength={ 60 } value={ d.phone } onChange={ ( e ) => setD( { ...d, phone: e.target.value } ) } />
						</div>
						<div className="uxs-form__row">
							<label htmlFor="uxs-oh-lat">{ t( 'Latitude' ) }</label>
							<input id="uxs-oh-lat" type="number" step="0.0000001" min={ -90 } max={ 90 } value={ d.lat ?? '' } onChange={ ( e ) => setD( { ...d, lat: e.target.value === '' ? null : Number( e.target.value ) } ) } />
						</div>
						<div className="uxs-form__row">
							<label htmlFor="uxs-oh-lng">{ t( 'Longitude' ) }</label>
							<input id="uxs-oh-lng" type="number" step="0.0000001" min={ -180 } max={ 180 } value={ d.lng ?? '' } onChange={ ( e ) => setD( { ...d, lng: e.target.value === '' ? null : Number( e.target.value ) } ) } />
						</div>
					</>
				) : null }

				{ tab === 'hours' ? (
					<>
						<p className="uxs-muted">{ t( 'A day without hours is closed. A range that ends before it starts (20:00–02:00) continues after midnight.' ) }</p>
						<div className="uxs-table-wrap">
							<table className="uxs-table">
								<tbody>
									{ DAYS.map( ( day ) => (
										<tr key={ day }>
											<th scope="row">{ dayLabel( day ) }</th>
											<td>
												<RangeList ranges={ d.hours[ day ] } label={ dayLabel( day ) } onChange={ ( next ) => setDay( day, next ) } />
											</td>
										</tr>
									) ) }
								</tbody>
							</table>
						</div>
						<div className="uxs-actions" style={ { marginTop: 'var(--uxs-sp-2)' } }>
							<button type="button" className="uxs-btn" onClick={ copyMonday }>
								<Copy size={ 14 } aria-hidden="true" /> { t( 'Copy Monday to Tuesday – Friday' ) }
							</button>
						</div>
					</>
				) : null }

				{ tab === 'exceptions' ? (
					<>
						<p className="uxs-muted">{ t( 'An exception replaces the weekly hours of that date. Czech public holidays are closed automatically (see Settings), an exception for the same date overrides that.' ) }</p>
						{ d.exceptions.map( ( x, i ) => (
							// eslint-disable-next-line react/no-array-index-key
							<div key={ i } className="uxs-card" style={ { marginBottom: 'var(--uxs-sp-2)' } }>
								<div className="uxs-actions">
									<input type="date" aria-label={ t( 'Date' ) } value={ x.date } onChange={ ( e ) => setExc( i, { date: e.target.value } ) } />
									<input type="text" aria-label={ t( 'Label' ) } placeholder={ t( 'Label (e.g. Stocktaking)' ) } maxLength={ 120 } value={ x.label } onChange={ ( e ) => setExc( i, { label: e.target.value } ) } />
									<ToggleSwitch checked={ x.closed } onChange={ ( v ) => setExc( i, { closed: v } ) } label={ t( 'Closed all day' ) } />
									<button type="button" className="uxs-btn uxs-btn--small uxs-btn--danger" aria-label={ t( 'Remove' ) } title={ t( 'Remove' ) } onClick={ () => setD( { ...d, exceptions: d.exceptions.filter( ( _, ix ) => ix !== i ) } ) }>
										<Trash2 size={ 14 } aria-hidden="true" />
									</button>
								</div>
								{ x.closed ? null : (
									<div style={ { marginTop: 'var(--uxs-sp-2)' } }>
										<RangeList ranges={ x.hours } label={ x.date || t( 'Exception' ) } onChange={ ( next ) => setExc( i, { hours: next } ) } />
									</div>
								) }
							</div>
						) ) }
						<button type="button" className="uxs-btn" onClick={ () => setD( { ...d, exceptions: [ ...d.exceptions, { date: '', closed: true, label: '', hours: [] } ] } ) }>
							<Plus size={ 14 } aria-hidden="true" /> { t( 'Add exception' ) }
						</button>
					</>
				) : null }

				{ tab === 'announcements' ? (
					<>
						<p className="uxs-muted">{ t( 'A short notice shown on the card while its dates apply. With several active notices the most severe one is shown.' ) }</p>
						{ d.announcements.map( ( a, i ) => (
							// eslint-disable-next-line react/no-array-index-key
							<div key={ i } className="uxs-card" style={ { marginBottom: 'var(--uxs-sp-2)' } }>
								<div className="uxs-actions">
									<input type="text" aria-label={ t( 'Notice' ) } placeholder={ t( 'Notice text' ) } maxLength={ 300 } value={ a.text } onChange={ ( e ) => setAnn( i, { text: e.target.value } ) } />
									<select aria-label={ t( 'Severity' ) } value={ a.severity } onChange={ ( e ) => setAnn( i, { severity: e.target.value as Severity } ) }>
										<option value="info">{ t( 'Info' ) }</option>
										<option value="warning">{ t( 'Warning' ) }</option>
										<option value="critical">{ t( 'Critical' ) }</option>
									</select>
									<button type="button" className="uxs-btn uxs-btn--small uxs-btn--danger" aria-label={ t( 'Remove' ) } title={ t( 'Remove' ) } onClick={ () => setD( { ...d, announcements: d.announcements.filter( ( _, ix ) => ix !== i ) } ) }>
										<Trash2 size={ 14 } aria-hidden="true" />
									</button>
								</div>
								<div className="uxs-actions" style={ { marginTop: 'var(--uxs-sp-2)' } }>
									<label>
										{ t( 'From' ) } <input type="date" value={ a.from } onChange={ ( e ) => setAnn( i, { from: e.target.value } ) } />
									</label>
									<label>
										{ t( 'To' ) } <input type="date" value={ a.to } onChange={ ( e ) => setAnn( i, { to: e.target.value } ) } />
									</label>
								</div>
							</div>
						) ) }
						<button type="button" className="uxs-btn" onClick={ () => setD( { ...d, announcements: [ ...d.announcements, { text: '', from: '', to: '', severity: 'info' } ] } ) }>
							<Plus size={ 14 } aria-hidden="true" /> { t( 'Add notice' ) }
						</button>
					</>
				) : null }

				{ error ? (
					<p role="alert" className="uxs-inline-warning">
						{ error }
					</p>
				) : null }
			</div>
		</Modal>
	);
}
