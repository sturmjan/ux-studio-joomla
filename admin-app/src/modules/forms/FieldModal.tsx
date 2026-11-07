import { useState } from 'react';
import { Plus, Trash2 } from 'lucide-react';
import { createT } from '../../app/i18n';
import { Modal } from '../../app/ui';
import { CHOICE_TYPES, FILE_EXTENSIONS, OPERATORS, WIDTHS, type Condition, type FieldOption, type FormField } from './types';

const { t } = createT( 'forms' );

export function typeLabel( type: string ): string {
	const labels: Record< string, string > = {
		text: t( 'Text' ),
		email: t( 'E-mail' ),
		tel: t( 'Phone' ),
		url: t( 'Web address' ),
		number: t( 'Number' ),
		date: t( 'Date' ),
		textarea: t( 'Long text' ),
		select: t( 'Drop-down' ),
		radio: t( 'Radio buttons' ),
		checkbox_group: t( 'Checkboxes' ),
		checkbox: t( 'Single checkbox' ),
		acceptance: t( 'Consent (GDPR)' ),
		file: t( 'File upload' ),
		hidden: t( 'Hidden value' ),
		html: t( 'Text block' ),
	};
	return labels[ type ] ?? type;
}

/** "Label|value" per line <-> option objects. */
function optionsToText( options: FieldOption[] | undefined ): string {
	return ( options ?? [] ).map( ( o ) => ( o.label === o.value ? o.label : `${ o.label }|${ o.value }` ) ).join( '\n' );
}

function textToOptions( text: string ): FieldOption[] {
	return text
		.split( '\n' )
		.map( ( line ) => line.trim() )
		.filter( Boolean )
		.map( ( line ) => {
			const [ label, ...rest ] = line.split( '|' );
			const value = rest.join( '|' ).trim();
			return { label: ( label ?? '' ).trim(), value: value || ( label ?? '' ).trim() };
		} );
}

function operatorLabel( op: Condition[ 'operator' ] ): string {
	const labels: Record< Condition[ 'operator' ], string > = {
		equals: t( 'equals' ),
		not_equals: t( 'does not equal' ),
		contains: t( 'contains' ),
		empty: t( 'is empty' ),
		not_empty: t( 'is not empty' ),
	};
	return labels[ op ];
}

export function FieldModal( {
	field,
	siblings,
	onSave,
	onClose,
}: {
	field: FormField;
	/** Other fields of the form (targets of conditions). */
	siblings: FormField[];
	onSave: ( next: FormField ) => void;
	onClose: () => void;
} ): JSX.Element {
	const [ f, setF ] = useState< FormField >( field );
	const [ optionsText, setOptionsText ] = useState( optionsToText( field.options ) );
	const set = < K extends keyof FormField >( key: K, value: FormField[ K ] ): void => setF( ( cur ) => ( { ...cur, [ key ]: value } ) );
	const num = ( v: string ): number | null => ( v === '' || Number.isNaN( Number( v ) ) ? null : Number( v ) );

	const isLayout = f.type === 'html';
	const isChoice = CHOICE_TYPES.includes( f.type );
	const textual = [ 'text', 'email', 'tel', 'url', 'textarea' ].includes( f.type );
	const targets = siblings.filter( ( s ) => s.key !== f.key && s.type !== 'html' );

	const row = ( label: string, control: JSX.Element, help?: string ): JSX.Element => (
		<div className="uxs-form__row">
			<label>{ label }</label>
			{ control }
			{ help ? <p className="uxs-form__help">{ help }</p> : null }
		</div>
	);

	const save = (): void => {
		onSave( { ...f, options: isChoice ? textToOptions( optionsText ) : f.options } );
	};

	return (
		<Modal
			open
			wide
			onClose={ onClose }
			title={ `${ typeLabel( f.type ) }: ${ f.label || f.key }` }
			actions={
				<>
					<button type="button" className="uxs-btn" onClick={ onClose }>
						{ t( 'Cancel' ) }
					</button>
					<button type="button" className="uxs-btn uxs-btn--primary" onClick={ save }>
						{ t( 'Apply' ) }
					</button>
				</>
			}
		>
			<div className="uxs-form uxs-form--grid" style={ { maxHeight: '60vh', overflowY: 'auto' } }>
				{ ! isLayout ? row( t( 'Label' ), <input type="text" value={ f.label } maxLength={ 200 } onChange={ ( e ) => set( 'label', e.target.value ) } /> ) : null }
				{ row(
					t( 'Field key' ),
					<input type="text" value={ f.key } maxLength={ 40 } pattern="[a-z][a-z0-9_]*" onChange={ ( e ) => set( 'key', e.target.value.toLowerCase().replace( /[^a-z0-9_]/g, '_' ) ) } />,
					t( 'Used in the e-mail placeholders {key} and as the column of the export. Letters, digits and underscore.' )
				) }
				{ f.type === 'html' ? row( t( 'Content (HTML)' ), <textarea rows={ 6 } value={ f.html ?? '' } onChange={ ( e ) => set( 'html', e.target.value ) } /> ) : null }
				{ ! isLayout && f.type !== 'hidden' && f.type !== 'checkbox' && f.type !== 'acceptance' && ! isChoice && f.type !== 'file'
					? row( t( 'Placeholder' ), <input type="text" value={ f.placeholder } maxLength={ 200 } onChange={ ( e ) => set( 'placeholder', e.target.value ) } /> )
					: null }
				{ ! isLayout ? row( t( 'Help text' ), <input type="text" value={ f.help } maxLength={ 300 } onChange={ ( e ) => set( 'help', e.target.value ) } /> ) : null }
				{ f.type === 'hidden' || f.type === 'text' || f.type === 'textarea' || f.type === 'select' || f.type === 'radio'
					? row( t( 'Default value' ), <input type="text" value={ f.default_value } maxLength={ 500 } onChange={ ( e ) => set( 'default_value', e.target.value ) } /> )
					: null }
				{ ! isLayout && f.type !== 'hidden'
					? row(
							t( 'Required' ),
							<label style={ { display: 'inline-flex', gap: 8, alignItems: 'center' } }>
								<input type="checkbox" checked={ f.required } onChange={ ( e ) => set( 'required', e.target.checked ) } /> { t( 'The visitor has to fill this field' ) }
							</label>
					  )
					: null }
				{ isChoice
					? row( t( 'Options' ), <textarea rows={ 5 } value={ optionsText } onChange={ ( e ) => setOptionsText( e.target.value ) } />, t( 'One option per line. Use "Label|value" to store a value different from the label.' ) )
					: null }
				{ f.type === 'acceptance'
					? row( t( 'Link to the terms' ), <input type="text" value={ f.terms_url ?? '' } placeholder="/ochrana-udaju" onChange={ ( e ) => set( 'terms_url', e.target.value ) } />, t( 'Optional. A path on this site or a full address.' ) )
					: null }
				{ textual
					? row(
							t( 'Length' ),
							<span className="uxs-actions">
								<input type="number" min={ 0 } placeholder={ t( 'min' ) } value={ f.minlength ?? '' } onChange={ ( e ) => set( 'minlength', num( e.target.value ) ) } />
								<input type="number" min={ 1 } placeholder={ t( 'max' ) } value={ f.maxlength ?? '' } onChange={ ( e ) => set( 'maxlength', num( e.target.value ) ) } />
							</span>
					  )
					: null }
				{ f.type === 'number'
					? row(
							t( 'Range' ),
							<span className="uxs-actions">
								<input type="number" placeholder={ t( 'min' ) } value={ f.min ?? '' } onChange={ ( e ) => set( 'min', num( e.target.value ) ) } />
								<input type="number" placeholder={ t( 'max' ) } value={ f.max ?? '' } onChange={ ( e ) => set( 'max', num( e.target.value ) ) } />
							</span>
					  )
					: null }
				{ f.type === 'textarea' ? row( t( 'Rows' ), <input type="number" min={ 2 } max={ 20 } value={ f.rows ?? 5 } onChange={ ( e ) => set( 'rows', Number( e.target.value ) || 5 ) } /> ) : null }
				{ f.type === 'file' ? (
					<>
						{ row(
							t( 'Allowed file types' ),
							<span className="uxs-actions" style={ { flexWrap: 'wrap' } }>
								{ FILE_EXTENSIONS.map( ( ext ) => (
									<label key={ ext } style={ { display: 'inline-flex', gap: 4, alignItems: 'center' } }>
										<input
											type="checkbox"
											checked={ ( f.accept ?? [] ).includes( ext ) }
											onChange={ ( e ) => set( 'accept', e.target.checked ? [ ...( f.accept ?? [] ), ext ] : ( f.accept ?? [] ).filter( ( x ) => x !== ext ) ) }
										/>
										{ ext }
									</label>
								) ) }
							</span>,
							t( 'The content of the file is checked too, not only its name.' )
						) }
						{ row( t( 'Maximum size (MB)' ), <input type="number" min={ 1 } max={ 5 } value={ f.max_size_mb ?? 2 } onChange={ ( e ) => set( 'max_size_mb', Number( e.target.value ) || 2 ) } />, t( 'At most 5 MB per file.' ) ) }
						{ row(
							t( 'Several files' ),
							<label style={ { display: 'inline-flex', gap: 8, alignItems: 'center' } }>
								<input type="checkbox" checked={ !! f.multiple } onChange={ ( e ) => set( 'multiple', e.target.checked ) } /> { t( 'Allow up to 5 files' ) }
							</label>
						) }
					</>
				) : null }
				{ f.type !== 'hidden'
					? row(
							t( 'Width' ),
							<select value={ f.width } onChange={ ( e ) => set( 'width', Number( e.target.value ) ) }>
								{ WIDTHS.map( ( w ) => (
									<option key={ w } value={ w }>
										{ w } %
									</option>
								) ) }
							</select>
					  )
					: null }
				{ f.type !== 'hidden' ? row( t( 'CSS class' ), <input type="text" value={ f.css_class } maxLength={ 100 } onChange={ ( e ) => set( 'css_class', e.target.value ) } /> ) : null }
				{ f.type !== 'hidden'
					? row(
							t( 'Show only when' ),
							<div>
								{ f.conditions.map( ( c, i ) => (
									<div key={ i } className="uxs-actions" style={ { marginBottom: 8 } }>
										<select value={ c.field } onChange={ ( e ) => set( 'conditions', f.conditions.map( ( x, j ) => ( j === i ? { ...x, field: e.target.value } : x ) ) ) } aria-label={ t( 'Field' ) }>
											{ targets.map( ( s ) => (
												<option key={ s.key } value={ s.key }>
													{ s.label || s.key }
												</option>
											) ) }
										</select>
										<select value={ c.operator } onChange={ ( e ) => set( 'conditions', f.conditions.map( ( x, j ) => ( j === i ? { ...x, operator: e.target.value as Condition[ 'operator' ] } : x ) ) ) } aria-label={ t( 'Operator' ) }>
											{ OPERATORS.map( ( op ) => (
												<option key={ op } value={ op }>
													{ operatorLabel( op ) }
												</option>
											) ) }
										</select>
										{ c.operator !== 'empty' && c.operator !== 'not_empty' ? (
											<input type="text" value={ c.value } aria-label={ t( 'Value' ) } onChange={ ( e ) => set( 'conditions', f.conditions.map( ( x, j ) => ( j === i ? { ...x, value: e.target.value } : x ) ) ) } />
										) : null }
										<button type="button" className="uxs-btn uxs-btn--small" aria-label={ t( 'Remove' ) } onClick={ () => set( 'conditions', f.conditions.filter( ( _, j ) => j !== i ) ) }>
											<Trash2 size={ 14 } aria-hidden="true" />
										</button>
									</div>
								) ) }
								<div className="uxs-actions">
									<button
										type="button"
										className="uxs-btn uxs-btn--small"
										disabled={ targets.length === 0 }
										onClick={ () => set( 'conditions', [ ...f.conditions, { field: targets[ 0 ]?.key ?? '', operator: 'equals', value: '' } ] ) }
									>
										<Plus size={ 14 } aria-hidden="true" /> { t( 'Add condition' ) }
									</button>
									{ f.conditions.length > 1 ? (
										<select value={ f.logic } onChange={ ( e ) => set( 'logic', e.target.value === 'any' ? 'any' : 'all' ) } aria-label={ t( 'Logic' ) }>
											<option value="all">{ t( 'all conditions match' ) }</option>
											<option value="any">{ t( 'any condition matches' ) }</option>
										</select>
									) : null }
								</div>
							</div>,
							t( 'Without a condition the field is always shown. A hidden field is neither validated nor submitted.' )
					  )
					: null }
			</div>
		</Modal>
	);
}
