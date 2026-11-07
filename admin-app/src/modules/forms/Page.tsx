/** Form Builder screen: list of forms, editor of one form, archive of submissions and the module settings. */
import { useState } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import { Copy, Pencil, Plus, Trash2 } from 'lucide-react';
import { api, queryClient, type ModuleInfo } from '../../app/api';
import { createT, formatDate } from '../../app/i18n';
import { SettingsPanel } from '../../app/SettingsForm';
import { DataTable, Loading, TabPanel, Tabs, confirm, pushToast, type Column } from '../../app/ui';
import FormEditor from './FormEditor';
import SubmissionsTab from './SubmissionsTab';
import type { FormSummary } from './types';

const { t, tn } = createT( 'forms' );

type ListData = { forms: FormSummary[]; turnstile: boolean };
type Row = FormSummary;

export default function Page( { module }: { module: ModuleInfo } ): JSX.Element {
	const [ tab, setTab ] = useState( 'forms' );
	const [ editing, setEditing ] = useState< number | null >( null );

	const list = useQuery( { queryKey: [ 'forms', 'list' ], queryFn: () => api.get< ListData >( 'forms/list' ), staleTime: 0 } );

	const refresh = (): void => {
		void queryClient.invalidateQueries( { queryKey: [ 'forms', 'list' ] } );
	};
	const remove = useMutation( {
		mutationFn: ( id: number ) => api.post( `forms/form/${ id }/delete` ),
		onSuccess: () => {
			refresh();
			pushToast( 'success', t( 'Form deleted.' ) );
		},
	} );
	const duplicate = useMutation( {
		mutationFn: ( id: number ) => api.post( `forms/form/${ id }/duplicate` ),
		onSuccess: () => {
			refresh();
			pushToast( 'success', t( 'Form duplicated as a draft.' ) );
		},
	} );

	if ( editing !== null ) {
		return (
			<FormEditor
				id={ editing }
				hasTurnstile={ list.data?.turnstile ?? false }
				onBack={ () => {
					setEditing( null );
					refresh();
				} }
			/>
		);
	}

	const ask = async ( form: Row ): Promise< void > => {
		const message =
			form.submissions > 0
				? tn( 'Delete the form "{title}" together with its {n} submission and uploaded files? This cannot be undone.', 'Delete the form "{title}" together with its {n} submissions and uploaded files? This cannot be undone.', form.submissions, { title: form.title } )
				: t( 'Delete the form "{title}"? This cannot be undone.', { title: form.title } );
		if ( await confirm( { message, confirmLabel: t( 'Delete' ), danger: true } ) ) {
			remove.mutate( form.id );
		}
	};

	const columns: Column< Row >[] = [
		{ key: 'title', header: t( 'Form' ), sortable: true, render: ( r ) => <strong>{ r.title }</strong> },
		{
			key: 'status',
			header: t( 'Status' ),
			render: ( r ) => <span className={ `uxs-badge ${ r.status === 'published' ? 'is-success' : '' }` }>{ r.status === 'published' ? t( 'Published' ) : t( 'Draft' ) }</span>,
		},
		{ key: 'field_count', header: t( 'Fields' ), sortable: true },
		{
			key: 'submissions',
			header: t( 'Submissions' ),
			sortable: true,
			render: ( r ) => (
				<>
					{ r.submissions }
					{ r.unread > 0 ? <span className="uxs-badge is-warning" style={ { marginLeft: 6 } }>{ t( '{n} new', { n: r.unread } ) }</span> : null }
				</>
			),
		},
		{ key: 'shortcode', header: t( 'Shortcode' ), render: ( r ) => <code>{ r.shortcode }</code> },
		{ key: 'modified', header: t( 'Changed' ), sortable: true, render: ( r ) => formatDate( r.modified ) },
		{
			key: 'actions',
			header: t( 'Actions' ),
			render: ( r ) => (
				<span className="uxs-actions">
					<button type="button" className="uxs-btn uxs-btn--small" onClick={ () => setEditing( r.id ) }>
						<Pencil size={ 14 } aria-hidden="true" /> { t( 'Edit' ) }
					</button>
					<button type="button" className="uxs-btn uxs-btn--small" aria-label={ t( 'Duplicate' ) } onClick={ () => duplicate.mutate( r.id ) }>
						<Copy size={ 14 } aria-hidden="true" />
					</button>
					<button type="button" className="uxs-btn uxs-btn--small uxs-btn--danger" aria-label={ t( 'Delete' ) } onClick={ () => void ask( r ) }>
						<Trash2 size={ 14 } aria-hidden="true" />
					</button>
				</span>
			),
		},
	];

	return (
		<>
			<Tabs
				label={ module.name }
				active={ tab }
				onChange={ setTab }
				items={ [
					{ id: 'forms', label: t( 'Forms' ) },
					{ id: 'submissions', label: t( 'Submissions' ) },
					{ id: 'settings', label: t( 'Settings' ) },
				] }
			/>
			{ tab === 'forms' ? (
				<TabPanel id="forms">
					<div className="uxs-actions" style={ { marginBottom: 'var(--uxs-sp-4)' } }>
						<button type="button" className="uxs-btn uxs-btn--primary" onClick={ () => setEditing( 0 ) }>
							<Plus size={ 14 } aria-hidden="true" /> { t( 'New form' ) }
						</button>
					</div>
					{ list.isLoading ? <Loading /> : null }
					{ list.error ? <p role="alert">{ list.error.message }</p> : null }
					{ list.data ? <DataTable columns={ columns } rows={ list.data.forms } rowKey={ ( r ) => r.id } empty={ t( 'No forms yet. Create the first one.' ) } pageSize={ 20 } /> : null }
				</TabPanel>
			) : null }
			{ tab === 'submissions' ? (
				<TabPanel id="submissions">
					<SubmissionsTab forms={ list.data?.forms ?? [] } />
				</TabPanel>
			) : null }
			{ tab === 'settings' ? (
				<TabPanel id="settings">
					<SettingsPanel moduleId={ module.id } />
				</TabPanel>
			) : null }
		</>
	);
}
