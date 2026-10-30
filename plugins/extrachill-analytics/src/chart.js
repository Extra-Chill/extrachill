/**
 * Shared Chart.js asset — network-activated charting handle.
 *
 * extrachill-analytics is network-activated, so registering Chart.js here as a
 * WordPress script handle (`extrachill-analytics-chart`) makes ONE guaranteed-
 * present copy available to every consumer on the network instead of each
 * plugin re-bundling its own. See extrachill-analytics#93.
 *
 * This entry imports `chart.js/auto` (Chart.js v4 with all controllers,
 * elements, scales, and plugins auto-registered) and exposes the full module
 * namespace on `window.ExtraChillChart`. Downstream webpack consumers
 * (extrachill-artist-platform#89, extrachill-studio#104) map the `chart.js`
 * import to this global as a webpack external — the same way `react` /
 * `wp-element` are externalized to `window.React` / `window.wp.element`:
 *
 *   // webpack.config.js (consumer)
 *   externals: {
 *     'chart.js': 'ExtraChillChart',
 *     'chart.js/auto': 'ExtraChillChart',
 *   }
 *
 *   // consumer source — unchanged ergonomics
 *   import { Chart } from 'chart.js';      // -> window.ExtraChillChart.Chart
 *   import Chart from 'chart.js/auto';     // -> window.ExtraChillChart (default)
 *
 * Handle:   extrachill-analytics-chart
 * Global:   window.ExtraChillChart  (namespace object; `.default` and `.Chart`
 *           both resolve to the auto-registered Chart constructor)
 */

import Chart, * as ChartModule from 'chart.js/auto';

// Expose the full namespace. `default` is the auto-registered Chart
// constructor; named exports (registerables, scales, controllers, etc.) ride
// along so a consumer mapping either `chart.js` or `chart.js/auto` gets a
// faithful module shape.
const ExtraChillChart = {
	...ChartModule,
	default: Chart,
	Chart,
};

window.ExtraChillChart = ExtraChillChart;

export default ExtraChillChart;
