/**
 * Page load: real-user timing overview (percentiles, trend, devices), the slowest pages and the settings.
 */
import { useState } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import { RefreshCw, Trash2 } from 'lucide-react';
import { api, queryClient, type ModuleInfo } from '../../app/api';
import { createT } from '../../app/i18n';
import { SettingsPanel } from '../../app/SettingsForm';
import { DataTable, Loading, TabPanel, Tabs, confirm, pushToast, type Column } from '../../app/ui';

type Rating = 'good' | 'ok' | 'poor' | 'none';

interface MetricStat {
	samples: number;
	median: number;
	p75: number;
	rating: Rating;
}

interface Summary {
	period: string;
	samples: number;
	slow_ms: number;
	sample_rate: number;
	metrics: Record< 'ttfb_ms' | 'fcp_ms' | 'lcp_ms' | 'dcl_ms' | 'load_ms' | 'cls_milli', MetricStat >;
	devices: Array< { device: string; samples: number; median_load: number } >;
	trend: Array< { day: string; samples: number; load_avg: number; ttfb_avg: number } >;
}

interface PageRow extends Record< string, unknown > {
	path: string;
	samples: number;
	load_avg: number;
	load_max: number;
	ttfb_avg: number;
	fcp_avg: number;
	lcp_avg: number;
}

const { t } = createT( 'page-load' );

const BADGE: Record< Rating, string > = { good: 'is-success', ok: 'is-warning', poor: 'is-danger', none: 'is-info' };

function errorText( e: unknown ): string {
	return e instanceof Error ? e.message : String( e );
}

function ms( n: number ): string {
	if ( n <= 0 ) {
		return '-';
	}
	return n >= 1000 ? ( n / 1000 ).toFixed( 2 ) + ' s' : n + ' ms';
}

function periodLabel( p: string ): string {
	return { '1d': t( 'Last 24 hours' ), '7d': t( 'Last 7 days' ), '30d': t( 'Last 30 days' ), '90d': t( 'Last 90 days' ) }[ p ] ?? p;
}

function deviceLabel( d: string ): string {
	return { mobile: t( 'Mobile' ), tablet: t( 'Tablet' ), desktop: t( 'Desktop' ), unknown: t( 'Unknown' ) }[ d ] ?? d;
}

function ratingLabel( r: Rating ): string {
	return { good: t( 'Good' ), ok: t( 'Needs work' ), poor: t( 'Poor' ), none: t( 'No data' ) }[ r ];
}

function PeriodSelect( { value, onChange }: { value: string; onChange: ( v: string ) => void } ): JSX.Element {
	return (
		<select value={ value } aria-label={ t( 'Period' ) } onChange={ ( e ) => onChange( e.target.value ) }>
			{ [ '1d', '7d', '30d', '90d' ].map( ( p ) => (
				<option key={ p } value={ p }>{ periodLabel( p ) }</option>
			) ) }
		</select>
	);
}

function OverviewTab(): JSX.Element {
	const [ period, setPeriod ] = useState( '7d' );
	const q = useQuery( { queryKey: [ 'page-load', 'summary', period ], queryFn: () => api.get< Summary >( 'page-load/summary', { period } ) } );
	const clear = useMutation( {
		mutationFn: () => api.post( 'page-load/clear', {} ),
		onSuccess: () => {
			pushToast( 'success', t( 'All measurements were deleted.' ) );
			void queryClient.invalidateQueries( { queryKey: [ 'page-load' ] } );
		},
		onError: ( e ) => pushToast( 'error', errorText( e ) ),
	} );

	if ( q.isLoading ) {
		return <Loading />;
	}
	const s = q.data;
	if ( ! s ) {
		return <p role="alert">{ errorText( q.error ) }</p>;
	}
	const m = s.metrics;
	const maxLoad = Math.max( 1, ...s.trend.map( ( d ) => d.load_avg ) );

	const card = ( label: string, stat: MetricStat, format: ( n: number ) => string ): JSX.Element => (
		<div className="uxs-card">
			<span className="uxs-card__label">{ label }</span>
			<span className="uxs-card__num">{ format( stat.p75 ) }</span>
			<span className={ `uxs-badge ${ BADGE[ stat.rating ] }` }>{ ratingLabel( stat.rating ) }</span>
		</div>
	);

	return (
		<>
			<div className="uxs-actions" style={ { marginBottom: 'var(--uxs-sp-4)' } }>
				<PeriodSelect value={ period } onChange={ setPeriod } />
				<button type="button" className="uxs-btn" onClick={ () => void q.refetch() } disabled={ q.isFetching }>
					<RefreshCw size={ 14 } aria-hidden="true" /> { t( 'Refresh' ) }
				</button>
				<button
					type="button"
					className="uxs-btn uxs-btn--danger"
					disabled={ clear.isPending || s.samples === 0 }
					onClick={ async () => {
						if ( await confirm( { message: t( 'Delete all stored measurements?' ), confirmLabel: t( 'Delete' ) } ) ) {
							clear.mutate();
						}
					} }
				>
					<Trash2 size={ 14 } aria-hidden="true" /> { t( 'Delete data' ) }
				</button>
			</div>

			{ s.sample_rate <= 0 ? <div className="uxs-notice" role="alert">{ t( 'Measuring is switched off (share of measured views is 0 %). Raise it in the settings.' ) }</div> : null }

			{ s.samples === 0 ? (
				<p className="uxs-muted">{ t( 'No measurements yet. Visit the site as an anonymous visitor (not logged in) and come back in a few seconds; with a share of {rate} % it may take several visits.', { rate: s.sample_rate } ) }</p>
			) : (
				<>
					<p className="uxs-muted">{ t( '{n} measurements. Values are the 75th percentile: three of four visits were at least this fast.', { n: s.samples } ) }</p>
					<div className="uxs-cards">
						<div className="uxs-card">
							<span className="uxs-card__label">{ t( 'Page load (median)' ) }</span>
							<span className="uxs-card__num">{ ms( m.load_ms.median ) }</span>
							<span className="uxs-muted">p75: { ms( m.load_ms.p75 ) }</span>
						</div>
						{ card( t( 'Server response (TTFB)' ), m.ttfb_ms, ms ) }
						{ card( t( 'First contentful paint' ), m.fcp_ms, ms ) }
						{ card( t( 'Largest contentful paint' ), m.lcp_ms, ms ) }
						{ card( t( 'Layout shift (CLS)' ), m.cls_milli, ( n ) => ( n > 0 ? ( n / 1000 ).toFixed( 3 ) : '-' ) ) }
					</div>

					<h2 className="uxs-form__section" style={ { marginTop: 'var(--uxs-sp-6)' } }>{ t( 'Devices' ) }</h2>
					<div className="uxs-table-wrap">
						<table className="uxs-table">
							<thead>
								<tr><th>{ t( 'Device' ) }</th><th>{ t( 'Measurements' ) }</th><th>{ t( 'Median load time' ) }</th></tr>
							</thead>
							<tbody>
								{ s.devices.map( ( d ) => (
									<tr key={ d.device }><td>{ deviceLabel( d.device ) }</td><td>{ d.samples }</td><td>{ ms( d.median_load ) }</td></tr>
								) ) }
							</tbody>
						</table>
					</div>

					<h2 className="uxs-form__section" style={ { marginTop: 'var(--uxs-sp-6)' } }>{ t( 'Trend (average load time per day)' ) }</h2>
					<div className="uxs-table-wrap">
						<table className="uxs-table">
							<thead>
								<tr><th>{ t( 'Day' ) }</th><th>{ t( 'Measurements' ) }</th><th>{ t( 'Server response (TTFB)' ) }</th><th>{ t( 'Page load' ) }</th></tr>
							</thead>
							<tbody>
								{ s.trend.map( ( d ) => (
									<tr key={ d.day }>
										<td>{ d.day }</td>
										<td>{ d.samples }</td>
										<td>{ ms( d.ttfb_avg ) }</td>
										<td>
											<progress max={ maxLoad } value={ d.load_avg } style={ { width: '8rem', verticalAlign: 'middle' } } aria-label={ ms( d.load_avg ) } /> { ms( d.load_avg ) }
										</td>
									</tr>
								) ) }
							</tbody>
						</table>
					</div>
				</>
			) }
		</>
	);
}

function PagesTab(): JSX.Element {
	const [ period, setPeriod ] = useState( '7d' );
	const [ sort, setSort ] = useState( 'load_ms' );
	const [ min, setMin ] = useState( 1 );
	const q = useQuery( {
		queryKey: [ 'page-load', 'pages', period, sort, min ],
		queryFn: () => api.get< { items: PageRow[] } >( 'page-load/pages', { period, sort, min } ),
	} );
	const slow = useQuery( { queryKey: [ 'page-load', 'summary', '7d' ], queryFn: () => api.get< Summary >( 'page-load/summary', { period: '7d' } ) } ).data?.slow_ms ?? 3000;

	const load = ( n: number ): JSX.Element => <span className={ `uxs-badge ${ n > slow * 2 ? 'is-danger' : n > slow ? 'is-warning' : 'is-success' }` }>{ ms( n ) }</span>;
	const columns: Column< PageRow >[] = [
		{ key: 'path', header: t( 'Page' ), render: ( r ) => <code>{ r.path }</code> },
		{ key: 'samples', header: t( 'Measurements' ), sortable: true },
		{ key: 'ttfb_avg', header: t( 'Server response (TTFB)' ), sortable: true, render: ( r ) => ms( r.ttfb_avg ) },
		{ key: 'fcp_avg', header: t( 'First contentful paint' ), sortable: true, render: ( r ) => ms( r.fcp_avg ) },
		{ key: 'lcp_avg', header: t( 'Largest contentful paint' ), sortable: true, render: ( r ) => ms( r.lcp_avg ) },
		{ key: 'load_avg', header: t( 'Average load time' ), sortable: true, render: ( r ) => load( r.load_avg ) },
		{ key: 'load_max', header: t( 'Slowest visit' ), sortable: true, render: ( r ) => ms( r.load_max ) },
	];

	return (
		<>
			<div className="uxs-actions" style={ { marginBottom: 'var(--uxs-sp-4)' } }>
				<PeriodSelect value={ period } onChange={ setPeriod } />
				<select value={ sort } aria-label={ t( 'Rank by' ) } onChange={ ( e ) => setSort( e.target.value ) }>
					<option value="load_ms">{ t( 'Rank by load time' ) }</option>
					<option value="ttfb_ms">{ t( 'Rank by server response' ) }</option>
					<option value="fcp_ms">{ t( 'Rank by first contentful paint' ) }</option>
					<option value="lcp_ms">{ t( 'Rank by largest contentful paint' ) }</option>
				</select>
				<select value={ min } aria-label={ t( 'Minimum measurements' ) } onChange={ ( e ) => setMin( Number( e.target.value ) ) }>
					<option value={ 1 }>{ t( 'Any number of measurements' ) }</option>
					<option value={ 3 }>{ t( 'At least 3 measurements' ) }</option>
					<option value={ 10 }>{ t( 'At least 10 measurements' ) }</option>
				</select>
			</div>
			<p className="uxs-muted">{ t( 'Visits that were closed before the page finished loading are not measured, so the slowest pages may look better than they are.' ) }</p>
			{ q.isLoading ? <Loading /> : null }
			{ q.error ? <p role="alert">{ errorText( q.error ) }</p> : null }
			<DataTable columns={ columns } rows={ q.data?.items ?? [] } rowKey={ ( r ) => r.path } empty={ t( 'No measurements for this period.' ) } pageSize={ 25 } />
		</>
	);
}

export default function Page( { module }: { module: ModuleInfo } ): JSX.Element {
	const [ tab, setTab ] = useState( 'overview' );
	return (
		<>
			<Tabs
				label={ module.name }
				active={ tab }
				onChange={ setTab }
				items={ [
					{ id: 'overview', label: t( 'Overview' ) },
					{ id: 'pages', label: t( 'Slowest pages' ) },
					{ id: 'settings', label: t( 'Settings' ) },
				] }
			/>
			<TabPanel id={ tab }>
				{ tab === 'overview' ? <OverviewTab /> : null }
				{ tab === 'pages' ? <PagesTab /> : null }
				{ tab === 'settings' ? <SettingsPanel moduleId={ module.id } /> : null }
			</TabPanel>
		</>
	);
}
