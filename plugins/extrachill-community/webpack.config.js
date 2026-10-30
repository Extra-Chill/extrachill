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

/**
 * External dependencies
 */
const path = require( 'path' );

/**
 * WordPress dependencies
 */
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

// wp-scripts exposes `entry` as a function that discovers block entries.
const [ scriptConfig, moduleConfig ] = defaultConfig;
const DependencyExtractionWebpackPlugin = require( '@wordpress/dependency-extraction-webpack-plugin' );
const { defaultRequestToExternal } = require( '@wordpress/dependency-extraction-webpack-plugin/lib/util' );
const defaultEntry = scriptConfig.entry;

const classicConfig = {
	...scriptConfig,
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
		...scriptConfig.module,
		rules: [
			...( scriptConfig.module?.rules || [] ),
			{
				test: /\.m?js$/,
				resolve: {
					fullySpecified: false,
				},
			},
		],
	},
};

// Only abilities are core script modules. React/UI remain classic core scripts,
// explicitly enqueued by the authenticated blocks' render callbacks.
module.exports = [ classicConfig, {
	...moduleConfig,
	module: classicConfig.module,
	externals: [ ( { request }, callback ) => {
		if ( [ '@wordpress/abilities', '@wordpress/core-abilities' ].includes( request ) ) {
			return callback();
		}
		const external = defaultRequestToExternal( request );
		return external ? callback( null, external, 'window' ) : callback();
	} ],
	plugins: moduleConfig.plugins.map( ( plugin ) =>
		plugin instanceof DependencyExtractionWebpackPlugin
			? new DependencyExtractionWebpackPlugin( {
				useDefaults: false,
				requestToExternalModule( request ) {
					if ( [ '@wordpress/abilities', '@wordpress/core-abilities' ].includes( request ) ) {
						return `module ${ request }`;
					}
					return false;
				},
			} )
			: plugin
	),
} ];
