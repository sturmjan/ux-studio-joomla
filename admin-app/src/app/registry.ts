/**
 * Auto-discovery of module screens: every admin-app/src/modules/<id>/Page.tsx (default export) is lazy loaded.
 * A module without Page.tsx gets the generic settings renderer. No central list - adding a folder is enough.
 */
import { lazy, type ComponentType, type LazyExoticComponent } from 'react';
import type { ModuleInfo } from './api';

export interface ModulePageProps {
	module: ModuleInfo;
}

type Loader = () => Promise< { default: ComponentType< ModulePageProps > } >;

const loaders = import.meta.glob< { default: ComponentType< ModulePageProps > } >( '../modules/*/Page.tsx' );

const cache = new Map< string, LazyExoticComponent< ComponentType< ModulePageProps > > >();

function loaderFor( id: string ): Loader | undefined {
	return loaders[ `../modules/${ id }/Page.tsx` ];
}

export function hasCustomPage( id: string ): boolean {
	return loaderFor( id ) !== undefined;
}

export function pageFor( id: string ): LazyExoticComponent< ComponentType< ModulePageProps > > | null {
	const loader = loaderFor( id );
	if ( ! loader ) {
		return null;
	}
	let page = cache.get( id );
	if ( ! page ) {
		page = lazy( loader );
		cache.set( id, page );
	}
	return page;
}
