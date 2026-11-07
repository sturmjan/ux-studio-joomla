/**
 * Visitor stats overview: period switcher + 3 cards (Visitors, Views, Live) + a dual bar chart (visitors + views),
 * plus rankings and breakdowns. Look and layout follow the WordPress version.
 */
import { useEffect, useRef, useState } from 'react';
import type { CSSProperties } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Eye, Globe, LoaderCircle, RefreshCw, TrendingDown, TrendingUp, Users } from 'lucide-react';
import { api, ApiError } from '../../app/api';
import { createT, intlLocale } from '../../app/i18n';
import { Loading, pushToast } from '../../app/ui';

const { t } = createT( 'analytics' );

type DailyPoint = { day: string; views: number; uniq: number };
type TopRow = { path?: string; referer_host?: string; views: number; visitors: number };
type DimRow = { val: string; views: number; visitors: number };
type Summary = {
	range: { from: string; to: string; period: string; days: number };
	totals: { views: number; visitors: number };
	previous: { views: number; visitors: number };
	realtime: number;
	daily: DailyPoint[];
	top_pages: TopRow[];
	referers: TopRow[];
	breakdowns: Record< string, DimRow[] >;
};
type GeoStatus = { enabled: boolean; ready: boolean; rows: number; updated: string; can_manage: boolean };

/** Marker the server stores for an unknown value of a dimension. */
const UNKNOWN = '-';

const flag = ( cc: string ): string =>
	/^[A-Za-z]{2}$/.test( cc )
		? String.fromCodePoint( ...cc.toUpperCase().split( '' ).map( ( c ) => 0x1f1e6 + c.charCodeAt( 0 ) - 65 ) )
		: '';

const periods = (): { key: string; label: string }[] => [
	{ key: 'today', label: t( 'Today' ) },
	{ key: 'yesterday', label: t( 'Yesterday' ) },
	{ key: '7d', label: t( '7 days' ) },
	{ key: '14d', label: t( '14 days' ) },
	{ key: '30d', label: t( '30 days' ) },
	{ key: 'month', label: t( 'This month' ) },
];

/** Categorical palette for donut breakdowns, built only from the shared design tokens. */
const CAT_COLORS = [ 'var(--uxs-brand)', 'var(--uxs-info)', 'var(--uxs-success)', 'var(--uxs-warning)', 'var(--uxs-danger)', 'var(--uxs-brand-strong)' ];
const CAT_OTHER = 'var(--uxs-text-soft)';

function useCountUp( target: number, duration = 650 ): number {
	const [ val, setVal ] = useState( target );
	const fromRef = useRef( target );
	const rafRef = useRef< number | undefined >( undefined );

	useEffect( () => {
		const from = fromRef.current;
		const start = performance.now();
		const tick = ( now: number ): void => {
			const p = Math.min( 1, ( now - start ) / duration );
			const eased = 1 - Math.pow( 1 - p, 3 );
			setVal( Math.round( from + ( target - from ) * eased ) );
			if ( p < 1 ) {
				rafRef.current = requestAnimationFrame( tick );
			} else {
				fromRef.current = target;
			}
		};
		rafRef.current = requestAnimationFrame( tick );
		return () => {
			if ( rafRef.current ) {
				cancelAnimationFrame( rafRef.current );
			}
			fromRef.current = target;
		};
	}, [ target, duration ] );

	return val;
}

const fmt = ( n: number ): string => new Intl.NumberFormat( intlLocale() ).format( n );

const parseDay = ( iso: string ): Date => new Date( iso + 'T00:00:00' );
const shortDate = ( iso: string ): string => {
	const d = parseDay( iso );
	return Number.isNaN( d.getTime() ) ? iso : d.toLocaleDateString( intlLocale(), { day: 'numeric', month: 'numeric' } );
};
const fullDate = ( iso: string ): string => {
	const d = parseDay( iso );
	return Number.isNaN( d.getTime() ) ? iso : d.toLocaleDateString( intlLocale(), { weekday: 'short', day: 'numeric', month: 'numeric' } );
};

const softText = { color: 'var(--uxs-text-soft)' } as const;

function DeltaBadge( { cur, prev }: { cur: number; prev: number } ): JSX.Element | null {
	if ( prev <= 0 ) {
		return null;
	}
	const pct = Math.round( ( ( cur - prev ) / prev ) * 100 );
	const up = pct >= 0;
	return (
		<span
			style={ {
				display: 'inline-flex',
				alignItems: 'center',
				gap: 3,
				fontSize: 12,
				fontWeight: 600,
				padding: '2px 8px',
				borderRadius: 999,
				color: up ? 'var(--uxs-success)' : 'var(--uxs-danger)',
				background: `color-mix(in srgb, ${ up ? 'var(--uxs-success)' : 'var(--uxs-danger)' } 12%, transparent)`,
			} }
		>
			{ up ? <TrendingUp size={ 12 } aria-hidden="true" /> : <TrendingDown size={ 12 } aria-hidden="true" /> }
			{ up ? '+' : '' }
			{ pct }%
		</span>
	);
}

function prevSubtitle( cur: number, prev: number ): string {
	if ( prev <= 0 ) {
		return t( 'no data for the previous period' );
	}
	const diff = cur - prev;
	if ( diff === 0 ) {
		return t( 'same as the previous period' );
	}
	return diff > 0 ? t( '{n} more than the previous period', { n: fmt( diff ) } ) : t( '{n} fewer than the previous period', { n: fmt( -diff ) } );
}

function StatCard( {
	icon,
	label,
	value,
	prev,
	live,
	subtitle,
}: {
	icon: JSX.Element | null;
	label: string;
	value: number;
	prev?: number;
	live?: boolean;
	subtitle: string;
} ): JSX.Element {
	const animated = useCountUp( value );
	return (
		<div className="uxs-card" style={ { margin: 0, textAlign: 'left' } }>
			<div style={ { display: 'flex', justifyContent: 'space-between', alignItems: 'center', ...softText, fontSize: 12, textTransform: 'uppercase', letterSpacing: '.03em' } }>
				<span style={ { display: 'inline-flex', alignItems: 'center', gap: 6 } }>
					{ icon } { label }
				</span>
				{ live ? (
					<span style={ { display: 'inline-flex', alignItems: 'center', gap: 5, fontSize: 11 } }>
						<span style={ { width: 7, height: 7, borderRadius: '50%', background: 'var(--uxs-success)', display: 'inline-block' } } />
						{ t( 'Live' ) }
					</span>
				) : (
					prev !== undefined && <DeltaBadge cur={ value } prev={ prev } />
				) }
			</div>
			<div style={ { fontSize: 34, fontWeight: 700, lineHeight: 1.15, marginTop: 6 } }>{ fmt( animated ) }</div>
			<div style={ { ...softText, fontSize: 12.5, marginTop: 2 } }>{ subtitle }</div>
		</div>
	);
}

function Chart( { data }: { data: DailyPoint[] } ): JSX.Element {
	const H = 170;
	const max = Math.max( 1, ...data.map( ( d ) => d.views ) );
	const [ hover, setHover ] = useState< number | null >( null );

	if ( data.length === 0 ) {
		return <p style={ { ...softText, margin: 0 } }>{ t( 'No data yet.' ) }</p>;
	}

	const bar = ( height: number, opacity: number ): JSX.Element => (
		<div
			style={ {
				position: 'absolute',
				bottom: 0,
				left: 0,
				right: 0,
				height: Math.max( 2, height ),
				background: 'var(--uxs-brand)',
				opacity,
				borderRadius: '4px 4px 0 0',
				transition: 'height .3s var(--uxs-ease)',
			} }
		/>
	);

	return (
		<div>
			<div
				style={ { display: 'flex', alignItems: 'flex-end', gap: data.length > 20 ? 3 : 6, height: H } }
				onMouseLeave={ () => setHover( null ) }
				role="img"
				aria-label={ t( 'Visitors and views' ) }
			>
				{ data.map( ( d, i ) => {
					const viewsH = ( d.views / max ) * H;
					const uniqH = ( d.uniq / max ) * H;
					const active = hover === i;
					return (
						<div
							key={ d.day }
							onMouseEnter={ () => setHover( i ) }
							title={ `${ fullDate( d.day ) }: ${ fmt( d.uniq ) } / ${ fmt( d.views ) }` }
							style={ { flex: 1, position: 'relative', height: H, minWidth: 4, cursor: 'pointer' } }
						>
							{ bar( viewsH, active ? 0.4 : 0.28 ) }
							{ bar( uniqH, 1 ) }
							{ active && (
								<div
									style={ {
										position: 'absolute',
										bottom: Math.max( viewsH, uniqH ) + 8,
										left: '50%',
										transform: 'translateX(-50%)',
										background: 'var(--uxs-surface)',
										border: '1px solid var(--uxs-border)',
										borderRadius: 'var(--uxs-radius-s)',
										boxShadow: 'var(--uxs-shadow-2)',
										padding: 'var(--uxs-sp-2) var(--uxs-sp-3)',
										fontSize: 12,
										whiteSpace: 'nowrap',
										zIndex: 10,
									} }
								>
									<div style={ { fontWeight: 600, marginBottom: 4 } }>{ fullDate( d.day ) }</div>
									<div>
										{ t( 'Visitors' ) }: <strong>{ fmt( d.uniq ) }</strong>
									</div>
									<div>
										{ t( 'Views' ) }: <strong>{ fmt( d.views ) }</strong>
									</div>
								</div>
							) }
						</div>
					);
				} ) }
			</div>
			<div style={ { display: 'flex', justifyContent: 'space-between', ...softText, fontSize: 11, marginTop: 8 } }>
				<span>{ shortDate( data[ 0 ]?.day ?? '' ) }</span>
				<span>{ shortDate( data[ data.length - 1 ]?.day ?? '' ) }</span>
			</div>
		</div>
	);
}

function TopTable( { title, rows, dimLabel, getKey }: { title: string; rows: TopRow[]; dimLabel: string; getKey: ( r: TopRow ) => string } ): JSX.Element {
	return (
		<div className="uxs-card" style={ { margin: 0, textAlign: 'left' } }>
			<h2 style={ { fontSize: 15, marginTop: 0, marginBottom: 12, color: 'var(--uxs-text)' } }>{ title }</h2>
			{ rows.length === 0 ? (
				<p style={ { ...softText, margin: 0, fontSize: 13 } }>{ t( 'No data.' ) }</p>
			) : (
				<div className="uxs-table-wrap">
					<table className="uxs-table">
						<thead>
							<tr>
								<th style={ { width: 28 } }>#</th>
								<th style={ { textAlign: 'left' } }>{ dimLabel }</th>
								<th style={ { textAlign: 'right', whiteSpace: 'nowrap' } }>{ t( 'Visitors' ) }</th>
								<th style={ { textAlign: 'right', whiteSpace: 'nowrap' } }>{ t( 'Views' ) }</th>
							</tr>
						</thead>
						<tbody>
							{ rows.map( ( r, i ) => (
								<tr key={ getKey( r ) }>
									<td style={ softText }>{ i + 1 }</td>
									<td>
										<span
											style={ { display: 'inline-block', maxWidth: 340, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap', verticalAlign: 'bottom' } }
											title={ getKey( r ) }
										>
											{ getKey( r ) }
										</span>
									</td>
									<td style={ { textAlign: 'right', fontWeight: 600 } }>{ fmt( r.visitors ) }</td>
									<td style={ { textAlign: 'right' } }>{ fmt( r.views ) }</td>
								</tr>
							) ) }
						</tbody>
					</table>
				</div>
			) }
		</div>
	);
}

type Seg = { label: string; value: number; color: string };

function Donut( { segments, size = 128, thickness = 20 }: { segments: Seg[]; size?: number; thickness?: number } ): JSX.Element {
	const total = Math.max( 1, segments.reduce( ( s, x ) => s + x.value, 0 ) );
	const cx = size / 2;
	const r = ( size - thickness ) / 2;
	const C = 2 * Math.PI * r;
	const gap = segments.length > 1 ? 2 : 0;

	let acc = 0;
	const segs = segments.map( ( s ) => {
		const frac = s.value / total;
		const start = acc;
		acc += frac;
		return { ...s, dash: Math.max( 0, frac * C - gap ), rot: start * 360 - 90 };
	} );

	return (
		<svg width={ size } height={ size } viewBox={ `0 0 ${ size } ${ size }` } aria-hidden="true">
			{ segs.map( ( s, i ) => (
				<circle
					key={ i }
					cx={ cx }
					cy={ cx }
					r={ r }
					fill="none"
					stroke={ s.color }
					strokeWidth={ thickness }
					strokeDasharray={ `${ s.dash } ${ C - s.dash }` }
					style={ { transform: `rotate(${ s.rot }deg)`, transformOrigin: 'center' } }
				/>
			) ) }
			<text x="50%" y="50%" textAnchor="middle" dominantBaseline="central" style={ { fontSize: 22, fontWeight: 700, fill: 'var(--uxs-text)' } }>
				{ fmt( total ) }
			</text>
		</svg>
	);
}

function BreakdownCard( {
	title,
	rows,
	variant = 'bars',
	labelFn,
}: {
	title: string;
	rows: DimRow[];
	variant?: 'bars' | 'donut';
	labelFn?: ( val: string ) => string;
} ): JSX.Element {
	const empty = rows.length === 0;
	const label = ( v: string ): string => ( v === UNKNOWN ? t( 'Unknown' ) : labelFn ? labelFn( v ) : v );

	let segments: Seg[] = [];
	if ( variant === 'donut' && ! empty ) {
		const top = rows.slice( 0, 6 );
		const rest = rows.slice( 6 ).reduce( ( s, r ) => s + r.visitors, 0 );
		segments = top.map( ( r, i ) => ( { label: r.val, value: r.visitors, color: CAT_COLORS[ i % CAT_COLORS.length ] ?? CAT_OTHER } ) );
		if ( rest > 0 ) {
			segments.push( { label: t( 'Other' ), value: rest, color: CAT_OTHER } );
		}
	}
	const totalVis = Math.max( 1, rows.reduce( ( s, r ) => s + r.visitors, 0 ) );
	const barMax = Math.max( 1, ...rows.map( ( r ) => r.visitors ) );

	return (
		<div className="uxs-card" style={ { margin: 0, textAlign: 'left' } }>
			<h2 style={ { fontSize: 15, marginTop: 0, marginBottom: 12, color: 'var(--uxs-text)' } }>{ title }</h2>

			{ empty && <p style={ { ...softText, margin: 0, fontSize: 13 } }>{ t( 'No data.' ) }</p> }

			{ ! empty && variant === 'donut' && (
				<div style={ { display: 'flex', alignItems: 'center', gap: 16, flexWrap: 'wrap' } }>
					<Donut segments={ segments } />
					<div style={ { flex: 1, minWidth: 130, display: 'flex', flexDirection: 'column', gap: 7 } }>
						{ segments.map( ( s ) => (
							<div key={ s.label } style={ { display: 'flex', alignItems: 'center', gap: 8, fontSize: 13 } }>
								<span style={ { width: 10, height: 10, borderRadius: 3, background: s.color, flexShrink: 0 } } />
								<span style={ { overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' } }>{ label( s.label ) }</span>
								<strong style={ { marginLeft: 'auto' } }>{ fmt( s.value ) }</strong>
								<span style={ { ...softText, width: 38, textAlign: 'right' } }>{ Math.round( ( s.value / totalVis ) * 100 ) }%</span>
							</div>
						) ) }
					</div>
				</div>
			) }

			{ ! empty && variant === 'bars' && (
				<div style={ { display: 'flex', flexDirection: 'column', gap: 9 } }>
					{ rows.map( ( r ) => (
						<div key={ r.val }>
							<div style={ { display: 'flex', justifyContent: 'space-between', fontSize: 13, marginBottom: 3 } }>
								<span style={ { overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap', maxWidth: 180 } }>{ label( r.val ) }</span>
								<strong>{ fmt( r.visitors ) }</strong>
							</div>
							<div style={ { height: 6, background: 'var(--uxs-surface-2)', borderRadius: 999 } }>
								<div style={ { width: `${ ( r.visitors / barMax ) * 100 }%`, height: '100%', background: 'var(--uxs-brand)', borderRadius: 999, transition: 'width .5s var(--uxs-ease)' } } />
							</div>
						</div>
					) ) }
				</div>
			) }
		</div>
	);
}

function GeoIpCard(): JSX.Element {
	const qc = useQueryClient();
	const q = useQuery< GeoStatus >( { queryKey: [ 'analytics', 'geoip' ], queryFn: () => api.get< GeoStatus >( 'analytics/geoip' ) } );
	const run = useMutation( {
		mutationFn: ( action: 'update' | 'disable' ) => api.post< GeoStatus >( 'analytics/geoip', { action } ),
		onSuccess: () => void qc.invalidateQueries( { queryKey: [ 'analytics' ] } ),
		onError: ( e: unknown ) => pushToast( 'error', e instanceof ApiError || e instanceof Error ? e.message : t( 'Something went wrong.' ) ),
	} );
	const s = q.data;
	const busy = run.isPending;
	const active = !! s?.ready && s.enabled;

	return (
		<div className="uxs-card" style={ { flexDirection: 'row', alignItems: 'center', gap: 14, margin: 0, flexWrap: 'wrap', textAlign: 'left' } }>
			<Globe size={ 22 } color="var(--uxs-brand)" aria-hidden="true" />
			<div style={ { flex: 1, minWidth: 220 } }>
				<strong>{ t( 'Country stats (GeoIP)' ) }</strong>
				<div style={ { ...softText, fontSize: 13, marginTop: 2 } }>
					{ active && s
						? t( 'Database active: {rows} IP ranges{updated}.', {
								rows: fmt( s.rows ),
								updated: s.updated ? ', ' + t( 'updated {date}', { date: new Date( s.updated ).toLocaleDateString( intlLocale() ) } ) : '',
						  } )
						: t( 'The public-domain country database is downloaded once. The raw IP address is never stored.' ) }
				</div>
			</div>
			{ s?.can_manage && (
				<button type="button" className="uxs-btn" onClick={ () => run.mutate( 'update' ) } disabled={ busy }>
					{ busy ? <LoaderCircle size={ 14 } aria-hidden="true" /> : <RefreshCw size={ 14 } aria-hidden="true" /> } { active ? t( 'Update' ) : t( 'Download database' ) }
				</button>
			) }
			{ s?.can_manage && s.enabled && (
				<button type="button" className="uxs-btn" onClick={ () => run.mutate( 'disable' ) } disabled={ busy }>
					{ t( 'Disable' ) }
				</button>
			) }
		</div>
	);
}

export default function AnalyticsOverview(): JSX.Element {
	const [ period, setPeriod ] = useState( '7d' );
	const list = periods();

	const q = useQuery< Summary >( {
		queryKey: [ 'analytics', 'summary', period ],
		queryFn: () => api.get< Summary >( 'analytics/summary', { period } ),
		refetchInterval: 60000,
	} );
	const data = q.data;
	const grid = ( cols: string ): CSSProperties => ( { display: 'grid', gridTemplateColumns: cols, gap: 16 } );

	return (
		<div style={ { display: 'flex', flexDirection: 'column', gap: 20 } }>
			<div style={ { display: 'flex', justifyContent: 'flex-end' } }>
				<div role="group" aria-label={ t( 'Period' ) } style={ { display: 'inline-flex', flexWrap: 'wrap', gap: 'var(--uxs-sp-1)' } }>
					{ list.map( ( p ) => (
						<button
							key={ p.key }
							type="button"
							className={ period === p.key ? 'uxs-btn uxs-btn--small uxs-btn--primary' : 'uxs-btn uxs-btn--small' }
							aria-pressed={ period === p.key }
							onClick={ () => setPeriod( p.key ) }
						>
							{ p.label }
						</button>
					) ) }
				</div>
			</div>

			{ q.isLoading && <Loading /> }
			{ q.error && <p role="alert">{ q.error.message }</p> }

			{ data && (
				<>
					<div style={ grid( 'repeat(auto-fit, minmax(220px, 1fr))' ) }>
						<StatCard icon={ <Users size={ 14 } aria-hidden="true" /> } label={ t( 'Visitors' ) } value={ data.totals.visitors } prev={ data.previous.visitors } subtitle={ prevSubtitle( data.totals.visitors, data.previous.visitors ) } />
						<StatCard icon={ <Eye size={ 14 } aria-hidden="true" /> } label={ t( 'Views' ) } value={ data.totals.views } prev={ data.previous.views } subtitle={ prevSubtitle( data.totals.views, data.previous.views ) } />
						<StatCard icon={ null } label={ t( 'Right now' ) } value={ data.realtime } live subtitle={ t( 'views in the last hour' ) } />
					</div>

					<div className="uxs-card" style={ { textAlign: 'left' } }>
						<h2 style={ { marginTop: 0, color: 'var(--uxs-text)' } }>{ t( 'Visitors and views' ) }</h2>
						<p style={ { ...softText, margin: '2px 0 16px', fontSize: 13 } }>{ t( 'Daily totals for the selected period' ) }</p>
						<Chart data={ data.daily } />
					</div>

					<div style={ grid( 'repeat(auto-fit, minmax(300px, 1fr))' ) }>
						<TopTable title={ t( 'Top pages' ) } rows={ data.top_pages } dimLabel={ t( 'Page' ) } getKey={ ( r ) => r.path ?? '' } />
						<TopTable title={ t( 'Traffic sources' ) } rows={ data.referers } dimLabel={ t( 'Source' ) } getKey={ ( r ) => r.referer_host ?? '' } />
					</div>

					<div style={ grid( 'repeat(auto-fit, minmax(280px, 1fr))' ) }>
						<BreakdownCard
							title={ t( 'Device' ) }
							variant="donut"
							rows={ data.breakdowns.device ?? [] }
							labelFn={ ( v ) => ( v === 'desktop' ? t( 'Desktop' ) : v === 'mobile' ? t( 'Mobile' ) : v === 'tablet' ? t( 'Tablet' ) : v ) }
						/>
						<BreakdownCard title={ t( 'Browser' ) } variant="donut" rows={ data.breakdowns.browser ?? [] } />
						<BreakdownCard title={ t( 'Operating system' ) } variant="donut" rows={ data.breakdowns.os ?? [] } />
					</div>

					<div style={ grid( 'repeat(auto-fit, minmax(280px, 1fr))' ) }>
						<BreakdownCard title={ t( 'Language' ) } rows={ data.breakdowns.lang ?? [] } />
						{ ( data.breakdowns.country?.length ?? 0 ) > 0 && (
							<BreakdownCard title={ t( 'Country' ) } rows={ data.breakdowns.country ?? [] } labelFn={ ( cc ) => `${ flag( cc ) } ${ cc }`.trim() } />
						) }
					</div>

					<GeoIpCard />
				</>
			) }
		</div>
	);
}
