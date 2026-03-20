/**
 * Custom webpack extends @wordpress/scripts defaults.
 *
 * Disables strict ESM resolution for .mjs files so that
 * @extrachill/tokens (and similar packages) can import
 * internal modules without explicit extensions.
 */
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

module.exports = {
	...defaultConfig,
	module: {
		...defaultConfig.module,
		rules: [
			...( defaultConfig.module?.rules || [] ),
			{
				test: /\.m?js$/,
				resolve: {
					fullySpecified: false,
				},
			},
		],
	},
};
