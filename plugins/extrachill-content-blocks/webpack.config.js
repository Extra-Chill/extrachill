/**
 * Custom webpack extends @wordpress/scripts defaults
 * CopyWebpackPlugin: Block index.php files + trivia assets/ directory
 */
/**
 * WordPress dependencies
 */
const defaultConfig = require('@wordpress/scripts/config/webpack.config');

/**
 * External dependencies
 */
const CopyWebpackPlugin = require('copy-webpack-plugin');
const path = require('path');

module.exports = {
	...defaultConfig,
	resolve: {
		...defaultConfig.resolve,
		alias: {
			...( defaultConfig.resolve?.alias || {} ),
			// Compile the shared design-system components from monorepo source so
			// builds never depend on a pre-built packages/components/dist.
			'@extrachill/components$': path.resolve( __dirname, '../../packages/components/src/index.tsx' ),
		},
	},
	plugins: [
		...defaultConfig.plugins,
		new CopyWebpackPlugin({
			patterns: [
				{
					from: 'src/**/index.php',
					to({context, absoluteFilename}) {
						const relativePath = path.relative(path.join(context, 'src'), absoluteFilename);
						return relativePath;
					},
					noErrorOnMissing: true
				},
				{
					from: 'src/trivia/assets',
					to: 'trivia/assets',
					noErrorOnMissing: true
				}
			]
		})
	]
};
