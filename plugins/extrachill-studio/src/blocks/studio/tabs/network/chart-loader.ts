/**
 * Lazy Chart.js loader.
 *
 * Chart.js is externalized onto the shared `extrachill-analytics-chart` handle
 * (see ../../../../../webpack.config.js), so `import('chart.js')` resolves to
 * the `window.ExtraChillChart` global rather than bundling a second copy. We
 * still dynamic-`import()` it here so webpack emits the chart wiring as a
 * code-split chunk that loads only when the Network tab actually mounts a chart
 * — keeping it out of Studio's main view chunk and off every non-Network page.
 *
 * The single in-flight promise is memoized so concurrent charts share one load.
 */
import type { Chart as ChartType, ChartConfiguration } from 'chart.js';

type ChartCtor = typeof ChartType;

let chartPromise: Promise< ChartCtor > | null = null;

/**
 * Resolve the Chart.js constructor, loading the externalized module on first
 * use. Resolves to the auto-registered Chart class from the shared handle.
 */
export const loadChart = (): Promise< ChartCtor > => {
	if ( ! chartPromise ) {
		chartPromise = import( 'chart.js' ).then( ( mod ) => {
			// The shared handle exposes the namespace with both `Chart` and
			// `default` pointing at the auto-registered constructor.
			const ctor =
				( mod as { Chart?: ChartCtor; default?: ChartCtor } ).Chart ??
				( mod as { default?: ChartCtor } ).default;
			if ( ! ctor ) {
				throw new Error(
					'Chart.js constructor unavailable on the shared analytics handle.'
				);
			}
			return ctor;
		} );
	}

	return chartPromise;
};

export type { ChartConfiguration };
