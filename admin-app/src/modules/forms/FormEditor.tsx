import { useEffect, useState } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import { ArrowDown, ArrowLeft, ArrowUp, Copy, Pencil, Plus, Save, Trash2 } from 'lucide-react';
import { api, queryClient } from '../../app/api';
import { createT } from '../../app/i18n';
import { Loading, PageHead, TabPanel, Tabs, pushToast } from '../../app/ui';
import { FieldModal, typeLabel } from './FieldModal';
import { CHOICE_TYPES, FIELD_TYPES, type FieldType, type FormDef, type FormField, type FormSettings } from './types';

const { t } = createT( 'forms' );

/** A fresh field of the given type with sensible defaults (the server sanitizes everything again). */
function newField( type: FieldType, taken: string[] ): FormField {
	let key = type === 'checkbox_group' ? 'choices' : type === 'acceptance' ? 'consent' : type;
	for ( let i = 2; taken.includes( key ); i++ ) {
		key = `${ type }_${ i }`;
	}
	const field: FormField = {
		key,
		type,
		label: type === 'html' || type === 'hidden' ? '' : typeLabel( type ),
		placeholder: '',
		help: '',
		required: false,
		default_value: '',
		width: 100,
		css_class: '',
		conditions: [],
		logic: 'all',
	};
	if ( CHOICE_TYPES.includes( type ) ) {
		field.options = [
			{ label: t( 'Option 1' ), value: t( 'Option 1' ) },
			{ label: t( 'Option 2' ), value: t( 'Option 2' ) },
		];
	}
	if ( type === 'file' ) {
		field.accept = [ 'jpg', 'png', 'pdf' ];
		field.max_size_mb = 2;
	}
	if ( type === 'textarea' ) {
		field.rows = 5;
	}
	return field;
}

export default function FormEditor( { id, hasTurnstile, onBack }: { id: number; hasTurnstile: boolean; onBack: () => void } ): JSX.Element {
	const [ draft, setDraft ] = useState< FormDef | null >( null );
	const [ tab, setTab ] = useState( 'fields' );
	const [ editing, setEditing ] = useState< number | null >( null );
	const [ addType, setAddType ] = useState< FieldType >( 'text' );

	const query = useQuery( {
		queryKey: [ 'forms', 'form', id ],
		queryFn: () => api.get< { form: FormDef } >( id > 0 ? `forms/form/${ id }` : 'forms/new' ),
		staleTime: 0,
	} );
	useEffect( () => {
		if ( query.data ) {
			setDraft( query.data.form );
		}
	}, [ query.data ] );

	const save = useMutation( {
		mutationFn: async ( form: FormDef ) => {
			const toast = pushToast( 'loading', t( 'Saving…' ) );
			try {
				const res = await api.post< { form: FormDef } >( form.id > 0 ? `forms/form/${ form.id }` : 'forms/form', form );
				pushToast( 'success', t( 'Form saved.' ), toast );
				return res;
			} catch ( e ) {
				pushToast( 'error', e instanceof Error ? e.message : t( 'Something went wrong.' ), toast );
				throw e;
			}
		},
		onSuccess: ( res ) => {
			setDraft( res.form );
			void queryClient.invalidateQueries( { queryKey: [ 'forms', 'list' ] } );
			queryClient.setQueryData( [ 'forms', 'form', res.form.id ], res );
		},
	} );

	if ( query.isLoading || ! draft ) {
		return query.error ? <p role="alert">{ query.error.message }</p> : <Loading />;
	}

	const setSettings = < K extends keyof FormSettings >( key: K, value: FormSettings[ K ] ): void => setDraft( { ...draft, settings: { ...draft.settings, [ key ]: value } } );
	const move = ( index: number, delta: number ): void => {
		const next = [ ...draft.fields ];
		const target = index + delta;
		if ( target < 0 || target >= next.length ) {
			return;
		}
		[ next[ index ], next[ target ] ] = [ next[ target ] as FormField, next[ index ] as FormField ];
		setDraft( { ...draft, fields: next } );
	};
	const emailFields = draft.fields.filter( ( f ) => f.type === 'email' );
	const s = draft.settings;

	const row = ( label: string, control: JSX.Element, help?: string ): JSX.Element => (
		<div className="uxs-form__row">
			<label>{ label }</label>
			{ control }
			{ help ? <p className="uxs-form__help">{ help }</p> : null }
		</div>
	);
	const check = ( value: boolean, onChange: ( v: boolean ) => void, text: string ): JSX.Element => (
		<label style={ { display: 'inline-flex', gap: 8, alignItems: 'center' } }>
			<input type="checkbox" checked={ value } onChange={ ( e ) => onChange( e.target.checked ) } /> { text }
		</label>
	);

	return (
		<>
			<PageHead title={ draft.id > 0 ? t( 'Edit form' ) : t( 'New form' ) }>
				<button type="button" className="uxs-btn" onClick={ onBack }>
					<ArrowLeft size={ 14 } aria-hidden="true" /> { t( 'Back to forms' ) }
				</button>
				<button type="button" className="uxs-btn uxs-btn--primary" disabled={ save.isPending || draft.title.trim() === '' } onClick={ () => save.mutate( draft ) }>
					<Save size={ 14 } aria-hidden="true" /> { t( 'Save' ) }
				</button>
			</PageHead>

			<div className="uxs-form uxs-form--grid" style={ { marginBottom: 'var(--uxs-sp-4)' } }>
				{ row( t( 'Form name' ), <input type="text" value={ draft.title } maxLength={ 190 } onChange={ ( e ) => setDraft( { ...draft, title: e.target.value } ) } /> ) }
				{ row(
					t( 'Status' ),
					<select value={ draft.status } onChange={ ( e ) => setDraft( { ...draft, status: e.target.value === 'draft' ? 'draft' : 'published' } ) }>
						<option value="published">{ t( 'Published' ) }</option>
						<option value="draft">{ t( 'Draft (hidden on the site)' ) }</option>
					</select>
				) }
				{ draft.id > 0
					? row(
							t( 'Where to show it' ),
							<span>
								<code>{ draft.shortcode }</code>{ ' ' }
								<button type="button" className="uxs-btn uxs-btn--small" onClick={ () => void navigator.clipboard?.writeText( draft.shortcode ).then( () => pushToast( 'success', t( 'Copied.' ) ) ) }>
									<Copy size={ 14 } aria-hidden="true" /> { t( 'Copy' ) }
								</button>
							</span>,
							t( 'Paste the code into an article or a Custom module, or add the module "UX Studio - Form" to a template position.' )
					  )
					: null }
			</div>

			<Tabs
				label={ t( 'Form sections' ) }
				active={ tab }
				onChange={ setTab }
				items={ [
					{ id: 'fields', label: t( 'Fields' ) },
					{ id: 'mail', label: t( 'E-mails' ) },
					{ id: 'after', label: t( 'After submit' ) },
					{ id: 'spam', label: t( 'Spam protection' ) },
				] }
			/>

			{ tab === 'fields' ? (
				<TabPanel id="fields">
					<div className="uxs-table-wrap">
						<table className="uxs-table">
							<thead>
								<tr>
									<th scope="col">{ t( 'Label' ) }</th>
									<th scope="col">{ t( 'Type' ) }</th>
									<th scope="col">{ t( 'Key' ) }</th>
									<th scope="col">{ t( 'Actions' ) }</th>
								</tr>
							</thead>
							<tbody>
								{ draft.fields.length === 0 ? (
									<tr>
										<td className="is-empty" colSpan={ 4 }>
											{ t( 'No fields yet.' ) }
										</td>
									</tr>
								) : (
									draft.fields.map( ( f, i ) => (
										<tr key={ f.key }>
											<td>
												{ f.label || ( f.type === 'html' ? t( '(text block)' ) : f.key ) }
												{ f.required ? <span aria-hidden="true"> *</span> : null }
												{ f.conditions.length > 0 ? <span className="uxs-badge is-info" style={ { marginLeft: 8 } }>{ t( 'conditional' ) }</span> : null }
											</td>
											<td>{ typeLabel( f.type ) }</td>
											<td>
												<code>{ f.key }</code>
											</td>
											<td>
												<span className="uxs-actions">
													<button type="button" className="uxs-btn uxs-btn--small" aria-label={ t( 'Move up' ) } disabled={ i === 0 } onClick={ () => move( i, -1 ) }>
														<ArrowUp size={ 14 } aria-hidden="true" />
													</button>
													<button type="button" className="uxs-btn uxs-btn--small" aria-label={ t( 'Move down' ) } disabled={ i === draft.fields.length - 1 } onClick={ () => move( i, 1 ) }>
														<ArrowDown size={ 14 } aria-hidden="true" />
													</button>
													<button type="button" className="uxs-btn uxs-btn--small" onClick={ () => setEditing( i ) }>
														<Pencil size={ 14 } aria-hidden="true" /> { t( 'Edit' ) }
													</button>
													<button
														type="button"
														className="uxs-btn uxs-btn--small"
														aria-label={ t( 'Duplicate' ) }
														onClick={ () => {
															const copy = { ...f, key: `${ f.key.slice( 0, 34 ) }_copy` };
															setDraft( { ...draft, fields: [ ...draft.fields.slice( 0, i + 1 ), copy, ...draft.fields.slice( i + 1 ) ] } );
														} }
													>
														<Copy size={ 14 } aria-hidden="true" />
													</button>
													<button
														type="button"
														className="uxs-btn uxs-btn--small uxs-btn--danger"
														aria-label={ t( 'Delete' ) }
														onClick={ () => setDraft( { ...draft, fields: draft.fields.filter( ( _, j ) => j !== i ) } ) }
													>
														<Trash2 size={ 14 } aria-hidden="true" />
													</button>
												</span>
											</td>
										</tr>
									) )
								) }
							</tbody>
						</table>
					</div>
					<div className="uxs-actions" style={ { marginTop: 'var(--uxs-sp-4)' } }>
						<select value={ addType } onChange={ ( e ) => setAddType( e.target.value as FieldType ) } aria-label={ t( 'Field type' ) }>
							{ FIELD_TYPES.map( ( ft ) => (
								<option key={ ft } value={ ft }>
									{ typeLabel( ft ) }
								</option>
							) ) }
						</select>
						<button
							type="button"
							className="uxs-btn"
							onClick={ () => {
								const field = newField( addType, draft.fields.map( ( x ) => x.key ) );
								setDraft( { ...draft, fields: [ ...draft.fields, field ] } );
								setEditing( draft.fields.length );
							} }
						>
							<Plus size={ 14 } aria-hidden="true" /> { t( 'Add field' ) }
						</button>
					</div>
					{ editing !== null && draft.fields[ editing ] ? (
						<FieldModal
							key={ draft.fields[ editing ]?.key }
							field={ draft.fields[ editing ] as FormField }
							siblings={ draft.fields }
							onClose={ () => setEditing( null ) }
							onSave={ ( next ) => {
								const old = draft.fields[ editing ] as FormField;
								// Keep conditions of other fields pointing at the renamed key.
								const fields = draft.fields.map( ( f, j ) =>
									j === editing ? next : { ...f, conditions: f.conditions.map( ( c ) => ( c.field === old.key ? { ...c, field: next.key } : c ) ) }
								);
								setDraft( { ...draft, fields } );
								setEditing( null );
							} }
						/>
					) : null }
				</TabPanel>
			) : null }

			{ tab === 'mail' ? (
				<TabPanel id="mail">
					<div className="uxs-form uxs-form--grid">
						{ row( t( 'Notification' ), check( s.send_notification, ( v ) => setSettings( 'send_notification', v ), t( 'Send an e-mail for every submission' ) ) ) }
						{ s.send_notification ? (
							<>
								{ row( t( 'Recipients' ), <input type="text" value={ s.to } placeholder="info@example.com, sales@example.com" onChange={ ( e ) => setSettings( 'to', e.target.value ) } />, t( 'Comma separated. Empty: the default recipient from the module settings.' ) ) }
								{ row( t( 'Subject' ), <input type="text" value={ s.subject } maxLength={ 250 } placeholder={ t( 'New submission: {form_title}' ) } onChange={ ( e ) => setSettings( 'subject', e.target.value ) } />, t( 'Placeholders: {form_title}, {site_name}, {submission_id} and {field key}.' ) ) }
								{ row(
									t( 'Reply-To' ),
									<select value={ s.reply_to_field } onChange={ ( e ) => setSettings( 'reply_to_field', e.target.value ) }>
										<option value="">{ t( '- none -' ) }</option>
										{ emailFields.map( ( f ) => (
											<option key={ f.key } value={ f.key }>
												{ f.label || f.key }
											</option>
										) ) }
									</select>,
									t( 'Replying to the notification then goes to the visitor.' )
								) }
								{ draft.fields.some( ( f ) => f.type === 'file' ) ? row( t( 'Attachments' ), check( s.attach_files, ( v ) => setSettings( 'attach_files', v ), t( 'Attach uploaded files to the e-mail' ) ) ) : null }
							</>
						) : null }
						{ row( t( 'Confirmation to the visitor' ), check( s.confirm_enabled, ( v ) => setSettings( 'confirm_enabled', v ), t( 'Send a confirmation e-mail' ) ) ) }
						{ s.confirm_enabled ? (
							<>
								{ row(
									t( 'Send to the address in' ),
									<select value={ s.confirm_field } onChange={ ( e ) => setSettings( 'confirm_field', e.target.value ) }>
										<option value="">{ t( '- choose an e-mail field -' ) }</option>
										{ emailFields.map( ( f ) => (
											<option key={ f.key } value={ f.key }>
												{ f.label || f.key }
											</option>
										) ) }
									</select>
								) }
								{ row( t( 'Confirmation subject' ), <input type="text" value={ s.confirm_subject } maxLength={ 250 } onChange={ ( e ) => setSettings( 'confirm_subject', e.target.value ) } /> ) }
								{ row( t( 'Confirmation text' ), <textarea rows={ 5 } value={ s.confirm_message } onChange={ ( e ) => setSettings( 'confirm_message', e.target.value ) } />, t( 'Plain text; the same placeholders work. Empty: a default thank-you text.' ) ) }
							</>
						) : null }
					</div>
				</TabPanel>
			) : null }

			{ tab === 'after' ? (
				<TabPanel id="after">
					<div className="uxs-form uxs-form--grid">
						{ row( t( 'Button text' ), <input type="text" value={ s.submit_label } maxLength={ 80 } placeholder={ t( 'Send' ) } onChange={ ( e ) => setSettings( 'submit_label', e.target.value ) } /> ) }
						{ row( t( 'Thank-you message' ), <input type="text" value={ s.success_message } maxLength={ 500 } onChange={ ( e ) => setSettings( 'success_message', e.target.value ) } />, t( 'Shown under the form after a successful submission.' ) ) }
						{ row( t( 'Redirect to' ), <input type="text" value={ s.redirect_url } placeholder="/dekujeme" onChange={ ( e ) => setSettings( 'redirect_url', e.target.value ) } />, t( 'Optional. A path on this site or a full http(s) address; the thank-you message is then skipped.' ) ) }
						{ row( t( 'Archive' ), check( s.store, ( v ) => setSettings( 'store', v ), t( 'Keep submissions in the archive' ) ), t( 'Without the archive uploaded files are not kept either; the e-mail is the only record.' ) ) }
					</div>
				</TabPanel>
			) : null }

			{ tab === 'spam' ? (
				<TabPanel id="spam">
					<div className="uxs-form uxs-form--grid">
						{ row( t( 'Honeypot' ), <span className="uxs-muted">{ t( 'Always on: a hidden field that only bots fill in.' ) }</span> ) }
						{ row(
							t( 'Minimum fill time (seconds)' ),
							<input type="number" min={ 0 } max={ 60 } value={ s.min_time } onChange={ ( e ) => setSettings( 'min_time', Number( e.target.value ) || 0 ) } />,
							t( 'Submissions sent faster than this after the page loaded are rejected. 0 turns the check off.' )
						) }
						{ row(
							t( 'Cloudflare Turnstile' ),
							check( s.turnstile, ( v ) => setSettings( 'turnstile', v ), t( 'Require the Turnstile challenge' ) ),
							hasTurnstile ? undefined : t( 'Add the Turnstile site and secret key in the module settings first; until then this has no effect.' )
						) }
					</div>
				</TabPanel>
			) : null }
		</>
	);
}
