/**
 * SEO Admin Entry Point
 */
import { createRoot } from '@wordpress/element';
import App from './App';

import '@extrachill/components/styles/components.scss';
import './styles/seo-admin.scss';

const container = document.getElementById( 'extrachill-seo-audit-app' );

if ( container ) {
	const root = createRoot( container );
	root.render( <App /> );
}
