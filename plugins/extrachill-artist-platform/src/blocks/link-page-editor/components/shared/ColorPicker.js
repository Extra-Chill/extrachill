/**
 * ColorPicker Component
 *
 * Native HTML color picker with hex value display.
 */

import { useCallback } from '@wordpress/element';

export default function ColorPicker( { color, onChange } ) {
	const handleChange = useCallback(
		( e ) => {
			onChange( e.target.value );
		},
		[ onChange ]
	);

	return (
		<div className="ec-color-picker">
			<input
				type="color"
				value={ color || '#000000' }
				onChange={ handleChange }
				className="ec-color-picker__input"
			/>
			<span className="ec-color-picker__value">{ color || '#000000' }</span>
		</div>
	);
}
