/**
 * ChartCanvas — mounts a Chart.js chart from a lazily-loaded constructor.
 *
 * Owns the canvas ref, async Chart.js load, instance creation, config updates,
 * and teardown. Consumers pass a fully-formed Chart.js `configuration`; this
 * component never knows about specific datasets — it just renders whatever
 * config it is handed and recreates the chart when that config changes.
 */
import { useEffect, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import type { ReactElement } from 'react';
import type { Chart as ChartType } from 'chart.js';

import { loadChart, type ChartConfiguration } from './chart-loader';

interface ChartCanvasProps {
	/** Fully-formed Chart.js configuration (type, data, options). */
	configuration: ChartConfiguration;
	/** Accessible label for the chart canvas. */
	ariaLabel?: string;
	/** Fixed pixel height for the chart area. Defaults to 280. */
	height?: number;
}

export const ChartCanvas = ( {
	configuration,
	ariaLabel,
	height = 280,
}: ChartCanvasProps ): ReactElement => {
	const canvasRef = useRef< HTMLCanvasElement | null >( null );
	const chartRef = useRef< ChartType | null >( null );
	const [ loadError, setLoadError ] = useState( '' );

	useEffect( () => {
		let cancelled = false;

		loadChart()
			.then( ( Chart ) => {
				if ( cancelled || ! canvasRef.current ) {
					return;
				}

				// Recreate from scratch on config change — simplest correct
				// lifecycle for a low-frequency dashboard (no animation churn).
				if ( chartRef.current ) {
					chartRef.current.destroy();
					chartRef.current = null;
				}

				chartRef.current = new Chart(
					canvasRef.current,
					configuration
				);
			} )
			.catch( ( error: unknown ) => {
				if ( ! cancelled ) {
					setLoadError(
						( error as Error )?.message ||
							__(
								'Could not load the charting library.',
								'extrachill-studio'
							)
					);
				}
			} );

		return () => {
			cancelled = true;
			if ( chartRef.current ) {
				chartRef.current.destroy();
				chartRef.current = null;
			}
		};
	}, [ configuration ] );

	if ( loadError ) {
		return (
			<p className="ec-studio-network__chart-error" role="alert">
				{ loadError }
			</p>
		);
	}

	return (
		<div
			className="ec-studio-network__chart-canvas"
			style={ { height: `${ height }px` } }
		>
			<canvas ref={ canvasRef } aria-label={ ariaLabel } role="img" />
		</div>
	);
};

export default ChartCanvas;
