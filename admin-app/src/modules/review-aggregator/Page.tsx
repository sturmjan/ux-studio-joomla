/**
 * Review Aggregator: list and moderate reviews (hide, edit, delete), add or import them by hand, pull them from the
 * UX1 Network app, overview with rating distribution; plus the settings.
 */
import { useRef, useState } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import { CloudDownload, Eye, EyeOff, Pencil, Plus, RefreshCw, ScanSearch, Star, Trash2, Upload } from 'lucide-react';
import { api, queryClient, type ModuleInfo } from '../../app/api';
import { createT, formatDate, intlLocale } from '../../app/i18n';
import { SettingsPanel } from '../../app/SettingsForm';
import { DataTable, Loading, Modal, TabPanel, Tabs, confirm, pushToast, type Column } from '../../app/ui';

type Review = {
	id: number;
	source: string;
	origin: string;
	author: string;
	rating: number;
	text: string;
	review_date: string | null;
	imported_at: string;
	visible: boolean;
};

interface ReviewPage {
	items: Review[];
	total: number;
	page: number;
	pages: number;
	sources: string[];
}

interface Stats {
	total: number;
	visible: number;
	average: number;
	by_source: Array< { source: string; count: number; average: number } >;
	distribution: Array< { rating: number; count: number } >;
	ca: { available: boolean; profile_id: number; last_fetch: { at: string; status: string; message: string } };
	shortcode: string;
}

interface Draft {
	id: number;
	source: string;
	author: string;
	rating: string;
	text: string;
	review_date: string;
}

const { t, tn } = createT( 'review-aggregator' );
const PER_PAGE = 20;
const KNOWN_SOURCES = [ 'google', 'facebook', 'mapycz', 'manual' ];

function sourceLabel( source: string ): string {
	const labels: Record< string, string > = { google: 'Google', facebook: 'Facebook', mapycz: 'Mapy.cz', manual: t( 'Manual' ) };
	return labels[ source ] ?? source;
}

const WRAP = { overflowWrap: 'anywhere', wordBreak: 'break-word', display: 'inline-block', maxWidth: 'min(420px, 60vw)' } as const;

function fmtAvg( n: number, digits: number ): string {
	return new Intl.NumberFormat( intlLocale(), { minimumFractionDigits: 1, maximumFractionDigits: digits } ).format( n );
}

function Stars( { rating }: { rating: number } ): JSX.Element {
	return (
		<span aria-label={ t( '{n} out of 5', { n: rating } ) } style={ { whiteSpace: 'nowrap' } }>
			{ Array.from( { length: 5 }, ( _, i ) => (
				<Star key={ i } size={ 13 } aria-hidden="true" fill={ i < rating ? 'currentColor' : 'none' } style={ { opacity: i < rating ? 1 : 0.35 } } />
			) ) }
		</span>
	);
}

function ReviewForm( { draft, onClose }: { draft: Draft | null; onClose: () => void } ): JSX.Element {
	const [ form, setForm ] = useState< Draft | null >( draft );
	const [ shown, setShown ] = useState< Draft | null >( null );
	// Re-initialise the form when a different draft is opened.
	if ( draft !== shown ) {
		setShown( draft );
		setForm( draft );
	}

	const inFlight = useRef( false );
	const save = useMutation( {
		mutationFn: ( d: Draft ) => {
			// Guard against double clicks: isPending only flips after the next render.
			if ( inFlight.current ) {
				return Promise.reject( new Error( '' ) );
			}
			inFlight.current = true;
			return api.post< Review >( d.id > 0 ? `review-aggregator/reviews/${ d.id }` : 'review-aggregator/reviews', {
				source: d.source,
				author: d.author,
				rating: Number( d.rating ),
				text: d.text,
				review_date: d.review_date,
			} ).finally( () => {
				inFlight.current = false;
			} );
		},
		onSuccess: () => {
			pushToast( 'success', t( 'Review saved.' ) );
			void queryClient.invalidateQueries( { queryKey: [ 'review-aggregator' ] } );
			onClose();
		},
		onError: ( e: Error ) => {
			if ( e.message !== '' ) {
				pushToast( 'error', e.message );
			}
		},
	} );

	const set = ( patch: Partial< Draft > ): void => setForm( ( f ) => ( f ? { ...f, ...patch } : f ) );

	return (
		<Modal
			open={ draft !== null }
			onClose={ onClose }
			title={ form && form.id > 0 ? t( 'Edit review' ) : t( 'Add a review' ) }
			actions={
				<>
					<button type="button" className="uxs-btn" onClick={ onClose }>
						{ t( 'Cancel' ) }
					</button>
					<button type="button" className="uxs-btn uxs-btn--primary" disabled={ ! form || save.isPending || form.rating === '' } onClick={ () => form && save.mutate( form ) }>
						{ t( 'Save' ) }
					</button>
				</>
			}
		>
			{ form ? (
				<div className="uxs-form">
					<div className="uxs-form__row">
						<label htmlFor="rv-source">{ t( 'Platform' ) }</label>
						<input id="rv-source" className="uxs-input" list="rv-sources" value={ form.source } maxLength={ 32 } onChange={ ( e ) => set( { source: e.target.value } ) } />
						<datalist id="rv-sources">
							{ KNOWN_SOURCES.map( ( s ) => (
								<option key={ s } value={ s } />
							) ) }
						</datalist>
					</div>
					<div className="uxs-form__row">
						<label htmlFor="rv-author">{ t( 'Author' ) }</label>
						<input id="rv-author" className="uxs-input" value={ form.author } maxLength={ 255 } onChange={ ( e ) => set( { author: e.target.value } ) } />
					</div>
					<div className="uxs-form__row">
						<label htmlFor="rv-rating">{ t( 'Rating' ) }</label>
						<select id="rv-rating" className="uxs-input" value={ form.rating } onChange={ ( e ) => set( { rating: e.target.value } ) }>
							<option value="">{ t( 'Choose…' ) }</option>
							{ [ 5, 4, 3, 2, 1 ].map( ( n ) => (
								<option key={ n } value={ String( n ) }>
									{ '★'.repeat( n ) } ({ n })
								</option>
							) ) }
						</select>
					</div>
					<div className="uxs-form__row">
						<label htmlFor="rv-date">{ t( 'Date' ) }</label>
						<input id="rv-date" className="uxs-input" type="date" value={ form.review_date } onChange={ ( e ) => set( { review_date: e.target.value } ) } />
					</div>
					<div className="uxs-form__row">
						<label htmlFor="rv-text">{ t( 'Text' ) }</label>
						<textarea id="rv-text" className="uxs-input" rows={ 5 } value={ form.text } maxLength={ 10000 } onChange={ ( e ) => set( { text: e.target.value } ) } />
					</div>
				</div>
			) : null }
		</Modal>
	);
}

function ReviewsTab(): JSX.Element {
	const [ page, setPage ] = useState( 1 );
	const [ search, setSearch ] = useState( '' );
	const [ term, setTerm ] = useState( '' );
	const [ source, setSource ] = useState( '' );
	const [ visible, setVisible ] = useState( '' );
	const [ draft, setDraft ] = useState< Draft | null >( null );

	const list = useQuery( {
		queryKey: [ 'review-aggregator', 'reviews', page, term, source, visible ],
		queryFn: () => api.get< ReviewPage >( 'review-aggregator/reviews', { page, per_page: PER_PAGE, q: term, source, visible } ),
	} );

	const refresh = (): void => void queryClient.invalidateQueries( { queryKey: [ 'review-aggregator' ] } );
	const toggle = useMutation( {
		mutationFn: ( id: number ) => api.post( `review-aggregator/reviews/${ id }/toggle-visibility` ),
		onSuccess: refresh,
		onError: ( e: Error ) => pushToast( 'error', e.message ),
	} );
	const remove = useMutation( {
		mutationFn: ( id: number ) => api.post( `review-aggregator/reviews/${ id }/delete` ),
		onSuccess: refresh,
		onError: ( e: Error ) => pushToast( 'error', e.message ),
	} );

	const columns: Column< Review >[] = [
		{ key: 'source', header: t( 'Platform' ), render: ( r ) => sourceLabel( r.source ) },
		{ key: 'author', header: t( 'Author' ), render: ( r ) => r.author ? <span style={ WRAP }>{ r.author }</span> : <span className="uxs-muted">{ t( 'Anonymous' ) }</span> },
		{ key: 'rating', header: t( 'Rating' ), render: ( r ) => <Stars rating={ r.rating } /> },
		{
			key: 'text',
			header: t( 'Text' ),
			render: ( r ) => <span title={ r.text } style={ WRAP }>{ r.text.length > 140 ? `${ r.text.slice( 0, 140 ) }…` : r.text }</span>,
		},
		{ key: 'review_date', header: t( 'Date' ), render: ( r ) => ( r.review_date ? formatDate( r.review_date ) : '' ) },
		{
			key: 'visible',
			header: t( 'Shown' ),
			render: ( r ) => <span className={ `uxs-badge ${ r.visible ? 'is-success' : '' }` }>{ r.visible ? t( 'Yes' ) : t( 'Hidden' ) }</span>,
		},
		{
			key: 'actions',
			header: '',
			render: ( r ) => (
				<span className="uxs-actions" style={ { flexWrap: 'nowrap' } }>
					<button type="button" className="uxs-btn uxs-btn--small" disabled={ toggle.isPending } onClick={ () => toggle.mutate( r.id ) } aria-label={ r.visible ? t( 'Hide review' ) : t( 'Show review' ) } title={ r.visible ? t( 'Hide review' ) : t( 'Show review' ) }>
						{ r.visible ? <EyeOff size={ 14 } aria-hidden="true" /> : <Eye size={ 14 } aria-hidden="true" /> }
					</button>
					{ r.origin === 'manual' || r.origin === 'import' ? (
						<button
							type="button"
							className="uxs-btn uxs-btn--small"
							aria-label={ t( 'Edit review' ) }
							title={ t( 'Edit review' ) }
							onClick={ () => setDraft( { id: r.id, source: r.source, author: r.author, rating: String( r.rating ), text: r.text, review_date: r.review_date ? r.review_date.slice( 0, 10 ) : '' } ) }
						>
							<Pencil size={ 14 } aria-hidden="true" />
						</button>
					) : null }
					<button
						type="button"
						className="uxs-btn uxs-btn--small"
						aria-label={ t( 'Delete review' ) }
						title={ t( 'Delete review' ) }
						onClick={ () =>
							void confirm( {
								message: r.origin === 'ca' ? t( 'Delete this review? It will come back with the next fetch from UX1 Network app. To keep it off the site, hide it instead.' ) : t( 'Delete this review? This cannot be undone.' ),
								danger: true,
								confirmLabel: t( 'Delete' ),
							} ).then( ( ok ) => ok && remove.mutate( r.id ) )
						}
					>
						<Trash2 size={ 14 } aria-hidden="true" />
					</button>
				</span>
			),
		},
	];

	const data = list.data;
	const sources = Array.from( new Set( [ ...KNOWN_SOURCES, ...( data?.sources ?? [] ) ] ) );

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
				<input type="search" className="uxs-input" value={ search } placeholder={ t( 'Search author or text' ) } aria-label={ t( 'Search' ) } onChange={ ( e ) => setSearch( e.target.value ) } />
				<select className="uxs-input" value={ source } aria-label={ t( 'Platform' ) } onChange={ ( e ) => { setPage( 1 ); setSource( e.target.value ); } }>
					<option value="">{ t( 'All platforms' ) }</option>
					{ sources.map( ( s ) => (
						<option key={ s } value={ s }>
							{ sourceLabel( s ) }
						</option>
					) ) }
				</select>
				<select className="uxs-input" value={ visible } aria-label={ t( 'Shown' ) } onChange={ ( e ) => { setPage( 1 ); setVisible( e.target.value ); } }>
					<option value="">{ t( 'Shown and hidden' ) }</option>
					<option value="1">{ t( 'Shown only' ) }</option>
					<option value="0">{ t( 'Hidden only' ) }</option>
				</select>
				<button type="submit" className="uxs-btn">
					{ t( 'Search' ) }
				</button>
				<button type="button" className="uxs-btn uxs-btn--primary" onClick={ () => setDraft( { id: 0, source: 'manual', author: '', rating: '', text: '', review_date: new Date().toISOString().slice( 0, 10 ) } ) }>
					<Plus size={ 14 } aria-hidden="true" /> { t( 'Add a review' ) }
				</button>
			</form>

			{ list.isLoading ? <Loading /> : null }
			{ list.error ? <p role="alert">{ list.error.message }</p> : null }
			{ data ? (
				<>
					<p className="uxs-muted uxs-list-toolbar__count">{ tn( '{n} review', '{n} reviews', data.total ) }</p>
					<DataTable columns={ columns } rows={ data.items } rowKey={ ( r ) => r.id } page={ page } pages={ data.pages } onPage={ setPage } empty={ t( 'No reviews yet. Add one, import a file, or fetch them from UX1 Network app.' ) } />
				</>
			) : null }

			<ReviewForm draft={ draft } onClose={ () => setDraft( null ) } />
		</>
	);
}

function OverviewTab(): JSX.Element {
	const stats = useQuery( { queryKey: [ 'review-aggregator', 'stats' ], queryFn: () => api.get< Stats >( 'review-aggregator/stats' ) } );
	const [ format, setFormat ] = useState< 'csv' | 'json' >( 'csv' );
	const [ importSource, setImportSource ] = useState( 'manual' );
	const [ data, setData ] = useState( '' );

	const refresh = (): void => void queryClient.invalidateQueries( { queryKey: [ 'review-aggregator' ] } );
	const fetchNow = useMutation( {
		mutationFn: () => api.post< { fetched: number; updated: number } >( 'review-aggregator/fetch' ),
		onSuccess: ( r ) => {
			pushToast( 'success', t( '{new} new, {updated} updated.', { new: r.fetched, updated: r.updated } ) );
			refresh();
		},
		onError: ( e: Error ) => {
			pushToast( 'error', e.message );
			refresh();
		},
	} );
	const scan = useMutation( {
		mutationFn: () => api.post< { profile_id: number; job_id: number; status: string } >( 'review-aggregator/request-scrape' ),
		onSuccess: ( r ) => {
			pushToast( 'success', t( 'Full scan requested (job #{job}). Press "Fetch reviews" when it has finished.', { job: r.job_id } ) );
			void queryClient.invalidateQueries( { queryKey: [ 'settings', 'review-aggregator' ] } );
			refresh();
		},
		onError: ( e: Error ) => pushToast( 'error', e.message ),
	} );
	const run = useMutation( {
		mutationFn: () => api.post< { fetched: number; updated: number; skipped: number; total: number } >( 'review-aggregator/import', { format, source: importSource, data } ),
		onSuccess: ( r ) => {
			pushToast( 'success', t( 'Imported: {new} new, {updated} updated, {skipped} skipped.', { new: r.fetched, updated: r.updated, skipped: r.skipped } ) );
			setData( '' );
			refresh();
		},
		onError: ( e: Error ) => pushToast( 'error', e.message ),
	} );

	const s = stats.data;
	const max = s ? Math.max( 1, ...s.distribution.map( ( d ) => d.count ) ) : 1;

	return (
		<>
			{ stats.isLoading ? <Loading /> : null }
			{ stats.error ? <p role="alert">{ stats.error.message }</p> : null }
			{ s ? (
				<>
					<div className="uxs-cards">
						<div className="uxs-card">
							<span className="uxs-card__label">{ t( 'Average rating' ) }</span>
							<span className="uxs-card__num">{ s.total > 0 ? fmtAvg( s.average, 1 ) : '-' }</span>
						</div>
						<div className="uxs-card">
							<span className="uxs-card__label">{ t( 'Reviews' ) }</span>
							<span className="uxs-card__num">{ s.total }</span>
						</div>
						<div className="uxs-card">
							<span className="uxs-card__label">{ t( 'Shown on the site' ) }</span>
							<span className="uxs-card__num">{ s.visible }</span>
						</div>
					</div>

					<h3 className="uxs-section-title">{ t( 'Ratings' ) }</h3>
					<div className="uxs-table-wrap">
						<table className="uxs-table">
							<tbody>
								{ s.distribution.map( ( d ) => (
									<tr key={ d.rating }>
										<th scope="row" style={ { width: 90 } }>
											{ d.rating } <Star size={ 12 } aria-hidden="true" fill="currentColor" />
										</th>
										<td>
											<progress max={ max } value={ d.count } aria-label={ t( '{n} out of 5', { n: d.rating } ) } style={ { width: '100%' } } />
										</td>
										<td style={ { width: 60 } }>{ d.count }</td>
									</tr>
								) ) }
							</tbody>
						</table>
					</div>

					{ s.by_source.length > 0 ? (
						<>
							<h3 className="uxs-section-title">{ t( 'By platform' ) }</h3>
							<DataTable
								columns={ [
									{ key: 'source', header: t( 'Platform' ), render: ( r: Stats[ 'by_source' ][ number ] ) => sourceLabel( r.source ) },
									{ key: 'count', header: t( 'Reviews' ) },
									{ key: 'average', header: t( 'Average rating' ), render: ( r: Stats[ 'by_source' ][ number ] ) => fmtAvg( r.average, 2 ) },
								] }
								rows={ s.by_source }
								rowKey={ ( r ) => r.source }
							/>
						</>
					) : null }

					<h3 className="uxs-section-title">{ t( 'UX1 Network app' ) }</h3>
					{ s.ca.available ? (
						<p className="uxs-muted">
							{ s.ca.profile_id > 0 ? t( 'Profile #{id}.', { id: s.ca.profile_id } ) : t( 'No profile yet: fill in the Google Maps address in the settings and request a full scan.' ) }{ ' ' }
							{ s.ca.last_fetch.at ? t( 'Last fetch: {when} ({result}).', { when: formatDate( s.ca.last_fetch.at ), result: s.ca.last_fetch.status === 'ok' ? s.ca.last_fetch.message : s.ca.last_fetch.message || t( 'failed' ) } ) : t( 'Not fetched yet.' ) }
						</p>
					) : (
						<div className="uxs-notice" role="status">
							{ t( 'This site is not connected to UX1 Network app, so reviews are not downloaded automatically. Connect it in UX1 Network app module, or add and import reviews by hand below.' ) }
						</div>
					) }
					<div className="uxs-actions" style={ { marginBottom: 'var(--uxs-sp-5)' } }>
						<button type="button" className="uxs-btn uxs-btn--primary" disabled={ ! s.ca.available || fetchNow.isPending } onClick={ () => fetchNow.mutate() }>
							<CloudDownload size={ 14 } aria-hidden="true" /> { fetchNow.isPending ? t( 'Fetching…' ) : t( 'Fetch reviews' ) }
						</button>
						<button type="button" className="uxs-btn" disabled={ ! s.ca.available || scan.isPending } onClick={ () => scan.mutate() } title={ t( 'Asks UX1 Network app to download the whole history of your Google reviews, not only the latest ones.' ) }>
							<ScanSearch size={ 14 } aria-hidden="true" /> { t( 'Request a full scan' ) }
						</button>
						<button type="button" className="uxs-btn" onClick={ refresh }>
							<RefreshCw size={ 14 } aria-hidden="true" /> { t( 'Refresh' ) }
						</button>
					</div>

					<h3 className="uxs-section-title">{ t( 'Import reviews' ) }</h3>
					<p className="uxs-form__help">
						{ t( 'Paste a CSV (first row = header with a "rating" column; other columns: author, text, date, source) or a JSON list. Reviews that are already stored are not duplicated.' ) }
					</p>
					<div className="uxs-form">
						<div className="uxs-form__row">
							<label htmlFor="rv-fmt">{ t( 'Format' ) }</label>
							<select id="rv-fmt" className="uxs-input" value={ format } onChange={ ( e ) => setFormat( e.target.value === 'json' ? 'json' : 'csv' ) }>
								<option value="csv">CSV</option>
								<option value="json">JSON</option>
							</select>
						</div>
						<div className="uxs-form__row">
							<label htmlFor="rv-isrc">{ t( 'Platform for rows without one' ) }</label>
							<input id="rv-isrc" className="uxs-input" list="rv-sources2" value={ importSource } maxLength={ 32 } onChange={ ( e ) => setImportSource( e.target.value ) } />
							<datalist id="rv-sources2">
								{ KNOWN_SOURCES.map( ( x ) => (
									<option key={ x } value={ x } />
								) ) }
							</datalist>
						</div>
						<div className="uxs-form__row">
							<label htmlFor="rv-data">{ t( 'Data' ) }</label>
							<textarea id="rv-data" className="uxs-input" rows={ 8 } value={ data } spellCheck={ false } placeholder={ format === 'csv' ? 'rating;author;text;date\n5;Jana;Skvělé služby;2026-10-01' : '[{"rating":5,"author":"Jana","text":"Skvělé služby","date":"2026-10-01"}]' } onChange={ ( e ) => setData( e.target.value ) } />
						</div>
						<div className="uxs-form__actions">
							<button type="button" className="uxs-btn uxs-btn--primary" disabled={ run.isPending || data.trim() === '' } onClick={ () => run.mutate() }>
								<Upload size={ 14 } aria-hidden="true" /> { run.isPending ? t( 'Importing…' ) : t( 'Import' ) }
							</button>
						</div>
					</div>

					<h3 className="uxs-section-title">{ t( 'Show reviews on the site' ) }</h3>
					<p className="uxs-form__help">
						{ t( 'Add the module "UX1 Studio - Reviews" to a position (Content - Site Modules), or put {tag} into an article or a Custom module (there turn on "Prepare Content"). The tag accepts layout (grid, list, slider, badge), count, min_rating, order and platform, for example {example}.', {
							tag: s.shortcode,
							example: '{uxstudio_reviews layout=slider count=6 min_rating=4}',
						} ) }
					</p>
				</>
			) : null }
		</>
	);
}

export default function Page( { module }: { module: ModuleInfo } ): JSX.Element {
	const [ tab, setTab ] = useState( 'reviews' );

	return (
		<>
			<Tabs
				label={ module.name }
				active={ tab }
				onChange={ setTab }
				items={ [
					{ id: 'reviews', label: t( 'Reviews' ) },
					{ id: 'overview', label: t( 'Overview and import' ) },
					{ id: 'settings', label: t( 'Settings' ) },
				] }
			/>
			{ tab === 'reviews' ? (
				<TabPanel id="reviews">
					<ReviewsTab />
				</TabPanel>
			) : null }
			{ tab === 'overview' ? (
				<TabPanel id="overview">
					<OverviewTab />
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
