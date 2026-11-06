/**
 * Typed client of the UX Studio JSON endpoint (administrator/index.php?option=com_uxstudio&task=api.dispatch).
 * URL, CSRF token and locale come from Joomla.getOptions('uxstudio') (set by the component view).
 */
import { QueryClient } from '@tanstack/react-query';

export interface UxsOptions {
	apiUrl: string;
	csrf: string;
	locale: string;
	version: string;
	user: { id: number; name: string };
}

interface JoomlaGlobal {
	getOptions: ( key: string, fallback?: unknown ) => unknown;
}

export function options(): UxsOptions {
	const joomla = ( window as unknown as { Joomla?: JoomlaGlobal } ).Joomla;
	const opts = joomla?.getOptions( 'uxstudio' ) as UxsOptions | undefined;
	if ( ! opts ) {
		throw new Error( 'Joomla.getOptions("uxstudio") is missing.' );
	}
	return opts;
}

export const queryClient = new QueryClient( {
	defaultOptions: {
		queries: { refetchOnWindowFocus: false, staleTime: 30_000, gcTime: 5 * 60_000, retry: false },
	},
} );

/** Error thrown by the client: keeps the machine code and HTTP status of the API error. */
export class ApiError extends Error {
	code: string;
	status: number;

	constructor( message: string, code: string, status: number ) {
		super( message );
		this.name = 'ApiError';
		this.code = code;
		this.status = status;
	}
}

interface Envelope< T > {
	ok: boolean;
	data?: T;
	error?: { code: string; message: string };
}

type Query = Record< string, string | number | boolean | undefined >;

async function request< T >( method: 'GET' | 'POST', route: string, body?: unknown, query?: Query ): Promise< T > {
	const o = options();
	let url = `${ o.apiUrl }&route=${ route.split( '/' ).map( encodeURIComponent ).join( '/' ) }`;
	for ( const [ k, v ] of Object.entries( query ?? {} ) ) {
		if ( v !== undefined ) {
			url += `&${ encodeURIComponent( k ) }=${ encodeURIComponent( String( v ) ) }`;
		}
	}
	const headers: Record< string, string > = { Accept: 'application/json', 'X-CSRF-Token': o.csrf };
	if ( body !== undefined ) {
		headers[ 'Content-Type' ] = 'application/json';
	}
	const res = await fetch( url, {
		method,
		credentials: 'same-origin',
		headers,
		body: body === undefined ? undefined : JSON.stringify( body ),
	} );
	let json: Envelope< T > | null = null;
	try {
		json = ( await res.json() ) as Envelope< T >;
	} catch {
		// Not JSON (for example a Joomla login page after the session expired).
	}
	if ( ! res.ok || ! json || ! json.ok ) {
		throw new ApiError( json?.error?.message ?? `HTTP ${ res.status }`, json?.error?.code ?? 'http_error', res.status );
	}
	return json.data as T;
}

export const api = {
	get: < T >( route: string, query?: Query ): Promise< T > => request< T >( 'GET', route, undefined, query ),
	post: < T >( route: string, body: unknown = {} ): Promise< T > => request< T >( 'POST', route, body ),
};

/* ---- Shared types ---- */

export interface ModuleInfo {
	id: string;
	name: string;
	description: string;
	group: string;
	icon: string;
	settings: boolean;
	enabled: boolean;
	requires: string[];
}

export interface CoreMeta {
	version: string;
	joomla: string;
	php: string;
	locale: string;
	modules: number;
	canAdmin: boolean;
}

export interface DashboardWidget {
	module: string;
	title: string;
	value?: string | number;
	text?: string;
	status?: 'ok' | 'warn' | 'bad';
}
