import type { ReactNode } from 'react';

/** Page title with an optional actions area on the right (buttons, switch). */
export function PageHead( { title, children }: { title: string; children?: ReactNode } ): JSX.Element {
	return (
		<header className="uxs-pagehead">
			<h1>{ title }</h1>
			{ children ? <div className="uxs-pagehead__actions">{ children }</div> : null }
		</header>
	);
}
