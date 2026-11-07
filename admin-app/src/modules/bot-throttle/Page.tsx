/**
 * Bot Throttle screen: load tier and statistics, hit log, bans, a User-Agent tester and the module settings.
 */
import { useState } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import { Play, RefreshCw, Trash2, Undo2 } from 'lucide-react';
import { api, ApiError, queryClient, type ModuleInfo } from '../../app/api';
import { createT, formatDate } from '../../app/i18n';
import { SettingsPanel } from '../../app/SettingsForm';
import { DataTable, Loading, TabPanel, Tabs, confirm, pushToast, type Column } from '../../app/ui';

const { t } = createT( 'bot-throttle' );

type Stats = {
	total: number;
	blocked: number;
	delayed: number;
	bans: number;
	by_action: Array< { action: string; c: number; avg_delay: number } >;
	top_bots: Array< { bot_name: string; bot_category: string; c: number; avg_delay: number } >;
	hours: number;
};

type Overview = {
	tier: { tier: string; score: number };
	stats_24h: Stats;
	stats_1h: Stats;
	cache: { count: number; bytes: number };
	active_bans: number;
	client_ip: string;
	categories: Array< { id: string; label: string; action: string; bots: string[] } >;
	report_hash_enabled: boolean;
};

type LogRow = {
	id: number;
	created: string;
	ip_hash: string;
	user_agent: string;
	action: string;
	bot_category: string;
	bot_name: string;
	tier: string;
	delay_ms: number;
	url: string;
	load_score: number;
	response_status: number;
};

type Ban = { id: number; ip_hash: string; context: string; reason: string; banned_at: string; banned_until: string; active: boolean; reported: boolean };
type BanList = { items: Ban[]; total: number; page: number; pages: number };

type Plan = { action: string; delay_ms: number; status: number; block_on_miss?: boolean; retry_after?: number };
type TestResult = { detected: boolean; bot: { category: string; name: string; verified: boolean } | null; tier: { tier: string; score: number }; plan: Plan | null };

const TIER_TONE: Record< string, string > = { GREEN: 'is-success', YELLOW: 'is-warning', ORANGE: 'is-warning', RED: 'is-danger' };

function errorMessage( e: unknown ): string {
	return e instanceof ApiError || e instanceof Error ? e.message : t( 'Something went wrong.' );
}

function actionLabel( action: string ): string {
	const map: Record< string, string > = {
		pass: t( 'Passed' ),
		delay: t( 'Delayed' ),
		microcache: t( 'Served from cache' ),
		'microcache-hit': t( 'Served from cache' ),
		block: t( 'Blocked' ),
		ratelimit: t( 'Rate limit' ),
		ban: t( 'Banned' ),
		pass_with_min_delay: t( 'Pass with a minimal delay' ),
		throttle_light: t( 'Throttle lightly' ),
		throttle_aggressive: t( 'Throttle aggressively' ),
	};
	return map[ action ] ?? action;
}

function bytes( n: number ): string {
	return n > 1048576 ? `${ ( n / 1048576 ).toFixed( 1 ) } MB` : `${ Math.max( 0, Math.round( n / 1024 ) ) } kB`;
}

function OverviewTab(): JSX.Element {
	const q = useQuery( { queryKey: [ 'bt', 'overview' ], queryFn: () => api.get< Overview >( 'bot-throttle/overview' ), refetchInterval: 30000 } );
	const clearCache = useMutation( {
		mutationFn: () => api.post( 'bot-throttle/cache/clear', {} ),
		onSuccess: () => {
			void queryClient.invalidateQueries( { queryKey: [ 'bt' ] } );
			pushToast( 'success', t( 'Cache cleared.' ) );
		},
		onError: ( e ) => pushToast( 'error', errorMessage( e ) ),
	} );
	if ( q.isLoading ) {
		return <Loading />;
	}
	if ( q.error || ! q.data ) {
		return <p role="alert">{ errorMessage( q.error ) }</p>;
	}
	const o = q.data;
	const botCols: Column< Stats[ 'top_bots' ][ number ] >[] = [
		{ key: 'bot_name', header: t( 'Bot' ) },
		{ key: 'bot_category', header: t( 'Category' ) },
		{ key: 'c', header: t( 'Requests' ), sortable: true },
		{ key: 'avg_delay', header: t( 'Average delay (ms)' ) },
	];
	const catCols: Column< Overview[ 'categories' ][ number ] >[] = [
		{ key: 'label', header: t( 'Category' ) },
		{ key: 'action', header: t( 'Rule' ), render: ( c ) => actionLabel( c.action ) },
		{ key: 'bots', header: t( 'Bots' ), render: ( c ) => <span className="uxs-muted">{ c.bots.join( ', ' ) }</span> },
	];

	return (
		<>
			<div className="uxs-cards">
				<div className="uxs-card">
					<span className="uxs-card__label">{ t( 'Server load' ) }</span>
					<span className="uxs-card__num"><span className={ `uxs-badge ${ TIER_TONE[ o.tier.tier ] ?? '' }` }>{ o.tier.tier }</span> { o.tier.score }</span>
				</div>
				<div className="uxs-card">
					<span className="uxs-card__label">{ t( 'Bot requests (24 h)' ) }</span>
					<span className="uxs-card__num">{ o.stats_24h.total }</span>
				</div>
				<div className="uxs-card">
					<span className="uxs-card__label">{ t( 'Refused (24 h)' ) }</span>
					<span className="uxs-card__num">{ o.stats_24h.blocked }</span>
				</div>
				<div className="uxs-card">
					<span className="uxs-card__label">{ t( 'Delayed (24 h)' ) }</span>
					<span className="uxs-card__num">{ o.stats_24h.delayed }</span>
				</div>
				<div className="uxs-card">
					<span className="uxs-card__label">{ t( 'Active bans' ) }</span>
					<span className="uxs-card__num">{ o.active_bans }</span>
				</div>
				<div className="uxs-card">
					<span className="uxs-card__label">{ t( 'Microcache' ) }</span>
					<span className="uxs-card__num">{ o.cache.count } ({ bytes( o.cache.bytes ) })</span>
				</div>
			</div>
			<div className="uxs-actions" style={ { marginBottom: 'var(--uxs-sp-4)' } }>
				<button type="button" className="uxs-btn" onClick={ () => void q.refetch() } disabled={ q.isFetching }>
					<RefreshCw size={ 14 } aria-hidden="true" /> { t( 'Refresh' ) }
				</button>
				<button type="button" className="uxs-btn" onClick={ () => clearCache.mutate() } disabled={ o.cache.count === 0 || clearCache.isPending }>
					<Trash2 size={ 14 } aria-hidden="true" /> { t( 'Clear microcache' ) }
				</button>
				<span className="uxs-muted">{ t( 'Your address as seen by the module: {ip}', { ip: o.client_ip } ) }</span>
			</div>
			{ ! o.report_hash_enabled ? <p className="uxs-muted">{ t( 'Bans stay local: no central report secret is set, so nothing is reported to the Central App.' ) }</p> : null }
			<h2 className="uxs-form__section">{ t( 'Top bots (24 h)' ) }</h2>
			<DataTable columns={ botCols } rows={ o.stats_24h.top_bots } rowKey={ ( b ) => `${ b.bot_category }/${ b.bot_name }` } empty={ t( 'No bots seen yet.' ) } />
			<h2 className="uxs-form__section">{ t( 'Rules by category' ) }</h2>
			<DataTable columns={ catCols } rows={ o.categories } rowKey={ ( c ) => c.id } />
		</>
	);
}

function LogTab(): JSX.Element {
	const [ action, setAction ] = useState( '' );
	const q = useQuery( { queryKey: [ 'bt', 'log', action ], queryFn: () => api.get< { items: LogRow[] } >( 'bot-throttle/log', { action, limit: 200 } ) } );
	const clear = useMutation( {
		mutationFn: () => api.post( 'bot-throttle/log/clear', {} ),
		onSuccess: () => void queryClient.invalidateQueries( { queryKey: [ 'bt' ] } ),
		onError: ( e ) => pushToast( 'error', errorMessage( e ) ),
	} );
	const cols: Column< LogRow >[] = [
		{ key: 'created', header: t( 'Time' ), sortable: true, render: ( r ) => formatDate( r.created ) },
		{ key: 'bot_name', header: t( 'Bot' ), render: ( r ) => r.bot_name || '-' },
		{ key: 'action', header: t( 'Action' ), render: ( r ) => actionLabel( r.action ) },
		{ key: 'tier', header: t( 'Load' ), render: ( r ) => <span className={ `uxs-badge ${ TIER_TONE[ r.tier ] ?? '' }` }>{ r.tier }</span> },
		{ key: 'delay_ms', header: t( 'Delay (ms)' ), sortable: true },
		{ key: 'response_status', header: t( 'Status' ) },
		{ key: 'url', header: t( 'URL' ), render: ( r ) => <code>{ r.url }</code> },
		{ key: 'ip_hash', header: t( 'IP hash' ), render: ( r ) => <code>{ r.ip_hash }</code> },
	];
	return (
		<>
			<div className="uxs-actions uxs-list-toolbar">
				<select className="uxs-input" value={ action } aria-label={ t( 'Action' ) } onChange={ ( e ) => setAction( e.target.value ) }>
					<option value="">{ t( 'All actions' ) }</option>
					{ [ 'pass', 'delay', 'microcache', 'block', 'ratelimit', 'ban' ].map( ( a ) => <option key={ a } value={ a }>{ actionLabel( a ) }</option> ) }
				</select>
				<button
					type="button"
					className="uxs-btn"
					onClick={ () => {
						void confirm( { message: t( 'Delete the whole log?' ), danger: true, confirmLabel: t( 'Delete' ) } ).then( ( ok ) => ok && clear.mutate() );
					} }
				>
					<Trash2 size={ 14 } aria-hidden="true" /> { t( 'Clear log' ) }
				</button>
			</div>
			{ q.isLoading ? <Loading /> : null }
			{ q.error ? <p role="alert">{ errorMessage( q.error ) }</p> : null }
			{ q.data ? <DataTable columns={ cols } rows={ q.data.items } rowKey={ ( r ) => r.id } empty={ t( 'The log is empty.' ) } pageSize={ 25 } /> : null }
		</>
	);
}

function BansTab(): JSX.Element {
	const [ page, setPage ] = useState( 1 );
	const q = useQuery( { queryKey: [ 'bt', 'bans', page ], queryFn: () => api.get< BanList >( 'bot-throttle/bans', { page } ) } );
	const lift = useMutation( {
		mutationFn: ( b: Ban ) => api.post( `bot-throttle/bans/${ b.id }/delete`, {} ),
		onSuccess: () => void queryClient.invalidateQueries( { queryKey: [ 'bt' ] } ),
		onError: ( e ) => pushToast( 'error', errorMessage( e ) ),
	} );
	const cols: Column< Ban >[] = [
		{ key: 'banned_at', header: t( 'Banned' ), render: ( b ) => formatDate( b.banned_at ) },
		{ key: 'banned_until', header: t( 'Until' ), render: ( b ) => formatDate( b.banned_until ) },
		{ key: 'ip_hash', header: t( 'IP hash' ), render: ( b ) => <code>{ b.ip_hash }</code> },
		{ key: 'context', header: t( 'Where' ) },
		{ key: 'reason', header: t( 'Reason' ) },
		{ key: 'reported', header: t( 'Reported to Central App' ), render: ( b ) => ( b.reported ? t( 'Yes' ) : t( 'No' ) ) },
		{ key: 'active', header: t( 'Status' ), render: ( b ) => <span className={ `uxs-badge ${ b.active ? 'is-danger' : '' }` }>{ b.active ? t( 'Active' ) : t( 'Ended' ) }</span> },
		{
			key: 'actions',
			header: '',
			render: ( b ) =>
				b.active ? (
					<button type="button" className="uxs-btn uxs-btn--small" onClick={ () => lift.mutate( b ) } disabled={ lift.isPending }>
						<Undo2 size={ 14 } aria-hidden="true" /> { t( 'Lift ban' ) }
					</button>
				) : null,
		},
	];
	return (
		<>
			<p className="uxs-muted">{ t( 'Only a keyed hash of the address is stored, never the address itself. A ban ends by itself; you can lift it earlier.' ) }</p>
			{ q.isLoading ? <Loading /> : null }
			{ q.error ? <p role="alert">{ errorMessage( q.error ) }</p> : null }
			{ q.data ? <DataTable columns={ cols } rows={ q.data.items } rowKey={ ( b ) => b.id } empty={ t( 'No bans.' ) } page={ q.data.page } pages={ q.data.pages } onPage={ setPage } /> : null }
		</>
	);
}

function TestTab(): JSX.Element {
	const [ ua, setUa ] = useState( 'Mozilla/5.0 (compatible; GPTBot/1.1; +https://openai.com/gptbot)' );
	const [ ip, setIp ] = useState( '203.0.113.1' );
	const test = useMutation( {
		mutationFn: () => api.post< TestResult >( 'bot-throttle/test', { user_agent: ua, ip } ),
		onError: ( e ) => pushToast( 'error', errorMessage( e ) ),
	} );
	const r = test.data;
	return (
		<>
			<p className="uxs-muted">{ t( 'Shows how a User-Agent would be treated at the current server load. Nothing is sent or stored.' ) }</p>
			<div className="uxs-form">
				<div className="uxs-form__row">
					<label htmlFor="uxs-bt-ua">{ t( 'User-Agent' ) }</label>
					<input id="uxs-bt-ua" type="text" value={ ua } onChange={ ( e ) => setUa( e.target.value ) } />
				</div>
				<div className="uxs-form__row">
					<label htmlFor="uxs-bt-ip">{ t( 'IP address' ) }</label>
					<input id="uxs-bt-ip" type="text" value={ ip } onChange={ ( e ) => setIp( e.target.value ) } />
				</div>
				<div className="uxs-form__actions">
					<button type="button" className="uxs-btn uxs-btn--primary" disabled={ test.isPending || ! ua.trim() } onClick={ () => test.mutate() }>
						<Play size={ 14 } aria-hidden="true" /> { t( 'Test' ) }
					</button>
				</div>
			</div>
			{ r ? (
				<ul className="uxs-status-list" style={ { marginTop: 'var(--uxs-sp-4)' } }>
					<li className="uxs-status-list__row">
						<strong>{ t( 'Detected bot' ) }</strong> { r.bot ? <span><code>{ r.bot.name }</code> ({ r.bot.category })</span> : <span className="uxs-muted">{ t( 'Not a known bot: passes untouched.' ) }</span> }
					</li>
					<li className="uxs-status-list__row">
						<strong>{ t( 'Server load' ) }</strong> <span className={ `uxs-badge ${ TIER_TONE[ r.tier.tier ] ?? '' }` }>{ r.tier.tier }</span> { r.tier.score }
					</li>
					{ r.plan ? (
						<li className="uxs-status-list__row">
							<strong>{ t( 'What happens' ) }</strong> { actionLabel( r.plan.action ) }
							{ r.plan.delay_ms > 0 ? ` (${ r.plan.delay_ms } ms)` : '' }
							{ r.plan.status !== 200 ? ` - HTTP ${ r.plan.status }${ r.plan.block_on_miss ? ` ${ t( 'when there is no cached copy' ) }` : '' }` : '' }
						</li>
					) : null }
				</ul>
			) : null }
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
					{ id: 'log', label: t( 'Log' ) },
					{ id: 'bans', label: t( 'Bans' ) },
					{ id: 'test', label: t( 'Test' ) },
					{ id: 'settings', label: t( 'Settings' ) },
				] }
			/>
			<TabPanel id={ tab }>
				{ tab === 'overview' ? <OverviewTab /> : null }
				{ tab === 'log' ? <LogTab /> : null }
				{ tab === 'bans' ? <BansTab /> : null }
				{ tab === 'test' ? <TestTab /> : null }
				{ tab === 'settings' ? <SettingsPanel moduleId={ module.id } /> : null }
			</TabPanel>
		</>
	);
}
