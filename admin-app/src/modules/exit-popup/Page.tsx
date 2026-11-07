/**
 * Exit Popup screen: module settings (content, appearance, triggers, targeting, e-mail capture as sub-tabs)
 * and the list of captured subscribers with search, paging, CSV export and deletion.
 */
import { useState } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import { Download, ExternalLink, Search, Trash2 } from 'lucide-react';
import { api, ApiError, queryClient, type ModuleInfo } from '../../app/api';
import { createT, formatDate } from '../../app/i18n';
import { SettingsPanel } from '../../app/SettingsForm';
import { DataTable, Loading, TabPanel, Tabs, confirm, pushToast, type Column } from '../../app/ui';

const { t, tn } = createT( 'exit-popup' );

type Subscriber = { id: number; created: string; email: string; page_url: string; consent: boolean };

interface SubscriberPage {
	rows: Subscriber[];
	total: number;
	page: number;
	pages: number;
}

function errorMessage( e: unknown ): string {
	return e instanceof ApiError || e instanceof Error ? e.message : t( 'Something went wrong.' );
}

/** A cell that starts with = + - @ would be run as a formula by spreadsheets: neutralise it. */
function csvCell( value: string ): string {
	const safe = /^[=+\-@\t\r]/.test( value ) ? `'${ value }` : value;
	return `"${ safe.replace( /"/g, '""' ) }"`;
}

function downloadCsv( rows: Subscriber[] ): void {
	const lines = [ [ 'email', 'page_url', 'created_utc', 'consent' ].join( ',' ) ];
	for ( const r of rows ) {
		lines.push( [ csvCell( r.email ), csvCell( r.page_url ), csvCell( r.created ), r.consent ? '1' : '0' ].join( ',' ) );
	}
	const blob = new Blob( [ '﻿' + lines.join( '\r\n' ) ], { type: 'text/csv;charset=utf-8' } );
	const url = URL.createObjectURL( blob );
	const a = document.createElement( 'a' );
	a.href = url;
	a.download = 'exit-popup-subscribers.csv';
	document.body.appendChild( a );
	a.click();
	a.remove();
	URL.revokeObjectURL( url );
}

function SubscribersTab( { canAdmin }: { canAdmin: boolean } ): JSX.Element {
	const [ page, setPage ] = useState( 1 );
	const [ search, setSearch ] = useState( '' );
	const [ query, setQuery ] = useState( '' );
	const key = [ 'exit-popup', 'subscribers', page, query ];
	const list = useQuery( { queryKey: key, queryFn: () => api.get< SubscriberPage >( 'exit-popup/subscribers', { page, q: query } ) } );
	const refresh = (): Promise< void > => queryClient.invalidateQueries( { queryKey: [ 'exit-popup', 'subscribers' ] } );

	const remove = useMutation( {
		mutationFn: ( id: number ) => api.post< unknown >( `exit-popup/subscribers/${ id }/delete` ),
		onSuccess: refresh,
		onError: ( e ) => pushToast( 'error', errorMessage( e ) ),
	} );
	const purge = useMutation( {
		mutationFn: () => api.post< unknown >( 'exit-popup/subscribers/purge' ),
		onSuccess: () => {
			setPage( 1 );
			return refresh();
		},
		onError: ( e ) => pushToast( 'error', errorMessage( e ) ),
	} );
	const exportCsv = useMutation( {
		mutationFn: () => api.get< { rows: Subscriber[] } >( 'exit-popup/subscribers/export' ),
		onSuccess: ( data ) => downloadCsv( data.rows ),
		onError: ( e ) => pushToast( 'error', errorMessage( e ) ),
	} );

	const columns: Column< Subscriber >[] = [
		{ key: 'created', header: t( 'Date' ), render: ( r ) => formatDate( r.created ) },
		{ key: 'email', header: t( 'E-mail' ) },
		{ key: 'page_url', header: t( 'Page' ), render: ( r ) => <span style={ { overflowWrap: 'anywhere' } }>{ r.page_url }</span> },
		{ key: 'consent', header: t( 'Consent' ), render: ( r ) => ( r.consent ? t( 'Yes' ) : '' ) },
		{
			key: 'actions',
			header: '',
			render: ( r ) => ! canAdmin ? null : (
				<button
					type="button"
					className="uxs-btn uxs-btn--small uxs-btn--danger"
					aria-label={ t( 'Delete' ) }
					title={ t( 'Delete' ) }
					onClick={ () => {
						void confirm( { message: t( 'Delete {email}?', { email: r.email } ), confirmLabel: t( 'Delete' ), danger: true } ).then( ( ok ) => {
							if ( ok ) {
								remove.mutate( r.id );
							}
						} );
					} }
				>
					<Trash2 size={ 14 } aria-hidden="true" />
				</button>
			),
		},
	];

	return (
		<>
			<form
				className="uxs-actions"
				style={ { marginBottom: 'var(--uxs-sp-4)' } }
				role="search"
				onSubmit={ ( e ) => {
					e.preventDefault();
					setPage( 1 );
					setQuery( search.trim() );
				} }
			>
				<input type="search" value={ search } onChange={ ( e ) => setSearch( e.target.value ) } placeholder={ t( 'Search e-mail…' ) } aria-label={ t( 'Search e-mail…' ) } />
				<button type="submit" className="uxs-btn">
					<Search size={ 14 } aria-hidden="true" /> { t( 'Search' ) }
				</button>
				{ canAdmin ? (
					<button type="button" className="uxs-btn" disabled={ exportCsv.isPending || ! list.data?.total } onClick={ () => exportCsv.mutate() }>
						<Download size={ 14 } aria-hidden="true" /> { t( 'Export CSV' ) }
					</button>
				) : null }
				{ canAdmin ? (
					<button
						type="button"
						className="uxs-btn uxs-btn--danger"
						disabled={ ! list.data?.total }
						onClick={ () => {
							void confirm( { message: t( 'Delete all captured e-mail addresses? This cannot be undone.' ), confirmLabel: t( 'Delete all' ), danger: true } ).then( ( ok ) => {
								if ( ok ) {
									purge.mutate();
								}
							} );
						} }
					>
						{ t( 'Delete all' ) }
					</button>
				) : null }
				{ list.data ? <span className="uxs-muted">{ tn( '{n} address', '{n} addresses', list.data.total ) }</span> : null }
			</form>
			{ list.isLoading ? <Loading /> : null }
			{ list.error ? <p role="alert">{ errorMessage( list.error ) }</p> : null }
			{ list.data ? (
				<DataTable columns={ columns } rows={ list.data.rows } rowKey={ ( r ) => r.id } empty={ t( 'No e-mail addresses captured yet.' ) } page={ list.data.page } pages={ list.data.pages } onPage={ setPage } />
			) : null }
		</>
	);
}

export default function Page( { module }: { module: ModuleInfo } ): JSX.Element {
	const [ tab, setTab ] = useState( 'settings' );
	const meta = useQuery( { queryKey: [ 'core', 'meta' ], queryFn: () => api.get< { canAdmin: boolean } >( 'core/meta' ) } );

	const preview = async (): Promise< void > => {
		try {
			const { url } = await api.get< { url: string } >( 'exit-popup/preview-url' );
			window.open( url, '_blank', 'noopener' );
		} catch ( e ) {
			pushToast( 'error', errorMessage( e ) );
		}
	};

	return (
		<>
			<Tabs
				label={ module.name }
				active={ tab }
				onChange={ setTab }
				items={ [
					{ id: 'settings', label: t( 'Settings' ) },
					{ id: 'subscribers', label: t( 'Subscribers' ) },
				] }
			/>
			{ tab === 'settings' ? (
				<TabPanel id="settings">
					<div className="uxs-actions" style={ { marginBottom: 'var(--uxs-sp-4)' } }>
						<button type="button" className="uxs-btn" onClick={ () => void preview() }>
							<ExternalLink size={ 14 } aria-hidden="true" /> { t( 'Preview on the site' ) }
						</button>
						<span className="uxs-muted">{ t( 'Save the settings first, the preview shows the saved popup.' ) }</span>
					</div>
					<SettingsPanel moduleId={ module.id } />
				</TabPanel>
			) : (
				<TabPanel id="subscribers">
					<SubscribersTab canAdmin={ meta.data?.canAdmin ?? false } />
				</TabPanel>
			) }
		</>
	);
}
