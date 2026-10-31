const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const path = require( 'path' );

module.exports = {
	...defaultConfig,
	entry: {
		'blocks/ai-adventure/index':
			path.resolve( __dirname, 'src/blocks/ai-adventure/index.js' ),
		'blocks/ai-adventure/view':
			path.resolve( __dirname, 'src/blocks/ai-adventure/view.js' ),
		'blocks/ai-adventure-path/index':
			path.resolve( __dirname, 'src/blocks/ai-adventure-path/index.js' ),
		'blocks/ai-adventure-step/index':
			path.resolve( __dirname, 'src/blocks/ai-adventure-step/index.js' ),
	},
	output: {
		...defaultConfig.output,
		path: path.resolve( __dirname, 'build' ),
	},
};
