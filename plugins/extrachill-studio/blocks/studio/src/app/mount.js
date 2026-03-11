import { createRoot, render } from '@wordpress/element';

export const mountComponent = ( container, component ) => {
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
