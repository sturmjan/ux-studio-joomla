/** Pure helpers: what the wizard is going to turn on, in which order. */
import type { ModuleInfo } from '../api';
import type { OnboardingData, Profile, ProfileField } from './types';

/** Every module the wizard knows about (regular ones + the Central App connector). */
export function allModules( data: OnboardingData ): ModuleInfo[] {
	return data.ca ? [ ...data.modules, data.ca ] : data.modules;
}

export function nameOf( data: OnboardingData, id: string ): string {
	return allModules( data ).find( ( m ) => m.id === id )?.name ?? id;
}

/**
 * Ids to turn on, dependencies first. Selected modules that are already on are skipped; a module that
 * `requires` another one that is off pulls it in. The connector is added when the owner chose to connect.
 */
export function buildPlan( data: OnboardingData, selected: string[], connectCa: boolean ): string[] {
	const byId = new Map( allModules( data ).map( ( m ) => [ m.id, m ] ) );
	const out: string[] = [];
	const seen = new Set< string >();
	const visit = ( id: string ): void => {
		if ( seen.has( id ) ) {
			return;
		}
		seen.add( id );
		const m = byId.get( id );
		if ( ! m || m.enabled ) {
			return;
		}
		m.requires.forEach( visit );
		out.push( id );
	};
	selected.forEach( visit );
	if ( connectCa && data.ca ) {
		visit( data.ca.id );
	}
	return out;
}

/** Modules that are in the plan only because another selected module requires them. */
export function pulledIn( data: OnboardingData, selected: string[], plan: string[] ): string[] {
	return plan.filter( ( id ) => ! selected.includes( id ) && id !== data.ca?.id );
}

/** Ids of modules recommended for a site type that are not on yet. */
export function recommendedFor( data: OnboardingData, siteType: string ): string[] {
	return data.modules.filter( ( m ) => ! m.enabled && m.types.includes( siteType ) ).map( ( m ) => m.id );
}

// Close to PHP FILTER_VALIDATE_EMAIL: no leading, trailing or doubled dots in the local part, dotted domain labels.
const EMAIL = /^[A-Za-z0-9!#$%&'*+/=?^_`{|}~-]+(?:\.[A-Za-z0-9!#$%&'*+/=?^_`{|}~-]+)*@(?:[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?\.)+[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?$/;

export function validEmail( value: string ): boolean {
	return value.trim() === '' || ( value.length <= 190 && EMAIL.test( value.trim() ) );
}

export function profileFields(): ProfileField[] {
	return [ 'sender_email', 'sender_name', 'contact_email' ];
}

export function hasProfile( profile: Profile ): boolean {
	return profileFields().some( ( f ) => profile[ f ].trim() !== '' );
}
