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
		// Shared Chart.js v4 asset — registered network-wide as the
		// `extrachill-analytics-chart` handle and externalized by consumers.
		// See extrachill-analytics#93 and src/chart.js.
		chart: './src/chart.js',
	},
	output: {
		...defaultConfig.output,
		path: path.resolve( __dirname, 'build' ),
	},
};
