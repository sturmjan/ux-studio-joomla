/**
 * Screen of one module: gate for disabled modules, then either the module's own Page.tsx or the generic settings form.
 */
import { Suspense } from 'react';
import { ArrowLeft } from 'lucide-react';
import { t } from './i18n';
import { hashParam, navigate } from './route';
import { pageFor } from './registry';
import { SettingsPanel } from './SettingsForm';
import { useModules, useToggleModule } from './useModules';
import { Loading } from './ui/Loading';
import { PageHead } from './ui/PageHead';
import { ToggleSwitch } from './ui/ToggleSwitch';

export function ModulePage(): JSX.Element {
	const id = hashParam( 'id' ) ?? '';
	const { data: modules, isLoading } = useModules();
	const toggle = useToggleModule();

	if ( isLoading || ! modules ) {
		return <Loading />;
	}
	const module = modules.find( ( m ) => m.id === id );
	if ( ! module ) {
		return (
			<div className="uxs-notice is-error" role="alert">
				{ t( 'Unknown module.' ) }
			</div>
		);
	}

	const Page = pageFor( id );
	const back = (
		<button type="button" className="uxs-link-btn" onClick={ () => navigate( 'modules' ) }>
			<ArrowLeft size={ 14 } aria-hidden="true" /> { t( 'Back to modules' ) }
		</button>
	);

	return (
		<>
			<div style={ { marginBottom: 'var(--uxs-sp-3)' } }>{ back }</div>
			<PageHead title={ module.name }>
				<ToggleSwitch checked={ module.enabled } label={ module.enabled ? t( 'Disable module' ) : t( 'Turn on module' ) } onChange={ ( enabled ) => toggle.mutate( { m: module, enabled } ) } />
			</PageHead>
			{ module.description ? <p className="uxs-muted">{ module.description }</p> : null }
			{ module.error ? (
				<div className="uxs-notice is-error" role="alert">
					<strong>{ t( 'Boot error' ) }</strong> { t( 'The module failed to start and is skipped on this site. The other modules keep working.' ) }
					<br />
					<code>{ module.error }</code>
				</div>
			) : null }
			{ ! module.enabled ? (
				<div className="uxs-notice">
					<p>
						<strong>{ t( 'This module is turned off.' ) }</strong> { t( 'Turn it on to use its features and settings.' ) }
					</p>
					<button type="button" className="uxs-btn uxs-btn--primary" disabled={ toggle.isPending } onClick={ () => toggle.mutate( { m: module, enabled: true } ) }>
						{ t( 'Turn on' ) }
					</button>
				</div>
			) : Page ? (
				<Suspense fallback={ <Loading /> }>
					<Page module={ module } />
				</Suspense>
			) : (
				<SettingsPanel moduleId={ module.id } />
			) }
		</>
	);
}
