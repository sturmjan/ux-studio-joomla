/**
 * App shell: sidebar (brand, navigation, theme switch), narrow-screen drawer, hash router.
 * Routes: #/modules (default) and #/module?id=<module id>.
 */
import { useCallback, useEffect, useRef, useState } from 'react';
import { LayoutGrid, Menu, Moon, PanelLeftClose, PanelLeftOpen, Settings, Sun, X } from 'lucide-react';
import { useQuery } from '@tanstack/react-query';
import { UNSAFE_PortalProvider as PortalProvider } from 'react-aria';
import { api, options, type CoreMeta } from './api';
import { CoreSettingsPage } from './CoreSettingsPage';
import { t } from './i18n';
import { ModuleGrid } from './ModuleGrid';
import { ModuleIcon } from './moduleIcons';
import { ModulePage } from './ModulePage';
import { hasCustomPage } from './registry';
import { getSidebarHidden, getTheme, setSidebarHidden, setTheme, type Theme } from './prefs';
import { currentRoute, hashParam, navigate } from './route';
import { useModules } from './useModules';
import { ConfirmHost } from './ui/Confirm';
import { Toasts } from './ui/Toasts';
import { Wizard } from './wizard/Wizard';
import { WizardHint } from './wizard/WizardHint';

/** Below this width of the shell the sidebar becomes an off-canvas drawer. */
const NARROW_PX = 1100;

function useRoute(): string {
	const [ route, setRoute ] = useState( currentRoute() );
	useEffect( () => {
		const onChange = (): void => setRoute( currentRoute() + window.location.hash );
		window.addEventListener( 'hashchange', onChange );
		return () => window.removeEventListener( 'hashchange', onChange );
	}, [] );
	return route;
}

export function App(): JSX.Element {
	useRoute();
	const route = currentRoute();
	const { data: modules } = useModules();
	const { data: meta } = useQuery( { queryKey: [ 'meta' ], queryFn: () => api.get< CoreMeta >( 'core/meta' ) } );
	const shellRef = useRef< HTMLDivElement >( null );
	const [ narrow, setNarrow ] = useState( false );
	const [ folded, setFolded ] = useState( getSidebarHidden() );
	const [ drawer, setDrawer ] = useState( false );
	const [ theme, setThemeState ] = useState< Theme >( getTheme() );
	const [ portalRoot, setPortalRoot ] = useState< HTMLElement | null >( null );

	useEffect( () => {
		setPortalRoot( document.getElementById( 'uxs-root' ) );
	}, [] );

	useEffect( () => {
		const shell = shellRef.current;
		if ( ! shell || typeof ResizeObserver === 'undefined' ) {
			return undefined;
		}
		const measure = (): void => setNarrow( shell.getBoundingClientRect().width < NARROW_PX );
		measure();
		const observer = new ResizeObserver( measure );
		observer.observe( shell );
		return () => observer.disconnect();
	}, [] );

	const closeDrawer = useCallback( () => setDrawer( false ), [] );
	useEffect( () => {
		if ( ! narrow ) {
			setDrawer( false );
		}
	}, [ narrow ] );
	useEffect( () => {
		if ( ! drawer ) {
			return undefined;
		}
		const opener = document.activeElement instanceof HTMLElement ? document.activeElement : null;
		const items = (): HTMLElement[] => Array.from( shellRef.current?.querySelectorAll< HTMLElement >( '.uxs-sidebar button:not([disabled])' ) ?? [] );
		items()[ 0 ]?.focus();
		const onKey = ( e: KeyboardEvent ): void => {
			if ( e.key === 'Escape' ) {
				closeDrawer();
			} else if ( e.key === 'Tab' ) {
				// focus trap: the drawer is modal while it is open
				const list = items();
				if ( list.length === 0 ) {
					return;
				}
				const first = list[ 0 ];
				const last = list[ list.length - 1 ];
				const active = document.activeElement;
				if ( ! shellRef.current?.querySelector( '.uxs-sidebar' )?.contains( active ) ) {
					e.preventDefault();
					first.focus();
				} else if ( e.shiftKey && active === first ) {
					e.preventDefault();
					last.focus();
				} else if ( ! e.shiftKey && active === last ) {
					e.preventDefault();
					first.focus();
				}
			}
		};
		document.addEventListener( 'keydown', onKey );
		return () => {
			document.removeEventListener( 'keydown', onKey );
			opener?.focus();
		};
	}, [ drawer, closeDrawer ] );

	const sidebarShown = narrow ? drawer : ! folded;
	const activeId = route === 'module' ? hashParam( 'id' ) : null;
	const go = ( to: string, params?: Record< string, string > ): void => {
		navigate( to, params );
		closeDrawer();
	};
	const toggleTheme = (): void => {
		const next: Theme = theme === 'dark' ? 'light' : 'dark';
		setTheme( next );
		setThemeState( next );
	};
	const opts = options();

	return (
		<PortalProvider getContainer={ () => portalRoot }>
			<div ref={ shellRef } className={ `uxs-shell${ narrow ? ' is-narrow' : '' }${ ! narrow && folded ? ' is-folded' : '' }${ drawer ? ' is-drawer-open' : '' }` }>
				{ narrow && drawer ? <div className="uxs-shell__backdrop" aria-hidden="true" onClick={ closeDrawer } /> : null }
				<aside className="uxs-sidebar" aria-label="UX1 Studio">
					<div className="uxs-sidebar__top">
						<span className="uxs-sidebar__brand">UX1 Studio</span>
						<button
							type="button"
							className="uxs-sidebar__fold"
							aria-label={ narrow ? t( 'Close menu' ) : t( 'Hide the sidebar' ) }
							onClick={ () => {
								if ( narrow ) {
									closeDrawer();
								} else {
									setSidebarHidden( true );
									setFolded( true );
								}
							} }
						>
							{ narrow ? <X size={ 18 } aria-hidden="true" /> : <PanelLeftClose size={ 18 } aria-hidden="true" /> }
						</button>
					</div>
					<nav className="uxs-tree" aria-label={ t( 'Modules' ) }>
						<button type="button" className={ `uxs-sidebar__item${ route !== 'module' && route !== 'settings' ? ' is-active' : '' }` } onClick={ () => go( 'modules' ) }>
							<LayoutGrid size={ 20 } aria-hidden="true" /> { t( 'Modules' ) }
						</button>
						{ ( modules ?? [] )
							.filter( ( m ) => m.enabled && ( m.settings || hasCustomPage( m.id ) ) )
							.map( ( m ) => (
								<button key={ m.id } type="button" className={ `uxs-sidebar__item${ activeId === m.id ? ' is-active' : '' }` } onClick={ () => go( 'module', { id: m.id } ) }>
									<ModuleIcon name={ m.icon } moduleId={ m.id } size={ 20 } /> { m.name }
								</button>
							) ) }
						{ meta?.canAdmin ? (
							<button type="button" className={ `uxs-sidebar__item${ route === 'settings' ? ' is-active' : '' }` } onClick={ () => go( 'settings' ) }>
								<Settings size={ 20 } aria-hidden="true" /> { t( 'Settings' ) }
							</button>
						) : null }
					</nav>
					<div className="uxs-sidebar__foot">
						<button type="button" className="uxs-sidebar__item" onClick={ toggleTheme }>
							{ theme === 'dark' ? <Sun size={ 20 } aria-hidden="true" /> : <Moon size={ 20 } aria-hidden="true" /> } { theme === 'dark' ? t( 'Light mode' ) : t( 'Dark mode' ) }
						</button>
						<span>{ t( 'UX1 Studio {version}', { version: opts.version } ) }</span>
					</div>
				</aside>
				<main className="uxs-content">
					{ narrow || folded ? (
						<div className="uxs-topbar">
							<button
								type="button"
								className="uxs-topbar__toggle"
								aria-expanded={ sidebarShown }
								onClick={ () => {
									if ( narrow ) {
										setDrawer( true );
									} else {
										setSidebarHidden( false );
										setFolded( false );
									}
								} }
							>
								{ narrow ? <Menu size={ 18 } aria-hidden="true" /> : <PanelLeftOpen size={ 18 } aria-hidden="true" /> } { t( 'Menu' ) }
							</button>
						</div>
					) : null }
					{ route === 'module' ? (
						<ModulePage key={ activeId ?? '' } />
					) : route === 'settings' ? (
						<CoreSettingsPage />
					) : route === 'wizard' ? (
						<Wizard />
					) : (
						<>
							<WizardHint />
							<ModuleGrid />
						</>
					) }
				</main>
			</div>
			<Toasts />
			<ConfirmHost />
		</PortalProvider>
	);
}
