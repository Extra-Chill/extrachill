/**
 * Link Page Analytics Block - Frontend Entry
 *
 * Mounts the React analytics app when the block is rendered on the frontend.
 */

import { createRoot } from '@wordpress/element';
import { AnalyticsProvider } from './context/AnalyticsContext';
import Analytics from './components/Analytics';

document.addEventListener( 'DOMContentLoaded', () => {
	const container = document.getElementById( 'ec-link-page-analytics-root' );

	if ( ! container ) {
		return;
	}

	const artistId = parseInt( container.dataset.artistId, 10 ) || 0;
	const linkPageId = parseInt( container.dataset.linkPageId, 10 ) || 0;

	const root = createRoot( container );
	root.render(
		<AnalyticsProvider initialArtistId={ artistId } initialLinkPageId={ linkPageId }>
			<Analytics />
		</AnalyticsProvider>
	);
} );
