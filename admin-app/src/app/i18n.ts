/**
 * Tiny i18n. Source strings are English and double as the keys: t('Save') returns the Czech text from cs.json when
 * the administration language is cs-CZ, otherwise the key itself. Module dictionaries (modules/<id>/i18n/{en,cs}.json)
 * are merged automatically; use unique English phrases (a module may prefix them with its id).
 * Placeholders: t('Hello {name}', { name: 'Jan' }).
 */
import { options } from './api';
import coreCs from './i18n/cs.json';
import coreEn from './i18n/en.json';

type Dict = Record< string, string >;

const moduleDicts = import.meta.glob< Dict >( '../modules/*/i18n/*.json', { eager: true, import: 'default' } );

function lang(): 'cs' | 'en' {
	try {
		return options().locale.toLowerCase().startsWith( 'cs' ) ? 'cs' : 'en';
	} catch {
		return 'en';
	}
}

let cache: Dict | null = null;

function dictionary(): Dict {
	if ( cache ) {
		return cache;
	}
	const l = lang();
	const merged: Dict = { ...( l === 'cs' ? coreCs : coreEn ) };
	for ( const [ path, dict ] of Object.entries( moduleDicts ) ) {
		if ( path.endsWith( `/i18n/${ l }.json` ) ) {
			Object.assign( merged, dict );
		}
	}
	cache = merged;
	return merged;
}

function fill( text: string, vars?: Record< string, string | number > ): string {
	if ( ! vars ) {
		return text;
	}
	return text.replace( /\{(\w+)\}/g, ( m, k: string ) => ( k in vars ? String( vars[ k ] ) : m ) );
}

export function t( text: string, vars?: Record< string, string | number > ): string {
	return fill( dictionary()[ text ] ?? text, vars );
}

/**
 * Plural: tn('{n} item', '{n} items', n). The dictionary key is "<one>|<other>"; its Czech value has three forms
 * "<1>|<2-4>|<5+>".
 */
export function tn( one: string, other: string, n: number, vars?: Record< string, string | number > ): string {
	const translated = dictionary()[ `${ one }|${ other }` ];
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
