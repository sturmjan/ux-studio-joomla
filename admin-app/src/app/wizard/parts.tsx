/** Small building blocks shared by the wizard steps. */
import type { ReactNode } from 'react';
import { CircleX, TriangleAlert } from 'lucide-react';
import { t } from '../i18n';

/** True when the user asked the system for less motion. */
export function reducedMotion(): boolean {
	return window.matchMedia?.( '(prefers-reduced-motion: reduce)' ).matches ?? false;
}

/** Pause that is skipped under reduced motion (gives the progress bars time to be seen). */
export function pause( ms: number ): Promise< void > {
	return reducedMotion() ? Promise.resolve() : new Promise( ( resolve ) => window.setTimeout( resolve, ms ) );
}

/** A check mark that draws itself (static under reduced motion). */
export function DrawnCheck( { size = 18 }: { size?: number } ): JSX.Element {
	return (
		<svg className="uxs-wiz-check" width={ size } height={ size } viewBox="0 0 24 24" fill="none" aria-hidden="true">
			<path d="M5 12.5l4.5 4.5L19 7.5" pathLength={ 1 } />
		</svg>
	);
}

/** Round state icon: drawn check, cross, spinner or an empty ring. */
export function StateIcon( { state }: { state: 'pending' | 'running' | 'ok' | 'error' | 'warn' } ): JSX.Element {
	return (
		<span className={ `uxs-wiz-state is-${ state }` } aria-hidden="true">
			{ state === 'ok' && <DrawnCheck size={ 16 } /> }
			{ state === 'error' && <CircleX size={ 18 } /> }
			{ state === 'warn' && <TriangleAlert size={ 16 } /> }
		</span>
	);
}

/** Step indicator: numbered circles joined by a progress line; finished steps show a drawn check. */
export function Stepper( { steps, current, label }: { steps: Array< { id: string; title: string } >; current: number; label: string } ): JSX.Element {
	return (
		<ol className="uxs-wiz-steps" aria-label={ label }>
			{ steps.map( ( step, index ) => {
				const state = index < current ? 'done' : index === current ? 'current' : 'todo';
				return (
					<li key={ step.id } className={ `uxs-wiz-step is-${ state }` } aria-current={ state === 'current' ? 'step' : undefined }>
						<span className="uxs-wiz-step__dot">{ state === 'done' ? <DrawnCheck size={ 16 } /> : <span>{ index + 1 }</span> }</span>
						<span className="uxs-wiz-step__title">
							<span className="uxs-wiz-sr">{ t( 'Step {n}: {name}', { n: index + 1, name: step.title } ) }</span>
							<span aria-hidden="true">{ step.title }</span>
						</span>
					</li>
				);
			} ) }
		</ol>
	);
}

/** Icon tile + heading + intro paragraph at the top of a step. */
export function StepHead( { icon, title, children }: { icon?: ReactNode; title: string; children?: ReactNode } ): JSX.Element {
	return (
		<div className="uxs-wiz-stephead">
			{ icon ? <span className="uxs-wiz-stephead__icon">{ icon }</span> : null }
			<div>
				<h2>{ title }</h2>
				{ children ? <p>{ children }</p> : null }
			</div>
		</div>
	);
}

export function ErrorLine( { text }: { text: string | null | undefined } ): JSX.Element | null {
	return text ? (
		<p className="uxs-wiz-error" role="alert">
			{ text }
		</p>
	) : null;
}
