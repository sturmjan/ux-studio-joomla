/**
 * Banner on the Modules screen: offers the setup wizard on a fresh installation (and opens it once by itself), and
 * leaves a small link to run it again afterwards. The state lives on the server (core settings).
 */
import { useEffect, useRef } from 'react';
import { useMutation } from '@tanstack/react-query';
import { Sparkles, Wand2, X } from 'lucide-react';
import { api, queryClient } from '../api';
import { t } from '../i18n';
import { navigate } from '../route';
import { ONBOARDING_KEY, useOnboarding } from './Wizard';
import type { OnboardingData } from './types';

export function WizardHint(): JSX.Element | null {
	const { data } = useOnboarding();
	const autoOpened = useRef( false );
	const dismiss = useMutation( {
		mutationFn: () => api.post( 'core/onboarding/state', { action: 'dismiss' } ),
		onSuccess: () => {
			queryClient.setQueryData< OnboardingData >( ONBOARDING_KEY, ( old ) => ( old ? { ...old, pending: false, auto: false } : old ) );
		},
	} );

	// First visit of a fresh installation: open the wizard once.
	useEffect( () => {
		if ( data?.auto && ! autoOpened.current ) {
			autoOpened.current = true;
			navigate( 'wizard' );
		}
	}, [ data?.auto ] );

	if ( ! data ) {
		return null;
	}
	if ( ! data.pending ) {
		return (
			<p className="uxs-wizhint-again">
				<button type="button" className="uxs-wiz-linkbtn is-inline" onClick={ () => navigate( 'wizard' ) }>
					<Wand2 size={ 14 } aria-hidden="true" /> { t( 'Run the setup wizard' ) }
				</button>
			</p>
		);
	}
	return (
		<aside className="uxs-wizhint" aria-label={ t( 'Setup wizard' ) }>
			<Sparkles size={ 20 } aria-hidden="true" />
			<p>{ t( 'Is this a new website? Choose its type and turn on the recommended modules in one short wizard.' ) }</p>
			<button type="button" className="uxs-btn uxs-btn--primary uxs-btn--small" onClick={ () => navigate( 'wizard' ) }>
				{ t( 'Start the wizard' ) }
			</button>
			<button type="button" className="uxs-wizhint__close" aria-label={ t( 'Dismiss' ) } disabled={ dismiss.isPending } onClick={ () => dismiss.mutate() }>
				<X size={ 16 } aria-hidden="true" />
			</button>
		</aside>
	);
}
