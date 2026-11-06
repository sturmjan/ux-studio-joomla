/** On/off switch (role=switch semantics through aria-pressed, keyboard: Enter/Space as a native button). */
export function ToggleSwitch( {
	checked,
	onChange,
	label,
	disabled = false,
}: {
	checked: boolean;
	onChange: ( next: boolean ) => void;
	label: string;
	disabled?: boolean;
} ): JSX.Element {
	return (
		<button
			type="button"
			className={ `uxs-switch${ checked ? ' is-on' : '' }` }
			aria-pressed={ checked }
			aria-label={ label }
			title={ label }
			disabled={ disabled }
			onClick={ ( e ) => {
				e.stopPropagation();
				onChange( ! checked );
			} }
		/>
	);
}
