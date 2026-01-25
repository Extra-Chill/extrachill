/**
 * Webpack configuration for extrachill-analytics
 *
 * Extends @wordpress/scripts defaults for React admin app.
 */
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const path = require( 'path' );

module.exports = {
	...defaultConfig,
	entry: {
		analytics: './src/index.js',
	},
	output: {
		...defaultConfig.output,
		path: path.resolve( __dirname, 'build' ),
	},
};
