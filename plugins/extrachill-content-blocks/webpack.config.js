/**
 * Custom webpack configuration extends @wordpress/scripts defaults
 *
 * CopyWebpackPlugin handles files wp-scripts doesn't auto-copy:
 * - Block index.php files (custom REST endpoints, asset enqueuing)
 * - Trivia assets/ directory (manual enqueuing required due to non-standard structure)
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
