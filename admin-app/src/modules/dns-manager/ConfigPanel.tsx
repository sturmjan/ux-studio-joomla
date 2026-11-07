/**
 * Safety switches of the DNS module (who may edit, deleting critical records). Kept apart from the generic module settings
 * on purpose: the server accepts them only from a Super User who confirms the password.
 */
import { useEffect, useState } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import { api, queryClient } from '../../app/api';
import { createT } from '../../app/i18n';
import { Loading, pushToast } from '../../app/ui';

interface ConfigPayload {
	manage_user_ids: number[];
	allow_critical_delete: boolean;
	admins: Record< string, string >;
}

const { t } = createT( 'dns-manager' );

export function ConfigPanel( { isAdmin }: { isAdmin: boolean } ): JSX.Element {
	const config = useQuery( { queryKey: [ 'dns-manager', 'config' ], queryFn: () => api.get< ConfigPayload >( 'dns-manager/config' ), enabled: isAdmin } );
	const [ ids, setIds ] = useState< string[] >( [] );
	const [ critical, setCritical ] = useState( false );
	const [ password, setPassword ] = useState( '' );

	useEffect( () => {
		if ( config.data ) {
			setIds( config.data.manage_user_ids.map( String ) );
			setCritical( config.data.allow_critical_delete );
		}
	}, [ config.data ] );

	const save = useMutation( {
		mutationFn: () => api.post< ConfigPayload >( 'dns-manager/config', { manage_user_ids: ids.map( Number ), allow_critical_delete: critical, password } ),
		onSuccess: () => {
			setPassword( '' );
			pushToast( 'success', t( 'Settings saved.' ) );
			void queryClient.invalidateQueries( { queryKey: [ 'dns-manager' ] } );
		},
		onError: ( e: unknown ) => pushToast( 'error', e instanceof Error ? e.message : t( 'Something went wrong.' ) ),
	} );

	if ( ! isAdmin ) {
		return (
			<div className="uxs-notice" role="status">
				<p>{ t( 'Only a Super User can change who may edit DNS and whether critical records can be deleted.' ) }</p>
			</div>
		);
	}
	if ( config.isLoading ) {
		return <Loading />;
	}
	if ( config.error || ! config.data ) {
		return <p role="alert">{ config.error?.message ?? t( 'Something went wrong.' ) }</p>;
	}

	return (
		<form
			className="uxs-form"
			onSubmit={ ( e ) => {
				e.preventDefault();
				save.mutate();
			} }
		>
			<div className="uxs-form__row uxs-form__row--wide">
				<strong>{ t( 'Administrators who may edit DNS' ) }</strong>
				<p className="uxs-muted">{ t( 'Only the selected Super Users can add, change, delete and revert DNS records. Everybody else with access to UX Studio can only view. Nobody is selected by default.' ) }</p>
				<div className="uxs-checklist">
					{ Object.entries( config.data.admins ).map( ( [ id, label ] ) => (
						<label key={ id } className="uxs-checklist__item">
							<input type="checkbox" checked={ ids.includes( id ) } onChange={ ( e ) => setIds( e.target.checked ? [ ...ids, id ] : ids.filter( ( v ) => v !== id ) ) } />
							{ label }
						</label>
					) ) }
				</div>
			</div>
			<div className="uxs-form__row uxs-form__row--wide">
				<label className="uxs-checklist__item">
					<input type="checkbox" checked={ critical } onChange={ ( e ) => setCritical( e.target.checked ) } />
					<strong>{ t( 'Allow deleting critical records' ) }</strong>
				</label>
				<p className="uxs-muted">{ t( 'Mail (MX) records and the records of the website itself (the domain, www and the host name of this site) cannot be deleted, renamed or reverted away unless this is on. Changing them stays possible, but needs the record name retyped.' ) }</p>
			</div>
			<div className="uxs-form__row">
				<label htmlFor="uxs-dns-config-password">{ t( 'Confirm your password' ) }</label>
				<input id="uxs-dns-config-password" className="uxs-input" type="password" autoComplete="current-password" value={ password } onChange={ ( e ) => setPassword( e.target.value ) } />
			</div>
			<div className="uxs-actions">
				<button type="submit" className="uxs-btn uxs-btn--primary" disabled={ save.isPending || password === '' }>
					{ save.isPending ? t( 'Saving…' ) : t( 'Save' ) }
				</button>
			</div>
		</form>
	);
}
