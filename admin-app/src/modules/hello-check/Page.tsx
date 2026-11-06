/**
 * Template of a module screen with its own Page: tabs (Overview + Settings), an API call and a table.
 * Copy this folder to start a new module screen; delete Page.tsx entirely if the generic settings form is enough.
 */
import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { RefreshCw } from 'lucide-react';
import { api, type ModuleInfo } from '../../app/api';
import { formatDate, t } from '../../app/i18n';
import { SettingsPanel } from '../../app/SettingsForm';
import { DataTable, Loading, TabPanel, Tabs, type Column } from '../../app/ui';

interface Ping {
	greeting: string;
	secret_set: boolean;
	time: string;
	user: string;
	joomla: string;
	php: string;
	heartbeats: number;
	latest: Array< { id: string; created: string; note: string } >;
}

type Row = Ping[ 'latest' ][ number ];

export default function Page( { module }: { module: ModuleInfo } ): JSX.Element {
	const [ tab, setTab ] = useState( 'overview' );
	const ping = useQuery( { queryKey: [ 'hello-check', 'ping' ], queryFn: () => api.get< Ping >( 'hello-check/ping' ) } );

	const columns: Column< Row >[] = [
		{ key: 'id', header: 'ID', sortable: true },
		{ key: 'created', header: t( 'Time' ), sortable: true, render: ( r ) => formatDate( r.created ) },
		{ key: 'note', header: t( 'Source' ) },
	];

	return (
		<>
			<Tabs
				label={ module.name }
				active={ tab }
				onChange={ setTab }
				items={ [
					{ id: 'overview', label: t( 'Overview' ) },
					{ id: 'settings', label: t( 'Settings' ) },
				] }
			/>
			{ tab === 'overview' ? (
				<TabPanel id="overview">
					{ ping.isLoading ? <Loading /> : null }
					{ ping.error ? <p role="alert">{ ping.error.message }</p> : null }
					{ ping.data ? (
						<>
							<div className="uxs-cards">
								<div className="uxs-card">
									<span className="uxs-card__label">{ t( 'Greeting' ) }</span>
									<span className="uxs-card__num">{ ping.data.greeting }</span>
								</div>
								<div className="uxs-card">
									<span className="uxs-card__label">{ t( 'Heartbeats' ) }</span>
									<span className="uxs-card__num">{ ping.data.heartbeats }</span>
								</div>
								<div className="uxs-card">
									<span className="uxs-card__label">{ t( 'Secret token' ) }</span>
									<span className="uxs-card__num">{ ping.data.secret_set ? t( 'Set' ) : t( 'Not set' ) }</span>
								</div>
							</div>
							<div className="uxs-actions" style={ { marginBottom: 'var(--uxs-sp-4)' } }>
								<button type="button" className="uxs-btn" onClick={ () => void ping.refetch() } disabled={ ping.isFetching }>
									<RefreshCw size={ 14 } aria-hidden="true" /> { t( 'Refresh' ) }
								</button>
								<span className="uxs-muted">{ t( 'Joomla {joomla}, PHP {php}', { joomla: ping.data.joomla, php: ping.data.php } ) }</span>
							</div>
							<DataTable columns={ columns } rows={ ping.data.latest } rowKey={ ( r ) => r.id } empty={ t( 'No heartbeat yet. Run the scheduler task "Hello check heartbeat".' ) } pageSize={ 10 } />
						</>
					) : null }
				</TabPanel>
			) : (
				<TabPanel id="settings">
					<SettingsPanel moduleId={ module.id } />
				</TabPanel>
			) }
		</>
	);
}
