/**
 * SEO Admin Entry Point
 */
/**
 * WordPress dependencies
 */
import { createRoot } from '@wordpress/element';
/**
 * Internal dependencies
 */
import App from './App';

/**
 * External dependencies
 */
import '@extrachill/components/styles/components.scss';
import './styles/seo-admin.scss';

const container = document.getElementById( 'extrachill-seo-admin-app' );

if ( container ) {
	const root = createRoot( container );
	root.render( <App /> );
}
