import { CircleAlert, CircleCheck, LoaderCircle, X } from 'lucide-react';
import { t } from '../i18n';
import { dismissToast, useToasts } from '../notify';

/** Fixed status area (bottom right): saving spinner, success, error. */
export function Toasts(): JSX.Element | null {
	const toasts = useToasts();
	if ( 0 === toasts.length ) {
		return null;
	}
	return (
		<div className="uxs-toasts" role="status" aria-live="polite">
			{ toasts.map( ( toast ) => (
				<div key={ toast.id } className={ `uxs-toast is-${ toast.kind }` }>
					{ 'loading' === toast.kind && <LoaderCircle size={ 16 } className="uxs-toast__spin" aria-hidden="true" /> }
					{ 'success' === toast.kind && <CircleCheck size={ 16 } aria-hidden="true" /> }
					{ 'error' === toast.kind && <CircleAlert size={ 16 } aria-hidden="true" /> }
					<span className="uxs-toast__msg">{ toast.message }</span>
					{ 'loading' !== toast.kind && (
						<button type="button" className="uxs-toast__close" aria-label={ t( 'Close' ) } onClick={ () => dismissToast( toast.id ) }>
							<X size={ 14 } aria-hidden="true" />
						</button>
					) }
				</div>
			) ) }
		</div>
	);
}
