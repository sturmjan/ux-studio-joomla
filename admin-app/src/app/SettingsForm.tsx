/**
 * Schema-driven settings form (generic renderer). Modules without a custom Page get it automatically; custom pages
 * can embed <SettingsPanel moduleId=... /> or use useModuleSettings() + <SettingsFields />.
 * Field types: toggle | text | textarea | css | number | select | multiselect | color | media | datetime | richtext | password.
 */
import { Fragment, useEffect, useId, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import { ChevronDown } from 'lucide-react';
import { useMutation, useQuery } from '@tanstack/react-query';
import { api, queryClient } from './api';
import { t } from './i18n';
import { pushToast } from './notify';
import { type Scalar, type ShowIf, isEmptyValue, visibleKeys } from './showIf';
import { MediaField, RepeaterField, RichTextField } from './FieldWidgets';
import { ToggleSwitch } from './ui/ToggleSwitch';

/** One row of a repeater field: sub field key => value. */
export interface Row {
	[ key: string ]: Value;
}

export type Value = string | number | boolean | string[] | Row[] | null;

export interface Field {
	key: string;
	type: 'toggle' | 'text' | 'textarea' | 'css' | 'number' | 'select' | 'multiselect' | 'color' | 'media' | 'datetime' | 'richtext' | 'password' | 'repeater';
	label: string;
	help?: string;
	default?: Value;
	options?: Record< string, string >;
	section?: string;
	group?: string;
	group_help?: string;
	show_if?: ShowIf;
	wide?: boolean;
	min?: number;
	max?: number;
	step?: number;
	maxlength?: number;
	/** textarea: the server refuses over-long input instead of cutting it (lists whose entries must stay whole) */
	strict?: boolean;
	placeholder?: string;
	/** repeater: sub fields of one row, maximum number of rows and the label of the "add" button */
	fields?: Field[];
	add_label?: string;
}

export interface SettingsPayload {
	id: string;
	schema: Field[];
	values: Record< string, Value >;
}

/** Marker the server sends instead of a stored secret (and accepts back meaning "unchanged"). */
export const SECRET_MARKER = '__set__';

const WIDE_TYPES: ReadonlyArray< Field[ 'type' ] > = [ 'textarea', 'css', 'richtext', 'multiselect', 'repeater' ];

/** Empty value of a field (new repeater rows). */
function blankValue( field: Field ): Value {
	if ( field.default !== undefined ) {
		return field.default;
	}
	return field.type === 'toggle' ? false : field.type === 'number' ? 0 : field.type === 'multiselect' || field.type === 'repeater' ? [] : '';
}

function PasswordField( { field, value, onChange }: { field: Field; value: Value; onChange: ( v: Value ) => void } ): JSX.Element {
	const stored = value === SECRET_MARKER;
	const [ editing, setEditing ] = useState( false );
	if ( stored && ! editing ) {
		return (
			<span className="uxs-actions">
				<input type="password" value="••••••••" disabled aria-label={ field.label } readOnly />
				<span className="uxs-muted">{ t( 'Stored encrypted' ) }</span>
				<button type="button" className="uxs-btn uxs-btn--small" onClick={ () => { setEditing( true ); onChange( '' ); } }>
					{ t( 'Change' ) }
				</button>
				<button type="button" className="uxs-btn uxs-btn--small uxs-btn--danger" onClick={ () => onChange( '' ) }>
					{ t( 'Remove' ) }
				</button>
			</span>
		);
	}
	return (
		<input
			type="password"
			id={ `uxs-${ field.key }` }
			autoComplete="new-password"
			value={ String( value ?? '' ) }
			maxLength={ field.maxlength }
			onChange={ ( e ) => onChange( e.target.value ) }
		/>
	);
}

export function FieldInput( { field, value, onChange }: { field: Field; value: Value; onChange: ( v: Value ) => void } ): JSX.Element {
	const id = `uxs-${ field.key }`;
	switch ( field.type ) {
		case 'toggle':
			return <ToggleSwitch checked={ !! value } onChange={ onChange } label={ field.label } />;
		case 'select':
			return (
				<select id={ id } value={ String( value ?? '' ) } onChange={ ( e ) => onChange( e.target.value ) }>
					<option value="">{ t( 'Select an option' ) }</option>
					{ Object.entries( field.options ?? {} ).map( ( [ k, label ] ) => (
						<option key={ k } value={ k }>
							{ label }
						</option>
					) ) }
				</select>
			);
		case 'multiselect': {
			const selected = Array.isArray( value ) ? value.map( String ) : [];
			return (
				<div className="uxs-checklist">
					{ Object.entries( field.options ?? {} ).map( ( [ k, label ] ) => (
						<label key={ k } className="uxs-checklist__item">
							<input
								type="checkbox"
								checked={ selected.includes( k ) }
								onChange={ ( e ) => onChange( e.target.checked ? [ ...selected, k ] : selected.filter( ( v ) => v !== k ) ) }
							/>
							{ label }
						</label>
					) ) }
				</div>
			);
		}
		case 'number':
			return (
				<input
					type="number"
					id={ id }
					min={ field.min }
					max={ field.max }
					step={ field.step }
					value={ value === null || value === '' ? '' : Number( value ) }
					onChange={ ( e ) => onChange( e.target.value === '' ? 0 : Number( e.target.value ) ) }
				/>
			);
		case 'color':
			return (
				<span className="uxs-color">
					<input type="color" aria-label={ field.label } value={ /^#[0-9a-f]{6}$/i.test( String( value ?? '' ) ) ? String( value ) : '#000000' } onChange={ ( e ) => onChange( e.target.value ) } />
					<input type="text" id={ id } value={ String( value ?? '' ) } maxLength={ 9 } onChange={ ( e ) => onChange( e.target.value ) } />
				</span>
			);
		case 'datetime':
			return (
				<span className="uxs-actions">
					<input type="datetime-local" id={ id } value={ String( value ?? '' ).replace( ' ', 'T' ).slice( 0, 16 ) } onChange={ ( e ) => onChange( e.target.value ) } />
					{ value ? (
						<button type="button" className="uxs-link-btn" onClick={ () => onChange( '' ) }>
							{ t( 'Clear' ) }
						</button>
					) : null }
				</span>
			);
		case 'css':
			return <textarea id={ id } rows={ 10 } spellCheck={ false } className="uxs-code-editor" value={ String( value ?? '' ) } onChange={ ( e ) => onChange( e.target.value ) } />;
		case 'richtext':
			return <RichTextField id={ id } label={ field.label } value={ String( value ?? '' ) } maxLength={ field.maxlength } onChange={ onChange } />;
		case 'media':
			return <MediaField id={ id } label={ field.label } value={ String( value ?? '' ) } maxLength={ field.maxlength } onChange={ onChange } />;
		case 'repeater': {
			const subs = field.fields ?? [];
			const rows = Array.isArray( value ) ? ( value as Row[] ) : [];
			return (
				<RepeaterField< Row >
					label={ field.label }
					addLabel={ field.add_label }
					rows={ rows }
					max={ field.max ?? 50 }
					onChange={ onChange }
					blank={ () => Object.fromEntries( subs.map( ( sub ) => [ sub.key, blankValue( sub ) ] ) ) }
					renderRow={ ( row, index, update ) =>
						subs.map( ( sub ) => {
							const subId = `uxs-${ field.key }-${ index }-${ sub.key }`;
							return (
								<div key={ sub.key } className="uxs-repeater__cell">
									<label htmlFor={ subId }>{ sub.label }</label>
									<FieldInput field={ { ...sub, key: `${ field.key }-${ index }-${ sub.key }` } } value={ row[ sub.key ] ?? null } onChange={ ( v ) => update( { [ sub.key ]: v } ) } />
									{ sub.help ? <p className="uxs-form__help">{ sub.help }</p> : null }
								</div>
							);
						} )
					}
				/>
			);
		}
		case 'textarea':
			return <textarea id={ id } rows={ 5 } maxLength={ field.strict ? undefined : field.maxlength } value={ String( value ?? '' ) } onChange={ ( e ) => onChange( e.target.value ) } />;
		case 'password':
			return <PasswordField field={ field } value={ value } onChange={ onChange } />;
		default:
			return (
				<input
					type="text"
					id={ id }
					maxLength={ field.maxlength }
					placeholder={ field.placeholder }
					value={ String( value ?? '' ) }
					onChange={ ( e ) => onChange( e.target.value ) }
				/>
			);
	}
}

function prefersReducedMotion(): boolean {
	return typeof window.matchMedia === 'function' && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;
}

/** Smooth expand / collapse; children are not rendered while hidden. */
export function Reveal( { show, children }: { show: boolean; children: ReactNode } ): JSX.Element | null {
	const [ mounted, setMounted ] = useState( show );
	const [ open, setOpen ] = useState( show );
	const [ settled, setSettled ] = useState( show );
	useEffect( () => {
		if ( prefersReducedMotion() ) {
			setMounted( show );
			setOpen( show );
			setSettled( show );
			return undefined;
		}
		if ( show ) {
			setMounted( true );
			const frame = window.requestAnimationFrame( () => window.requestAnimationFrame( () => setOpen( true ) ) );
			return () => window.cancelAnimationFrame( frame );
		}
		setOpen( false );
		setSettled( false );
		const timer = window.setTimeout( () => setMounted( false ), 260 );
		return () => window.clearTimeout( timer );
	}, [ show ] );
	if ( ! mounted ) {
		return null;
	}
	return (
		<div
			className={ `uxs-reveal${ open ? ' is-open' : '' }${ settled ? ' is-settled' : '' }` }
			onTransitionEnd={ ( e ) => {
				if ( e.target === e.currentTarget && open ) {
					setSettled( true );
				}
			} }
		>
			<div className="uxs-reveal__inner">{ children }</div>
		</div>
	);
}

function isDefault( field: Field, value: Value | undefined ): boolean {
	const fallback = field.default ?? null;
	if ( isEmptyValue( value ?? null ) && isEmptyValue( fallback ) ) {
		return true;
	}
	return JSON.stringify( value ?? null ) === JSON.stringify( fallback );
}

interface FieldGroup {
	key: string;
	title: string;
	help: string;
	fields: Field[];
}

function groupFields( fields: Field[], tab: string ): FieldGroup[] {
	const groups: FieldGroup[] = [];
	for ( const field of fields ) {
		const title = field.group ?? '';
		let group = groups.find( ( g ) => g.title === title );
		if ( ! group ) {
			group = { key: `${ tab }::${ title }`, title, help: '', fields: [] };
			groups.push( group );
		}
		group.help = group.help || field.group_help || '';
		group.fields.push( field );
	}
	return groups;
}

/**
 * Renders a schema as a field grid. Two or more `section`s become sub-tabs, fields with a `group` become cards,
 * `show_if` hides dependent fields. The caller owns the heading and the Save button.
 */
export function SettingsFields( {
	schema,
	draft,
	setDraft,
}: {
	schema: Field[];
	draft: Record< string, Value >;
	setDraft: ( updater: ( d: Record< string, Value > ) => Record< string, Value > ) => void;
} ): JSX.Element {
	const general = t( 'General' );
	const sections: string[] = [];
	for ( const field of schema ) {
		const name = field.section || general;
		if ( ! sections.includes( name ) ) {
			sections.push( name );
		}
	}
	const [ active, setActive ] = useState( 0 );
	const [ toggled, setToggled ] = useState< Record< string, boolean > >( {} );
	const initialOpen = useRef< Record< string, boolean > >( {} );
	const idBase = useId();
	const current = sections[ Math.min( active, sections.length - 1 ) ] ?? general;
	const visible = sections.length > 1 ? schema.filter( ( f ) => ( f.section || general ) === current ) : schema;

	const ready = Object.keys( draft ).length > 0;
	const values: Record< string, Value > = ready ? draft : Object.fromEntries( schema.map( ( f ) => [ f.key, f.default ?? null ] ) );
	const shown = visibleKeys( schema, values as Record< string, Scalar > );

	const row = ( field: Field ): JSX.Element => (
		<div className={ `uxs-form__row${ field.wide || WIDE_TYPES.includes( field.type ) ? ' uxs-form__row--wide' : '' }` }>
			<label htmlFor={ `uxs-${ field.key }` }>{ field.label }</label>
			<FieldInput field={ field } value={ draft[ field.key ] ?? null } onChange={ ( v ) => setDraft( ( d ) => ( { ...d, [ field.key ]: v } ) ) } />
			{ field.help ? <p className="uxs-form__help">{ field.help }</p> : null }
		</div>
	);
	const rows = ( fields: Field[] ): JSX.Element[] =>
		fields.map( ( field ) =>
			field.show_if ? (
				<Reveal key={ field.key } show={ shown.has( field.key ) }>
					{ row( field ) }
				</Reveal>
			) : (
				<Fragment key={ field.key }>{ row( field ) }</Fragment>
			)
		);

	let body: JSX.Element;
	if ( ! visible.some( ( f ) => f.group ) ) {
		body = <div className="uxs-form uxs-form--grid">{ rows( visible ) }</div>;
	} else {
		body = (
			<div className="uxs-groups">
				{ groupFields( visible, current ).map( ( group, index ) => {
					if ( ! group.fields.some( ( f ) => shown.has( f.key ) ) ) {
						return null;
					}
					if ( ready && initialOpen.current[ group.key ] === undefined ) {
						initialOpen.current[ group.key ] = index === 0 || group.fields.some( ( f ) => ! isDefault( f, draft[ f.key ] ) );
					}
					const open = toggled[ group.key ] ?? initialOpen.current[ group.key ] ?? index === 0;
					const regionId = `${ idBase }-group-${ index }`;
					return (
						<div key={ group.key } className="uxs-form uxs-form--grid uxs-group">
							<button
								type="button"
								className="uxs-group__head"
								aria-expanded={ open }
								aria-controls={ regionId }
								onClick={ () => setToggled( ( s ) => ( { ...s, [ group.key ]: ! open } ) ) }
							>
								<span className="uxs-group__titles">
									<span className="uxs-group__title">{ group.title || general }</span>
									{ group.help ? <span className="uxs-group__help">{ group.help }</span> : null }
								</span>
								<ChevronDown size={ 18 } aria-hidden="true" className="uxs-group__chevron" />
							</button>
							<Reveal show={ open }>
								<div id={ regionId } className="uxs-group__body">
									{ rows( group.fields ) }
								</div>
							</Reveal>
						</div>
					);
				} ) }
			</div>
		);
	}

	if ( sections.length < 2 ) {
		return body;
	}
	return (
		<>
			<div className="uxs-subtabs" role="tablist">
				{ sections.map( ( name, i ) => (
					<button key={ name } type="button" role="tab" aria-selected={ name === current } className={ name === current ? 'is-active' : '' } onClick={ () => setActive( i ) }>
						{ name }
					</button>
				) ) }
			</div>
			<h2 className="uxs-form__section">{ current }</h2>
			{ body }
		</>
	);
}

/** Loads schema + values of a module, keeps a local draft and exposes a save mutation (with toasts). */
export function useModuleSettings( id: string ) {
	const [ draft, setDraft ] = useState< Record< string, Value > >( {} );

	const query = useQuery( {
		queryKey: [ 'settings', id ],
		queryFn: () => api.get< SettingsPayload >( `core/settings/${ id }` ),
		enabled: id !== '',
	} );

	useEffect( () => {
		if ( query.data ) {
			setDraft( query.data.values );
		}
	}, [ query.data ] );

	// A second submit while a save is running (double click) joins the running request instead of posting again.
	const inFlight = useRef< Promise< SettingsPayload > | null >( null );
	const save = useMutation( {
		mutationFn: (): Promise< SettingsPayload > => {
			if ( inFlight.current ) {
				return inFlight.current;
			}
			const run = ( async (): Promise< SettingsPayload > => {
				const toast = pushToast( 'loading', t( 'Saving…' ) );
				try {
					const result = await api.post< SettingsPayload >( `core/settings/${ id }`, draft );
					pushToast( 'success', t( 'Settings saved.' ), toast );
					return result;
				} catch ( e ) {
					pushToast( 'error', e instanceof Error ? e.message : t( 'Something went wrong.' ), toast );
					throw e;
				}
			} )().finally( () => {
				inFlight.current = null;
			} );
			inFlight.current = run;
			return run;
		},
		onSuccess: ( result ) => {
			queryClient.setQueryData( [ 'settings', id ], result );
			setDraft( result.values );
		},
	} );

	return { ...query, draft, setDraft, save };
}

/** Complete settings block (fields + Save button) for a module; used by the generic page and by custom pages. */
export function SettingsPanel( { moduleId }: { moduleId: string } ): JSX.Element {
	const { data, isLoading, error, draft, setDraft, save } = useModuleSettings( moduleId );
	if ( isLoading ) {
		return <p className="uxs-muted">{ t( 'Loading…' ) }</p>;
	}
	if ( error || ! data ) {
		return <p role="alert">{ error instanceof Error ? error.message : t( 'Something went wrong.' ) }</p>;
	}
	if ( data.schema.length === 0 ) {
		return <p className="uxs-muted">{ t( 'This module has no settings. It works as soon as it is turned on.' ) }</p>;
	}
	return (
		<form
			onSubmit={ ( e ) => {
				e.preventDefault();
				save.mutate();
			} }
		>
			<SettingsFields schema={ data.schema } draft={ draft } setDraft={ setDraft } />
			<div className="uxs-form__actions">
				<button type="submit" className="uxs-btn uxs-btn--primary" disabled={ save.isPending }>
					{ t( 'Save' ) }
				</button>
			</div>
		</form>
	);
}
