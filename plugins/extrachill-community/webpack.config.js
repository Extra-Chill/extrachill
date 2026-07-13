/**
 * Custom webpack extends @wordpress/scripts defaults.
 *
 * - Disables strict ESM resolution for .mjs files so packages
 *   like `@extrachill/tokens` can import internal modules without
 *   explicit extensions.
 * - Adds the standalone composer term-picker entry (not a block — it mounts
 *   into the server-rendered bbPress topic form) alongside the default
 *   block.json-discovered entries.
 */
const path = require( 'path' );
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

// wp-scripts exposes `entry` as a function that discovers block entries.
const defaultEntry = defaultConfig.entry;

module.exports = {
	...defaultConfig,
	entry: ( ...args ) => {
		const blockEntries =
			typeof defaultEntry === 'function'
				? defaultEntry( ...args )
				: defaultEntry;

		return {
			...blockEntries,
			'term-picker': path.resolve(
				process.cwd(),
				'src/term-picker/index.tsx'
			),
		};
	},
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
