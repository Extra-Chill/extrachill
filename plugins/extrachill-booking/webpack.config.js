/**
 * Booking imports shared venue-settings modules from extrachill-events' source
 * tree. Resolve their bare imports from booking's own node_modules so the build
 * never depends on events having installed its packages first.
 */
/**
 * External dependencies
 */
const path = require( 'path' );

/**
 * WordPress dependencies
 */
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

module.exports = {
	...defaultConfig,
	resolve: {
		...defaultConfig.resolve,
		modules: [ path.resolve( __dirname, 'node_modules' ), 'node_modules' ],
	},
};
