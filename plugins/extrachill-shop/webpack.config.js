/**
 * Webpack configuration for extrachill-shop.
 *
 * Extends the `@wordpress/scripts` defaults; block.json and render.php are copied
 * into build/ from src/ by the default config.
 */
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const path = require( 'path' );

module.exports = {
	...defaultConfig,
	entry: {
		'blocks/shop-manager/index': './src/blocks/shop-manager/index.js',
		'blocks/shop-manager/view': './src/blocks/shop-manager/view.js',
	},
	output: {
		...defaultConfig.output,
		path: path.resolve( __dirname, 'build' ),
	},
};
