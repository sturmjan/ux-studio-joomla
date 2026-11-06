import { useRef } from 'react';
import type { KeyboardEvent, ReactNode } from 'react';

export interface TabItem {
	id: string;
	label: string;
}

/** Tab strip (role=tablist, arrow keys). The caller renders the panel for the active id. */
export function Tabs( {
	items,
	active,
	onChange,
	label,
}: {
	items: TabItem[];
	active: string;
	onChange: ( id: string ) => void;
	label: string;
} ): JSX.Element {
	const refs = useRef< Array< HTMLButtonElement | null > >( [] );

	const onKey = ( e: KeyboardEvent, index: number ): void => {
		if ( e.key !== 'ArrowRight' && e.key !== 'ArrowLeft' ) {
			return;
		}
		e.preventDefault();
		const next = ( index + ( e.key === 'ArrowRight' ? 1 : items.length - 1 ) ) % items.length;
		const item = items[ next ];
		if ( item ) {
			onChange( item.id );
			refs.current[ next ]?.focus();
		}
	};

	return (
		<div className="uxs-tabs" role="tablist" aria-label={ label }>
			{ items.map( ( item, i ) => (
				<button
					key={ item.id }
					ref={ ( el ) => {
						refs.current[ i ] = el;
					} }
					type="button"
					role="tab"
					id={ `uxs-tab-${ item.id }` }
					aria-selected={ item.id === active }
					tabIndex={ item.id === active ? 0 : -1 }
					className={ item.id === active ? 'is-active' : '' }
					onClick={ () => onChange( item.id ) }
					onKeyDown={ ( e ) => onKey( e, i ) }
				>
					{ item.label }
				</button>
			) ) }
		</div>
	);
}

export function TabPanel( { id, children }: { id: string; children: ReactNode } ): JSX.Element {
	return (
		<div role="tabpanel" aria-labelledby={ `uxs-tab-${ id }` }>
			{ children }
		</div>
	);
}
