/**
 * Link Page Editor - Frontend Entry Point
 *
 * Mounts the React editor app on the frontend.
 */

import { createRoot } from '@wordpress/element';
import { EditorProvider } from './context/EditorContext';
import Editor from './components/Editor';

document.addEventListener( 'DOMContentLoaded', () => {
	const container = document.getElementById( 'ec-link-page-editor-root' );

	if ( ! container ) {
		return;
	}

	const artistId = parseInt( container.dataset.artistId, 10 );

	if ( ! artistId ) {
		container.innerHTML =
			'<div class="notice notice-error"><p>Invalid artist ID.</p></div>';
		return;
	}

	const root = createRoot( container );

	root.render(
		<EditorProvider artistId={ artistId }>
			<Editor />
		</EditorProvider>
	);
} );
