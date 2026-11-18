/**
 * Analytics Dashboard - Entry Point
 *
 * Mounts the React analytics app in the Network Admin.
 */

/**
 * WordPress dependencies
 */
import { createRoot } from '@wordpress/element';

/**
 * Internal dependencies
 */
import { AnalyticsProvider } from './context/AnalyticsContext';
import App from './App';
import './styles/analytics.scss';

document.addEventListener( 'DOMContentLoaded', () => {
	const container = document.getElementById( 'extrachill-analytics-app' );

	if ( ! container ) {
		return;
	}

	const root = createRoot( container );

	root.render(
		<AnalyticsProvider>
			<App />
		</AnalyticsProvider>
	);
} );
