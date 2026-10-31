/**
 * Artist Analytics Block - Frontend Entry
 *
 * Mounts the React analytics app when the block is rendered on the frontend.
 */

import apiFetch, { createRootURLMiddleware, createNonceMiddleware } from '@wordpress/api-fetch';
import { createRoot } from '@wordpress/element';
import { AnalyticsProvider } from './context/AnalyticsContext';
import Analytics from './components/Analytics';

// Configure apiFetch with REST root and nonce from localized config
const config = window.ecArtistAnalyticsConfig || {};
if ( config.restUrl ) {
	apiFetch.use( createRootURLMiddleware( config.restUrl ) );
}
if ( config.nonce ) {
	apiFetch.use( createNonceMiddleware( config.nonce ) );
}

window.addEventListener( 'DOMContentLoaded', () => {
	const container = document.getElementById( 'ec-artist-analytics-root' );

	if ( ! container ) {
		return;
	}

	const artistId = parseInt( container.dataset.artistId, 10 ) || 0;

	const root = createRoot( container );
	root.render(
		<AnalyticsProvider initialArtistId={ artistId }>
			<Analytics />
		</AnalyticsProvider>
	);
} );
