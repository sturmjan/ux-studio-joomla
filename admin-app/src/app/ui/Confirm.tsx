import { useSyncExternalStore } from 'react';
import { t } from '../i18n';
import { Modal } from './Modal';

/**
 * Promise based confirmation: `if ( await confirm( { message } ) ) { ... }`. <ConfirmHost /> (mounted once in App)
 * renders the dialog.
 */
interface ConfirmOptions {
	title?: string;
	message: string;
	confirmLabel?: string;
	danger?: boolean;
}

interface Pending extends ConfirmOptions {
	resolve: ( ok: boolean ) => void;
}

let pending: Pending | null = null;
const listeners = new Set< () => void >();

function emit(): void {
	listeners.forEach( ( l ) => l() );
}

export function confirm( options: ConfirmOptions ): Promise< boolean > {
	return new Promise( ( resolve ) => {
		pending = { ...options, resolve };
		emit();
	} );
}

function settle( ok: boolean ): void {
	const current = pending;
	pending = null;
	emit();
	current?.resolve( ok );
}

export function ConfirmHost(): JSX.Element {
	const current = useSyncExternalStore(
		( l ) => {
			listeners.add( l );
			return () => listeners.delete( l );
		},
		() => pending
	);
	return (
		<Modal
			open={ current !== null }
			onClose={ () => settle( false ) }
			title={ current?.title ?? t( 'Confirm' ) }
			actions={
				<>
					<button type="button" className="uxs-btn" onClick={ () => settle( false ) }>
						{ t( 'Cancel' ) }
					</button>
					<button type="button" className={ `uxs-btn ${ current?.danger ? 'uxs-btn--danger' : 'uxs-btn--primary' }` } onClick={ () => settle( true ) }>
						{ current?.confirmLabel ?? t( 'Confirm' ) }
					</button>
				</>
			}
		>
			<p className="uxs-modal__body">{ current?.message }</p>
		</Modal>
	);
}
