/**
 * Webpack configuration for extrachill-artist-platform
 *
 * Extends @wordpress/scripts defaults for block builds.
 */
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const path = require( 'path' );

module.exports = {
	...defaultConfig,
	entry: {
		'blocks/link-page-editor/index':
			'./src/blocks/link-page-editor/index.js',
		'blocks/link-page-editor/view':
			'./src/blocks/link-page-editor/view.js',
		'blocks/artist-analytics/index':
			'./src/blocks/artist-analytics/index.js',
		'blocks/artist-analytics/view':
			'./src/blocks/artist-analytics/view.js',
		'blocks/artist-manager/index':
			'./src/blocks/artist-manager/index.js',
		'blocks/artist-manager/view':
			'./src/blocks/artist-manager/view.js',
		'blocks/artist-creator/index':
			'./src/blocks/artist-creator/index.js',
		'blocks/artist-creator/view':
			'./src/blocks/artist-creator/view.js',
		'blocks/artist-shop-manager/index':
			'./src/blocks/artist-shop-manager/index.js',
		'blocks/artist-shop-manager/view':
			'./src/blocks/artist-shop-manager/view.js',
	},
	output: {
		...defaultConfig.output,
		path: path.resolve( __dirname, 'build' ),
	},
	externals: {
		...defaultConfig.externals,
		// Consume extrachill-analytics' (ECA) shared Chart.js v4 handle
		// ('extrachill-analytics-chart', exposing window.ExtraChillChart)
		// instead of bundling our own copy. See extrachill-artist-platform#89
		// and extrachill-analytics#93. ECA is network-activated so the global
		// is guaranteed present; the block declares the handle as a script
		// dependency so it loads first.
		'chart.js': 'ExtraChillChart',
		'chart.js/auto': 'ExtraChillChart',
	},
};
