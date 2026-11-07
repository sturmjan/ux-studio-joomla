/**
 * Visitor Statistics screen: Overview (period switcher, KPI cards, chart, rankings, breakdowns, GeoIP) + Settings.
 */
import { useState } from 'react';
import type { ModuleInfo } from '../../app/api';
import { createT } from '../../app/i18n';
import { SettingsPanel } from '../../app/SettingsForm';
import { TabPanel, Tabs } from '../../app/ui';
import AnalyticsOverview from './AnalyticsOverview';

const { t } = createT( 'analytics' );

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
					{ id: 'settings', label: t( 'Settings' ) },
				] }
			/>
			{ tab === 'overview' ? (
				<TabPanel id="overview">
					<AnalyticsOverview />
				</TabPanel>
			) : (
				<TabPanel id="settings">
					<SettingsPanel moduleId={ module.id } />
				</TabPanel>
			) }
		</>
	);
}
