/**
 * Analytics Dashboard - Entry Point
 *
 * Mounts the React analytics app in the Network Admin.
 */

import { createRoot } from '@wordpress/element';
import { AnalyticsProvider } from './context/AnalyticsContext';
import App from './App';
import '@extrachill/components/styles/components.scss';
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
