/**
 * WordPress dependencies
 */
import { createRoot, render } from '@wordpress/element';
/**
 * External dependencies
 */
import type { ReactElement } from 'react';

export const mountComponent = (
	container: HTMLElement | null,
	component: ReactElement
): void => {
	if ( ! container ) {
		return;
	}

	if ( typeof createRoot === 'function' ) {
		const root = createRoot( container );
		root.render( component );
		return;
	}

	if ( typeof render === 'function' ) {
		render( component, container );
	}
};
