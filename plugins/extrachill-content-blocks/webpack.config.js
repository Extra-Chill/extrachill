/**
 * Custom webpack extends @wordpress/scripts defaults
 * CopyWebpackPlugin: Block index.php files + trivia assets/ directory
 */
const defaultConfig = require('@wordpress/scripts/config/webpack.config');
const CopyWebpackPlugin = require('copy-webpack-plugin');
const path = require('path');

module.exports = {
	...defaultConfig,
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
