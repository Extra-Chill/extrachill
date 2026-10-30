/**
 * WordPress-specific entry point.
 *
 * Import the WP api-fetch transport from here to keep the main
 * bundle free of WordPress dependencies.
 *
 *   import { ExtraChillClient } from '@extrachill/api-client';
 *   import { WpApiFetchTransport } from '@extrachill/api-client/wordpress';
 *   import apiFetch from '@wordpress/api-fetch';
 *
 *   const client = new ExtraChillClient(new WpApiFetchTransport(apiFetch));
 */

export { WpApiFetchTransport } from './transports/wp-api-fetch';
