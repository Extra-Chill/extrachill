/**
 * Webpack configuration for extrachill-studio.
 *
 * Extends the @wordpress/scripts default config to externalize Analytics-owned
 * browser runtimes onto their shared, network-registered script handles.
 *
 * Why externalize instead of bundling:
 *   extrachill-analytics is network-activated, so exactly ONE copy of Chart.js
 *   v4 is guaranteed present on every site as `window.ExtraChillChart`. Mapping
 *   our `chart.js` import to that global (the same way `react` maps to
 *   `window.React` and `@wordpress/element` to `window.wp.element`) keeps a
 *   second Chart.js copy out of the Studio bundle entirely. The default
 *   DependencyExtractionWebpackPlugin also records the resolved handle into the
 *   generated `*.asset.php` dependency array, so the block's view script
 *   declares `extrachill-analytics-chart` as a dependency and WordPress loads
 *   the shared asset before our view code runs — no manual enqueue wiring.
 *
 * The import is consumed lazily (dynamic `import()`) from the Network tab only,
 * so the chart code path is a code-split chunk that loads when a team member
 * opens the tab rather than on every Studio page load.
 */
/**
 * WordPress dependencies
 */
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const DependencyExtractionWebpackPlugin = require( '@wordpress/dependency-extraction-webpack-plugin' );

/**
 * The shared Chart.js script handle registered network-wide by
 * extrachill-analytics. Consumers map their `chart.js` import to the
 * `ExtraChillChart` global exposed by that handle.
 */
const CHART_HANDLE = 'extrachill-analytics-chart';
const CHART_GLOBAL = 'ExtraChillChart';
const DATE_RANGE_REQUEST = 'extrachill-analytics-date-range';
const DATE_RANGE_GLOBAL = 'ExtraChillAnalyticsDateRange';

/**
 * Resolve a webpack request to its external global. Returns undefined for
 * anything we don't externalize so the default WordPress mapping still applies.
 *
 * @param {string} request The module request being resolved.
 * @return {string|undefined} The global variable name, or undefined.
 */
const requestToExternal = ( request ) => {
	if ( request === 'chart.js' || request === 'chart.js/auto' ) {
		return CHART_GLOBAL;
	}
	if ( request === DATE_RANGE_REQUEST ) {
		return DATE_RANGE_GLOBAL;
	}

	return undefined;
};

/**
 * Resolve a webpack request to the WordPress script handle that provides it.
 * Returning the handle here makes it land in the generated `*.asset.php`
 * dependency array so WordPress enqueues the shared asset for us.
 *
 * @param {string} request The module request being resolved.
 * @return {string|undefined} The script handle, or undefined.
 */
const requestToHandle = ( request ) => {
	if ( request === 'chart.js' || request === 'chart.js/auto' ) {
		return CHART_HANDLE;
	}
	if ( request === DATE_RANGE_REQUEST ) {
		return DATE_RANGE_REQUEST;
	}

	return undefined;
};

// Replace the default DependencyExtractionWebpackPlugin instance with one that
// also knows how to map `chart.js` -> the shared analytics handle. The default
// plugin's built-in WordPress/React mappings are preserved by delegating to
// `defaultRequestToExternal` / `defaultRequestToHandle` for everything else.
const plugins = defaultConfig.plugins.map( ( plugin ) => {
	if ( plugin.constructor.name !== 'DependencyExtractionWebpackPlugin' ) {
		return plugin;
	}

	return new DependencyExtractionWebpackPlugin( {
		injectPolyfill: false,
		requestToExternal,
		requestToHandle,
	} );
} );

module.exports = {
	...defaultConfig,
	plugins,
};
