/**
 * Surface growth chart — ranked horizontal bar of supply growth per surface.
 *
 * Reads GET /extrachill/v1/analytics/surface-growth. The cross-surface axis is
 * supply pct-per-week (every live surface can produce it deterministically);
 * demand (GA-derived) degrades to a coverage gap when GA is unavailable, so we
 * rank on supply and surface the GA availability as a footnote rather than
 * fabricating demand bars.
 */
/**
 * WordPress dependencies
 */
import { useEffect, useMemo, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

/**
 * External dependencies
 */
import type { ReactElement } from 'react';

/**
 * Internal dependencies
 */
import { studioAnalyticsApi } from '../../app/client';
import type { SurfaceGrowthResponse } from '../../types/analytics';
import type { AnalyticsDateRange } from '../../types/date-range';
import { ChartCard, type ChartCardState } from './chart-card';
import { ChartCanvas } from './chart-canvas';
import type { ChartConfiguration } from './chart-loader';

const BAR_COLOR = 'rgba(60, 132, 206, 0.75)';
const BAR_BORDER = 'rgba(60, 132, 206, 1)';

interface SurfaceGrowthChartProps {
	dateRange: AnalyticsDateRange;
}

export const SurfaceGrowthChart = ( {
	dateRange,
}: SurfaceGrowthChartProps ): ReactElement => {
	const [ data, setData ] = useState< SurfaceGrowthResponse | null >( null );
	const [ state, setState ] = useState< ChartCardState >( 'loading' );
	const [ errorMessage, setErrorMessage ] = useState( '' );

	useEffect( () => {
		let cancelled = false;
		setState( 'loading' );
		setErrorMessage( '' );

		studioAnalyticsApi
			.getSurfaceGrowth( {
				start_date: dateRange.startDate,
				end_date: dateRange.endDate,
			} )
			.then( ( response ) => {
				if ( cancelled ) {
					return;
				}
				setData( response );
				const ranked = response.supply_ranking?.ranked ?? [];
				setState( ranked.length > 0 ? 'ready' : 'empty' );
			} )
			.catch( ( error: unknown ) => {
				if ( cancelled ) {
					return;
				}
				setErrorMessage( ( error as Error )?.message || '' );
				setState( 'error' );
			} );

		return () => {
			cancelled = true;
		};
	}, [ dateRange.endDate, dateRange.startDate ] );

	const configuration = useMemo< ChartConfiguration | null >( () => {
		const ranked = data?.supply_ranking?.ranked ?? [];
		if ( ranked.length === 0 ) {
			return null;
		}

		return {
			type: 'bar',
			data: {
				labels: ranked.map( ( row ) => row.label ),
				datasets: [
					{
						label: __(
							'Supply growth (% / week)',
							'extrachill-studio'
						),
						data: ranked.map( ( row ) => row.pct_per_week ),
						backgroundColor: BAR_COLOR,
						borderColor: BAR_BORDER,
						borderWidth: 1,
					},
				],
			},
			options: {
				indexAxis: 'y',
				responsive: true,
				maintainAspectRatio: false,
				plugins: { legend: { display: false } },
				scales: {
					x: {
						title: {
							display: true,
							text: __(
								'% new inventory per week',
								'extrachill-studio'
							),
						},
					},
				},
			},
		};
	}, [ data ] );

	const description = data
		? sprintf(
				/* translators: %d: number of weeks in the growth window. */
				__(
					'New published inventory per surface, last %d weeks. Ranked by supply growth.',
					'extrachill-studio'
				),
				data.weeks
		  )
		: __(
				'New published inventory per surface, ranked by supply growth.',
				'extrachill-studio'
		  );

	const fastest = data?.fastest_growing;
	const headline =
		fastest && fastest.surface
			? sprintf(
					/* translators: 1: surface label, 2: percent per week. */
					__(
						'Fastest growing: %1$s (+%2$s%% / wk)',
						'extrachill-studio'
					),
					fastest.label ?? fastest.surface,
					String( fastest.pct_per_week ?? 0 )
			  )
			: null;

	return (
		<ChartCard
			title={ __( 'Surface growth', 'extrachill-studio' ) }
			description={ description }
			state={ state }
			errorMessage={ errorMessage }
			emptyMessage={ __(
				'No surface produced a comparable growth figure yet.',
				'extrachill-studio'
			) }
			headline={ headline }
		>
			{ configuration ? (
				<>
					<ChartCanvas
						configuration={ configuration }
						ariaLabel={ __(
							'Surface growth ranked bar chart',
							'extrachill-studio'
						) }
					/>
					{ data && ! data.ga_available ? (
						<p className="ec-studio-network__footnote">
							{ __(
								'Demand (GA sessions) not instrumented on this install — ranking is supply-based.',
								'extrachill-studio'
							) }
						</p>
					) : null }
				</>
			) : null }
		</ChartCard>
	);
};

export default SurfaceGrowthChart;
