import type { ReactNode } from 'react';
import { Dialog, Heading, Modal as AriaModal, ModalOverlay } from 'react-aria-components';

/**
 * Accessible modal (focus trap, Esc, focus return) built on react-aria-components. It is rendered inside #uxs-root
 * (see PortalProvider in App.tsx) so the --uxs-* tokens and the dark theme apply.
 */
export function Modal( {
	open,
	onClose,
	title,
	children,
	actions,
	wide = false,
}: {
	open: boolean;
	onClose: () => void;
	title: string;
	children: ReactNode;
	actions?: ReactNode;
	wide?: boolean;
} ): JSX.Element {
	return (
		<ModalOverlay
			isOpen={ open }
			onOpenChange={ ( next ) => {
				if ( ! next ) {
					onClose();
				}
			} }
			isDismissable
			className="uxs-overlay"
		>
			<AriaModal>
				<Dialog className={ `uxs-modal${ wide ? ' is-wide' : '' }` }>
					<Heading slot="title" className="uxs-modal__title">
						{ title }
					</Heading>
					{ children }
					{ actions ? <div className="uxs-modal__actions">{ actions }</div> : null }
				</Dialog>
			</AriaModal>
		</ModalOverlay>
	);
}
