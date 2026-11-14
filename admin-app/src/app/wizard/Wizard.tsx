/**
 * Setup wizard (`#/wizard`): site type, recommended modules, basic settings, UX1 Network app and the summary that turns
 * the chosen modules on. The module list comes from the registry (GET core/onboarding); modules are turned on one
 * request at a time through the ordinary core/modules/<id> route, so a problem in one never stops the others.
 */
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { api, queryClient, type ModuleInfo } from '../api';
import { t } from '../i18n';
import { navigate } from '../route';
import { Loading } from '../ui/Loading';
import { pause, Stepper } from './parts';
import { buildPlan, hasProfile, recommendedFor } from './plan';
import { getSteps } from './steps';
import type { OnboardingData, Profile, ProfileResponse, RunState, WizardCtx } from './types';
import './wizard.scss';

export const ONBOARDING_KEY = [ 'onboarding' ];

export function useOnboarding() {
	return useQuery( { queryKey: ONBOARDING_KEY, queryFn: () => api.get< OnboardingData >( 'core/onboarding' ), staleTime: 0 } );
}

function message( e: unknown ): string {
	return e instanceof Error ? e.message : String( e );
}

const IDLE: RunState = { status: 'idle', items: [], profileError: '', profileApplied: 0, completeError: '' };

function WizardInner( { data }: { data: OnboardingData } ): JSX.Element {
	const hasCa = data.ca !== null;
	const steps = useMemo( () => getSteps( hasCa ), [ hasCa ] );
	const [ stepId, setStepId ] = useState( 'welcome' );
	const [ siteType, setSiteTypeState ] = useState( data.state.site_type );
	const [ selected, setSelected ] = useState< string[] >( [] );
	const [ profile, setProfileState ] = useState< Profile >( {
		sender_email: data.state.profile.sender_email || data.defaults.sender_email,
		sender_name: data.state.profile.sender_name || data.defaults.sender_name,
		contact_email: data.state.profile.contact_email || data.defaults.contact_email,
	} );
	const [ connectCa, setConnectCa ] = useState( false );
	const [ run, setRun ] = useState< RunState >( IDLE );
	const started = useRef( false );
	const startCall = useRef< Promise< unknown > >( Promise.resolve() );
	const index = Math.max( 0, steps.findIndex( ( s ) => s.id === stepId ) );
	const running = run.status === 'running';

	// Tell the server the wizard was opened, so it is not opened by itself again.
	useEffect( () => {
		if ( ! started.current && data.state.status !== 'completed' ) {
			started.current = true;
			// Stop the hint on the Modules screen from opening the wizard again when the user leaves.
			queryClient.setQueryData< OnboardingData >( ONBOARDING_KEY, ( old ) => ( old ? { ...old, auto: false } : old ) );
			startCall.current = api.post( 'core/onboarding/state', { action: 'start' } ).catch( () => undefined );
		}
	}, [ data.state.status ] );

	const leave = useCallback( ( to: string, params?: Record< string, string > ): void => {
		// The wizard is never re-opened by itself after leaving; the server state is refetched in the background.
		queryClient.setQueryData< OnboardingData >( ONBOARDING_KEY, ( old ) => ( old ? { ...old, auto: false } : old ) );
		void startCall.current.then( () => queryClient.invalidateQueries( { queryKey: ONBOARDING_KEY } ) );
		void queryClient.invalidateQueries( { queryKey: [ 'modules' ] } );
		void queryClient.invalidateQueries( { queryKey: [ 'dashboard' ] } );
		navigate( to, params );
	}, [] );

	const start = useCallback( () => {
		const plan = buildPlan( data, selected, connectCa );
		setRun( { ...IDLE, status: 'running', items: plan.map( ( id ) => ( { id, state: 'pending', error: '' } ) ) } );
		void ( async () => {
			let profileApplied = 0;
			let profileError = '';
			if ( hasProfile( profile ) ) {
				try {
					profileApplied = ( await api.post< ProfileResponse >( 'core/onboarding/profile', profile ) ).applied.length;
				} catch ( e ) {
					profileError = message( e );
				}
			}
			const turnedOn: string[] = [];
			for ( const id of plan ) {
				setRun( ( cur ) => ( { ...cur, items: cur.items.map( ( i ) => ( i.id === id ? { ...i, state: 'running' } : i ) ) } ) );
				try {
					await api.post< ModuleInfo >( `core/modules/${ id }`, { enabled: true } );
					turnedOn.push( id );
					setRun( ( cur ) => ( { ...cur, items: cur.items.map( ( i ) => ( i.id === id ? { ...i, state: 'ok' } : i ) ) } ) );
				} catch ( e ) {
					setRun( ( cur ) => ( { ...cur, items: cur.items.map( ( i ) => ( i.id === id ? { ...i, state: 'error', error: message( e ) } : i ) ) } ) );
				}
				await pause( 250 );
			}
			let completeError = '';
			try {
				await api.post( 'core/onboarding/complete', { site_type: siteType, modules: turnedOn } );
			} catch ( e ) {
				completeError = message( e );
			}
			void queryClient.invalidateQueries( { queryKey: [ 'modules' ] } );
			void queryClient.invalidateQueries( { queryKey: [ 'dashboard' ] } );
			setRun( ( cur ) => ( { ...cur, status: 'done', profileApplied, profileError, completeError } ) );
		} )();
	}, [ connectCa, data, profile, selected, siteType ] );

	const skip = (): void => {
		void api
			.post( 'core/onboarding/state', { action: 'dismiss' } )
			.catch( () => undefined )
			.finally( () => {
				queryClient.setQueryData< OnboardingData >( ONBOARDING_KEY, ( old ) => ( old ? { ...old, pending: false, auto: false } : old ) );
				leave( 'modules' );
			} );
	};

	const ctx: WizardCtx = {
		data,
		hasCa,
		siteType,
		setSiteType: ( id ) => {
			setSiteTypeState( id );
			setSelected( recommendedFor( data, id ) );
		},
		selected,
		toggle: ( id ) => setSelected( ( cur ) => ( cur.includes( id ) ? cur.filter( ( x ) => x !== id ) : [ ...cur, id ] ) ),
		setSelected,
		profile,
		setProfile: ( patch ) => setProfileState( ( cur ) => ( { ...cur, ...patch } ) ),
		profileError: run.profileError,
		connectCa,
		setConnectCa,
		run,
		start,
		next: () => {
			setStepId( steps[ Math.min( index + 1, steps.length - 1 ) ]?.id ?? 'welcome' );
			window.scrollTo( { top: 0, behavior: 'auto' } );
		},
		back: () => setStepId( steps[ Math.max( index - 1, 0 ) ]?.id ?? 'welcome' ),
		finish: leave,
	};

	return (
		<div className="uxs-wiz">
			<header className="uxs-pagehead uxs-wiz-head">
				<h1>{ t( 'Setup wizard' ) }</h1>
				{ run.status !== 'done' && (
					<button type="button" className="uxs-btn uxs-btn--small" disabled={ running } onClick={ skip }>
						{ t( 'Skip the wizard' ) }
					</button>
				) }
			</header>
			<Stepper steps={ steps } current={ index } label={ t( 'Setup steps' ) } />
			<div className="uxs-wiz-stage" key={ `${ steps[ index ]?.id ?? 'welcome' }-${ run.status }` }>
				{ steps[ index ]?.render( ctx ) }
			</div>
		</div>
	);
}

export function Wizard(): JSX.Element {
	const { data, error, isLoading } = useOnboarding();
	if ( isLoading ) {
		return <Loading />;
	}
	if ( error || ! data ) {
		return <p role="alert">{ error instanceof Error ? error.message : t( 'The wizard could not be loaded.' ) }</p>;
	}
	return <WizardInner data={ data } />;
}
