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
	},
	output: {
		...defaultConfig.output,
		path: path.resolve( __dirname, 'build' ),
	},
};
