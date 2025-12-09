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
		'blocks/link-page-analytics/index':
			'./src/blocks/link-page-analytics/index.js',
		'blocks/link-page-analytics/view':
			'./src/blocks/link-page-analytics/view.js',
		'blocks/artist-profile-manager/index':
			'./src/blocks/artist-profile-manager/index.js',
		'blocks/artist-profile-manager/view':
			'./src/blocks/artist-profile-manager/view.js',
	},
	output: {
		...defaultConfig.output,
		path: path.resolve( __dirname, 'build' ),
	},
};
