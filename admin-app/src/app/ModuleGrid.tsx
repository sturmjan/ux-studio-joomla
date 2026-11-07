/**
 * Landing page: list of modules with search, group filter, status filter and an on/off switch per module.
 * Rows of modules that have a settings screen (meta.json settings:true or a custom Page) open it on click.
 */
import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { ChevronRight } from 'lucide-react';
import { api, type DashboardWidget, type ModuleInfo } from './api';
import { t } from './i18n';
import { ModuleIcon } from './moduleIcons';
import { hasCustomPage } from './registry';
import { navigate } from './route';
import { useModules, useToggleModule } from './useModules';
import { Loading } from './ui/Loading';
import { PageHead } from './ui/PageHead';
import { ToggleSwitch } from './ui/ToggleSwitch';

/** meta.json `group` values in display order. */
export function groupLabels(): Record< string, string > {
	return {
		security: t( 'Security' ),
		communication: t( 'Communication' ),
		marketing: t( 'Marketing' ),
		content: t( 'Content' ),
		performance: t( 'Performance' ),
		admin: t( 'Administration' ),
		developer: t( 'Developer' ),
	};
}

type StatusFilter = 'all' | 'on' | 'off';

function Widgets(): JSX.Element | null {
	const { data } = useQuery( { queryKey: [ 'dashboard' ], queryFn: () => api.get< DashboardWidget[] >( 'core/dashboard' ) } );
	if ( ! data || data.length === 0 ) {
		return null;
	}
	return (
		<div className="uxs-cards">
			{ data.map( ( w ) => (
				<div key={ `${ w.module }-${ w.title }` } className="uxs-card">
					<span className="uxs-card__label">{ w.title }</span>
					<span className="uxs-card__num">{ w.value ?? '' }</span>
					{ w.text ? <span className="uxs-card__label">{ w.text }</span> : null }
				</div>
			) ) }
		</div>
	);
}

export function ModuleGrid(): JSX.Element {
	const [ group, setGroup ] = useState( 'all' );
	const [ status, setStatus ] = useState< StatusFilter >( 'all' );
	const [ search, setSearch ] = useState( '' );
	const { data: modules, isLoading, error } = useModules();
	const toggle = useToggleModule();
	const labels = groupLabels();

	if ( isLoading ) {
		return <Loading />;
	}
	if ( error || ! modules ) {
		return <p role="alert">{ error instanceof Error ? error.message : t( 'Failed to load modules.' ) }</p>;
	}

	const needle = search.trim().toLocaleLowerCase();
	const matchesStatus = ( m: ModuleInfo ): boolean => status === 'all' || ( status === 'on' ) === m.enabled;
	const visible = modules.filter(
		( m ) =>
			( group === 'all' || m.group === group ) &&
			matchesStatus( m ) &&
			( needle === '' || `${ m.name } ${ m.description } ${ m.id }`.toLocaleLowerCase().includes( needle ) )
	);
	const groupIds = [ ...Object.keys( labels ), ...modules.map( ( m ) => m.group ).filter( ( g ) => ! ( g in labels ) ) ].filter(
		( g, i, all ) => all.indexOf( g ) === i && modules.some( ( m ) => m.group === g )
	);
	const enabledCount = modules.filter( ( m ) => m.enabled ).length;

	return (
		<>
			<PageHead title={ t( 'Modules' ) } />
			<Widgets />
			<div className="uxs-modulefilter">
				<input
					type="search"
					className="uxs-modulefilter__search"
					placeholder={ t( 'Search modules…' ) }
					aria-label={ t( 'Search modules' ) }
					value={ search }
					onChange={ ( e ) => setSearch( e.target.value ) }
				/>
				<div className="uxs-subtabs" role="tablist" aria-label={ t( 'Module group' ) }>
					<button type="button" role="tab" aria-selected={ group === 'all' } className={ group === 'all' ? 'is-active' : '' } onClick={ () => setGroup( 'all' ) }>
						{ t( 'All' ) } ({ modules.length })
					</button>
					{ groupIds.map( ( g ) => (
						<button key={ g } type="button" role="tab" aria-selected={ g === group } className={ g === group ? 'is-active' : '' } onClick={ () => setGroup( g ) }>
							{ labels[ g ] ?? t( 'Other' ) } ({ modules.filter( ( m ) => m.group === g ).length })
						</button>
					) ) }
				</div>
				<select className="uxs-modulefilter__status" aria-label={ t( 'Status' ) } value={ status } onChange={ ( e ) => setStatus( e.target.value as StatusFilter ) }>
					<option value="all">{ t( 'All statuses' ) }</option>
					<option value="on">{ t( 'Enabled ({n})', { n: enabledCount } ) }</option>
					<option value="off">{ t( 'Disabled ({n})', { n: modules.length - enabledCount } ) }</option>
				</select>
			</div>
			<p className="uxs-modulefilter__empty" style={ { marginTop: 0 } }>
				{ t( '{total} modules, {on} enabled.', { total: modules.length, on: enabledCount } ) }
			</p>
			{ visible.length === 0 ? <p className="uxs-modulefilter__empty">{ t( 'No module matches the filter.' ) }</p> : null }
			<div className="uxs-grid is-list">
				{ visible.map( ( m ) => {
					const clickable = m.settings || hasCustomPage( m.id );
					const open = (): void => navigate( 'module', { id: m.id } );
					return (
						<div
							key={ m.id }
							className={ `uxs-tile${ clickable ? '' : ' is-static' }` }
							{ ...( clickable
								? {
										role: 'link' as const,
										tabIndex: 0,
										onClick: open,
										onKeyDown: ( e: React.KeyboardEvent ) => {
											if ( e.key === 'Enter' ) {
												open();
											}
										},
								  }
								: {} ) }
						>
							<div className="uxs-tile__head">
								<span className="uxs-tile__icon">
									<ModuleIcon name={ m.icon } moduleId={ m.id } />
								</span>
								<span className="uxs-tile__name" title={ m.name } style={ { flex: '0 1 260px', whiteSpace: 'normal', overflow: 'visible', textOverflow: 'clip', lineHeight: 1.3 } }>
									{ m.name }
								</span>
								<span className="uxs-tile__desc" title={ m.description }>
									{ m.description }
								</span>
								{ m.error ? (
									<span className="uxs-badge is-danger" title={ m.error }>
										{ t( 'Boot error' ) }
									</span>
								) : null }
								<ToggleSwitch checked={ m.enabled } label={ m.enabled ? t( 'Disable module' ) : t( 'Turn on module' ) } onChange={ ( enabled ) => toggle.mutate( { m, enabled } ) } />
								{ clickable ? <ChevronRight size={ 16 } className="uxs-tile__chevron" aria-hidden="true" /> : <span className="uxs-tile__chevron" aria-hidden="true" style={ { width: 16 } } /> }
							</div>
						</div>
					);
				} ) }
			</div>
		</>
	);
}
