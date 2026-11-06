/**
 * Tiny i18n. Source strings are English and double as the keys: t('Save') returns the Czech text from cs.json when
 * the administration language is cs-CZ, otherwise the key itself. Placeholders: t('Hello {name}', { name: 'Jan' }).
 *
 * Dictionaries are NOT shared between modules: the core dictionary (app/i18n/{en,cs}.json) backs the global `t`, and every
 * module has its own (modules/<id>/i18n/{en,cs}.json) that is only reachable through createT('<id>'). A module phrase
 * therefore can neither override the core nor another module; the module dictionary wins only inside its own screens,
 * with the core dictionary as the fallback.
 */
import { options } from './api';
import coreCs from './i18n/cs.json';
import coreEn from './i18n/en.json';

type Dict = Record< string, string >;
type Vars = Record< string, string | number >;

const moduleDicts = import.meta.glob< Dict >( '../modules/*/i18n/*.json', { eager: true, import: 'default' } );

function lang(): 'cs' | 'en' {
	try {
		return options().locale.toLowerCase().startsWith( 'cs' ) ? 'cs' : 'en';
	} catch {
		return 'en';
	}
}

function coreDict(): Dict {
	return lang() === 'cs' ? coreCs : coreEn;
}

const moduleCache = new Map< string, Dict >();

/** Dictionary of one module for the current language (empty when it has none). */
function moduleDict( moduleId: string ): Dict {
	const l = lang();
	const key = `${ moduleId }/${ l }`;
	let dict = moduleCache.get( key );
	if ( ! dict ) {
		dict = moduleDicts[ `../modules/${ moduleId }/i18n/${ l }.json` ] ?? {};
		moduleCache.set( key, dict );
	}
	return dict;
}

function fill( text: string, vars?: Vars ): string {
	if ( ! vars ) {
		return text;
	}
	return text.replace( /\{(\w+)\}/g, ( m, k: string ) => ( k in vars ? String( vars[ k ] ) : m ) );
}

function lookup( text: string, moduleId?: string ): string | undefined {
	return ( moduleId ? moduleDict( moduleId )[ text ] : undefined ) ?? coreDict()[ text ];
}

function translate( text: string, vars?: Vars, moduleId?: string ): string {
	return fill( lookup( text, moduleId ) ?? text, vars );
}

/**
 * Plural: tn('{n} item', '{n} items', n). The dictionary key is "<one>|<other>"; its Czech value has three forms
 * "<1>|<2-4>|<5+>".
 */
function translatePlural( one: string, other: string, n: number, vars?: Vars, moduleId?: string ): string {
	const translated = lookup( `${ one }|${ other }`, moduleId );
	let text = n === 1 ? one : other;
	if ( translated ) {
		const forms = translated.split( '|' );
		if ( lang() === 'cs' && forms.length === 3 ) {
			text = n === 1 ? ( forms[ 0 ] as string ) : n >= 2 && n <= 4 ? ( forms[ 1 ] as string ) : ( forms[ 2 ] as string );
		} else {
			text = ( n === 1 ? forms[ 0 ] : forms[ 1 ] ) ?? text;
		}
	}
	return fill( text, { n, ...vars } );
}

export function t( text: string, vars?: Vars ): string {
	return translate( text, vars );
}

export function tn( one: string, other: string, n: number, vars?: Vars ): string {
	return translatePlural( one, other, n, vars );
}

export interface Translator {
	t: ( text: string, vars?: Vars ) => string;
	tn: ( one: string, other: string, n: number, vars?: Vars ) => string;
}

/**
 * Translator of one module: its own dictionary first, then the core one. Use in the module's screens:
 *   const { t } = createT( 'my-module' );   // at module level of Page.tsx
 */
export function createT( moduleId: string ): Translator {
	return {
		t: ( text, vars ) => translate( text, vars, moduleId ),
		tn: ( one, other, n, vars ) => translatePlural( one, other, n, vars, moduleId ),
	};
}

/** BCP 47 tag for Intl formatting. */
export function intlLocale(): string {
	return lang() === 'cs' ? 'cs-CZ' : 'en-GB';
}

/** Format a Joomla/MySQL UTC datetime ("2026-11-06 21:00:00") for display in the browser's zone. */
export function formatDate( value: string | null | undefined ): string {
	if ( ! value ) {
		return '';
	}
	const d = new Date( value.replace( ' ', 'T' ) + 'Z' );
	return Number.isNaN( d.getTime() ) ? value : new Intl.DateTimeFormat( intlLocale(), { dateStyle: 'medium', timeStyle: 'short' } ).format( d );
}
