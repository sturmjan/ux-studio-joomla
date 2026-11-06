import { useMutation, useQuery } from '@tanstack/react-query';
import { api, queryClient, type ModuleInfo } from './api';
import { t } from './i18n';
import { pushToast } from './notify';

export function useModules() {
	return useQuery( { queryKey: [ 'modules' ], queryFn: () => api.get< ModuleInfo[] >( 'core/modules' ) } );
}

/** Enable / disable a module with an optimistic switch; rolls back and shows the server message on failure. */
export function useToggleModule() {
	return useMutation( {
		mutationFn: ( { m, enabled }: { m: ModuleInfo; enabled: boolean } ) => api.post< ModuleInfo >( `core/modules/${ m.id }`, { enabled } ),
		onMutate: async ( { m, enabled } ) => {
			await queryClient.cancelQueries( { queryKey: [ 'modules' ] } );
			const previous = queryClient.getQueryData< ModuleInfo[] >( [ 'modules' ] );
			queryClient.setQueryData< ModuleInfo[] >( [ 'modules' ], ( old ) => old?.map( ( x ) => ( x.id === m.id ? { ...x, enabled } : x ) ) );
			return { previous };
		},
		onError: ( err, _vars, context ) => {
			if ( context?.previous ) {
				queryClient.setQueryData( [ 'modules' ], context.previous );
			}
			pushToast( 'error', err instanceof Error ? err.message : t( 'Something went wrong.' ) );
		},
		onSettled: () => {
			void queryClient.invalidateQueries( { queryKey: [ 'modules' ] } );
			void queryClient.invalidateQueries( { queryKey: [ 'dashboard' ] } );
		},
	} );
}
