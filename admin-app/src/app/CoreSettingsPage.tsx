/**
 * Settings of the UX Studio core (not of a module): client IP detection and other shared options.
 * Stored under the id "core" (core/settings/core), administrators only.
 */
import { PageHead } from './ui/PageHead';
import { SettingsPanel } from './SettingsForm';
import { t } from './i18n';

export function CoreSettingsPage(): JSX.Element {
	return (
		<>
			<PageHead title={ t( 'Settings' ) } />
			<p className="uxs-muted">{ t( 'Shared options of UX Studio that every module uses.' ) }</p>
			<SettingsPanel moduleId="core" />
		</>
	);
}
