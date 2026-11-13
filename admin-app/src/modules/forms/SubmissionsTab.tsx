import { useState } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import { Download, Eye, Mail, MailOpen, Trash2 } from 'lucide-react';
import { api, queryClient } from '../../app/api';
import { createT, formatDate } from '../../app/i18n';
import { DataTable, Loading, Modal, confirm, pushToast, type Column } from '../../app/ui';
import type { FormField, FormSummary, StoredFile, Submission, SubmissionRow } from './types';

const { t } = createT( 'forms' );

type Page = { items: SubmissionRow[]; total: number; page: number; pages: number };
type Detail = { submission: Submission; fields: FormField[] };
type Row = SubmissionRow;

function saveBlob( name: string, mime: string, blob: Blob ): void {
	const url = URL.createObjectURL( blob );
	const a = document.createElement( 'a' );
	a.href = url;
	a.download = name;
	document.body.appendChild( a );
	a.click();
	a.remove();
	window.setTimeout( () => URL.revokeObjectURL( url ), 1000 );
	void mime;
}

function base64ToBlob( data: string, mime: string ): Blob {
	const bin = atob( data );
	const bytes = new Uint8Array( bin.length );
	for ( let i = 0; i < bin.length; i++ ) {
		bytes[ i ] = bin.charCodeAt( i );
	}
	return new Blob( [ bytes ], { type: mime } );
}

function summary( data: Record< string, string > ): string {
	const text = Object.values( data ).filter( Boolean ).join( ' · ' );
	return text.length > 110 ? `${ text.slice( 0, 110 ) }…` : text;
}

export default function SubmissionsTab( { forms }: { forms: FormSummary[] } ): JSX.Element {
	const [ formId, setFormId ] = useState( 0 );
	const [ q, setQ ] = useState( '' );
	const [ search, setSearch ] = useState( '' );
	const [ unread, setUnread ] = useState( false );
	const [ page, setPage ] = useState( 1 );
	const [ openId, setOpenId ] = useState< number | null >( null );

	const key = [ 'forms', 'submissions', formId, search, unread, page ];
	const list = useQuery( {
		queryKey: key,
		queryFn: () => api.get< Page >( 'forms/submissions', { form_id: formId, q: search, status: unread ? 'unread' : 'all', page } ),
		staleTime: 0,
	} );

	const refresh = (): void => {
		void queryClient.invalidateQueries( { queryKey: [ 'forms', 'submissions' ] } );
		void queryClient.invalidateQueries( { queryKey: [ 'forms', 'list' ] } );
	};

	const remove = useMutation( {
		mutationFn: ( ids: number[] ) => api.post( 'forms/submissions/delete', { ids } ),
		onSuccess: () => {
			setOpenId( null );
			refresh();
			pushToast( 'success', t( 'Submission deleted.' ) );
		},
	} );
	const mark = useMutation( {
		mutationFn: ( p: { ids: number[]; read: boolean } ) => api.post( 'forms/submissions/read', p ),
		onSuccess: refresh,
	} );

	const exportCsv = async (): Promise< void > => {
		try {
			const res = await api.get< { filename: string; csv: string } >( `forms/form/${ formId }/export`, { q: search } );
			saveBlob( res.filename, 'text/csv', new Blob( [ res.csv ], { type: 'text/csv;charset=utf-8' } ) );
		} catch ( e ) {
			pushToast( 'error', e instanceof Error ? e.message : t( 'Something went wrong.' ) );
		}
	};

	const ask = async ( id: number ): Promise< void > => {
		if ( await confirm( { message: t( 'Delete this submission and its files? This cannot be undone.' ), confirmLabel: t( 'Delete' ), danger: true } ) ) {
			remove.mutate( [ id ] );
		}
	};

	const columns: Column< Row >[] = [
		{ key: 'id', header: '#', render: ( r ) => ( r.is_read ? String( r.id ) : <strong>{ r.id }</strong> ) },
		{ key: 'created', header: t( 'Date' ), render: ( r ) => formatDate( r.created ) },
		{ key: 'form_title', header: t( 'Form' ) },
		{ key: 'data', header: t( 'Content' ), render: ( r ) => summary( r.data ) },
		{
			key: 'is_read',
			header: t( 'Status' ),
			render: ( r ) => (
				<>
					<span className={ `uxs-badge ${ r.is_read ? '' : 'is-warning' }` }>{ r.is_read ? t( 'Read' ) : t( 'New' ) }</span>
					{ r.mail_status === 'failed' ? <span className="uxs-badge is-danger" style={ { marginLeft: 6 } }>{ t( 'E-mail failed' ) }</span> : null }
				</>
			),
		},
		{
			key: 'actions',
			header: t( 'Actions' ),
			render: ( r ) => (
				<span className="uxs-actions">
					<button type="button" className="uxs-btn uxs-btn--small" onClick={ () => setOpenId( r.id ) }>
						<Eye size={ 14 } aria-hidden="true" /> { t( 'Open' ) }
					</button>
					<button type="button" className="uxs-btn uxs-btn--small uxs-btn--danger" aria-label={ t( 'Delete' ) } onClick={ () => void ask( r.id ) }>
						<Trash2 size={ 14 } aria-hidden="true" />
					</button>
				</span>
			),
		},
	];

	return (
		<>
			<form
				className="uxs-actions uxs-list-toolbar"
				role="search"
				onSubmit={ ( e ) => {
					e.preventDefault();
					setPage( 1 );
					setSearch( q.trim() );
				} }
			>
				<select value={ formId } onChange={ ( e ) => { setFormId( Number( e.target.value ) ); setPage( 1 ); } } aria-label={ t( 'Form' ) }>
					<option value={ 0 }>{ t( 'All forms' ) }</option>
					{ forms.map( ( f ) => (
						<option key={ f.id } value={ f.id }>
							{ f.title }
						</option>
					) ) }
				</select>
				<input type="search" value={ q } placeholder={ t( 'Search in answers…' ) } aria-label={ t( 'Search in answers…' ) } onChange={ ( e ) => setQ( e.target.value ) } />
				<button type="submit" className="uxs-btn">
					{ t( 'Search' ) }
				</button>
				<span className="uxs-checklist">
					<label className="uxs-checklist__item" style={ { display: 'inline-flex', alignItems: 'center', gap: 6, fontWeight: 400, color: 'var(--uxs-text)' } }>
						<input type="checkbox" checked={ unread } onChange={ ( e ) => { setUnread( e.target.checked ); setPage( 1 ); } } /> { t( 'Unread only' ) }
					</label>
				</span>
				<button type="button" className="uxs-btn" disabled={ formId === 0 } title={ formId === 0 ? t( 'Choose a form to export' ) : undefined } onClick={ () => void exportCsv() }>
					<Download size={ 14 } aria-hidden="true" /> { t( 'Export CSV' ) }
				</button>
			</form>

			{ list.isLoading ? <Loading /> : null }
			{ list.error ? <p role="alert">{ list.error.message }</p> : null }
			{ list.data ? (
				<>
					<p className="uxs-muted">{ t( '{n} submissions', { n: list.data.total } ) }</p>
					<DataTable columns={ columns } rows={ list.data.items } rowKey={ ( r ) => r.id } empty={ t( 'No submissions yet.' ) } page={ list.data.page } pages={ list.data.pages } onPage={ setPage } />
				</>
			) : null }

			{ openId !== null ? <DetailModal id={ openId } onClose={ () => { setOpenId( null ); refresh(); } } onDelete={ () => void ask( openId ) } onToggle={ ( read ) => mark.mutate( { ids: [ openId ], read } ) } /> : null }
		</>
	);
}

function DetailModal( { id, onClose, onDelete, onToggle }: { id: number; onClose: () => void; onDelete: () => void; onToggle: ( read: boolean ) => void } ): JSX.Element {
	const detail = useQuery( { queryKey: [ 'forms', 'submission', id ], queryFn: () => api.get< Detail >( `forms/submission/${ id }` ), staleTime: 0 } );
	const [ read, setRead ] = useState( true );

	const download = async ( key: string, n: number ): Promise< void > => {
		try {
			const f = await api.get< StoredFile & { data: string } >( `forms/submission/${ id }/file/${ key }/${ n }` );
			saveBlob( f.name, f.mime, base64ToBlob( f.data, f.mime ) );
		} catch ( e ) {
			pushToast( 'error', e instanceof Error ? e.message : t( 'Something went wrong.' ) );
		}
	};

	const s = detail.data?.submission;
	const fields = ( detail.data?.fields ?? [] ).filter( ( f ) => f.type !== 'html' );
	const known = new Set( fields.map( ( f ) => f.key ) );

	return (
		<Modal
			open
			wide
			onClose={ onClose }
			title={ s ? t( 'Submission #{id}', { id: s.id } ) : t( 'Submission' ) }
			actions={
				<>
					<button
						type="button"
						className="uxs-btn"
						onClick={ () => {
							onToggle( ! read );
							setRead( ! read );
						} }
					>
						{ read ? <Mail size={ 14 } aria-hidden="true" /> : <MailOpen size={ 14 } aria-hidden="true" /> } { read ? t( 'Mark as unread' ) : t( 'Mark as read' ) }
					</button>
					<button type="button" className="uxs-btn uxs-btn--danger" onClick={ onDelete }>
						<Trash2 size={ 14 } aria-hidden="true" /> { t( 'Delete' ) }
					</button>
					<button type="button" className="uxs-btn uxs-btn--primary" onClick={ onClose }>
						{ t( 'Close' ) }
					</button>
				</>
			}
		>
			{ detail.isLoading ? <Loading /> : null }
			{ detail.error ? <p role="alert">{ detail.error.message }</p> : null }
			{ s ? (
				<div style={ { maxHeight: '60vh', overflowY: 'auto' } }>
					<p className="uxs-muted">
						{ s.form_title } · { formatDate( s.created ) }
					</p>
					<div className="uxs-table-wrap">
						<table className="uxs-table">
							<tbody>
								{ fields.map( ( f ) => {
									const value = s.data[ f.key ];
									const files = s.files[ f.key ] ?? [];
									if ( f.type !== 'file' && value === undefined ) {
										return null;
									}
									return (
										<tr key={ f.key }>
											<th scope="row" style={ { width: '30%', textAlign: 'left' } }>{ f.label || f.key }</th>
											<td style={ { whiteSpace: 'pre-wrap' } }>
												{ f.type === 'file'
													? files.length === 0
														? '-'
														: files.map( ( file, n ) => (
																<div key={ file.path }>
																	<button type="button" className="uxs-btn uxs-btn--small" onClick={ () => void download( f.key, n ) }>
																		<Download size={ 14 } aria-hidden="true" /> { file.name }
																	</button>{ ' ' }
																	<span className="uxs-muted">{ Math.max( 1, Math.round( file.size / 1024 ) ) } kB</span>
																</div>
														  ) )
													: Array.isArray( value )
													? value.join( ', ' )
													: value }
											</td>
										</tr>
									);
								} ) }
								{ Object.entries( s.data )
									.filter( ( [ k ] ) => ! known.has( k ) )
									.map( ( [ k, v ] ) => (
										<tr key={ k }>
											<th scope="row" style={ { textAlign: 'left' } }>{ k }</th>
											<td style={ { whiteSpace: 'pre-wrap' } }>{ Array.isArray( v ) ? v.join( ', ' ) : v }</td>
										</tr>
									) ) }
							</tbody>
						</table>
					</div>
					<p className="uxs-muted" style={ { marginTop: 'var(--uxs-sp-3)' } }>
						{ s.page_url ? <>{ t( 'Page' ) }: { s.page_url } · </> : null }
						IP: { s.ip || '-' } · { t( 'E-mail' ) }: { s.mail_status === 'sent' ? t( 'sent' ) : s.mail_status === 'failed' ? t( 'failed' ) : '-' }
					</p>
				</div>
			) : null }
		</Modal>
	);
}
