const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const path = require( 'path' );

module.exports = {
	...defaultConfig,
	entry: {
		index: path.resolve( __dirname, 'blocks/studio/src/index.ts' ),
		view: path.resolve( __dirname, 'blocks/studio/src/view.ts' ),
	},
	output: {
		...defaultConfig.output,
		path: path.resolve( __dirname, 'dist/studio' ),
	},
};
