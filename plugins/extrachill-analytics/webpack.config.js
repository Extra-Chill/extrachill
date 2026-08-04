/**
 * Webpack configuration for extrachill-analytics
 *
 * Extends @wordpress/scripts defaults for React admin app.
 */
/**
 * WordPress dependencies
 */
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

/**
 * External dependencies
 */
const path = require( 'path' );

module.exports = {
	...defaultConfig,
	entry: {
		analytics: './src/index.js',
		// Shared Chart.js v4 asset — registered network-wide as the
		// `extrachill-analytics-chart` handle and externalized by consumers.
		// See extrachill-analytics#93 and src/chart.js.
		chart: './src/chart.js',
		// Shared framework-neutral Flatpickr range runtime. Consumers declare the
		// `extrachill-analytics-date-range` WordPress script/style dependency.
		'date-range': './src/date-range.js',
	},
	output: {
		...defaultConfig.output,
		path: path.resolve( __dirname, 'build' ),
	},
};
