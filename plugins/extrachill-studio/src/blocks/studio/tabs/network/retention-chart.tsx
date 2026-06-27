/**
 * Retention chart — weekly-cohort retention curve + headline return rate.
 *
 * Reads GET /extrachill/v1/analytics/retention. The headline is the overall
 * return rate (share of visitors active on >= 2 distinct days). The chart plots
 * per-cohort week-1 / week-2 retention so a flattening or climbing curve is
 * visible over the cohort series.
 */
import { useEffect, useMemo, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import type { ReactElement } from 'react';
import { StatGroup, StatTile } from '@extrachill/components';

import { studioAnalyticsApi } from '../../app/client';
import type { RetentionResponse } from '../../types/analytics';
import { ChartCard, type ChartCardState } from './chart-card';
import { ChartCanvas } from './chart-canvas';
import type { ChartConfiguration } from './chart-loader';

const pct = ( rate: number ): string => `${ Math.round( rate * 1000 ) / 10 }%`;

export const RetentionChart = (): ReactElement => {
	const [ data, setData ] = useState< RetentionResponse | null >( null );
	const [ state, setState ] = useState< ChartCardState >( 'loading' );
	const [ errorMessage, setErrorMessage ] = useState( '' );

	useEffect( () => {
		let cancelled = false;

		studioAnalyticsApi
			.getRetention( { days: 28, cohort_weeks: 8 } )
			.then( ( response ) => {
				if ( cancelled ) {
					return;
				}
				setData( response );
				// A real return-rate read with zero visitors is "empty" (no
				// traffic accumulated yet), not an error.
				const hasVisitors =
					( response.return_rate?.total_visitors ?? 0 ) > 0;
				setState( hasVisitors ? 'ready' : 'empty' );
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
	}, [] );

	const configuration = useMemo< ChartConfiguration | null >( () => {
		const cohorts = data?.cohort_retention?.cohorts ?? [];
		if ( cohorts.length === 0 ) {
			return null;
		}

		return {
			type: 'line',
			data: {
				labels: cohorts.map( ( c ) => c.cohort_week ),
				datasets: [
					{
						label: __( 'Week 1 retention', 'extrachill-studio' ),
						data: cohorts.map(
							( c ) => Math.round( c.retention_w1 * 1000 ) / 10
						),
						borderColor: 'rgba(60, 132, 206, 1)',
						backgroundColor: 'rgba(60, 132, 206, 0.2)',
						tension: 0.3,
					},
					{
						label: __( 'Week 2 retention', 'extrachill-studio' ),
						data: cohorts.map(
							( c ) => Math.round( c.retention_w2 * 1000 ) / 10
						),
						borderColor: 'rgba(206, 96, 60, 1)',
						backgroundColor: 'rgba(206, 96, 60, 0.2)',
						tension: 0.3,
					},
				],
			},
			options: {
				responsive: true,
				maintainAspectRatio: false,
				scales: {
					y: {
						beginAtZero: true,
						title: {
							display: true,
							text: __(
								'% of cohort retained',
								'extrachill-studio'
							),
						},
					},
				},
			},
		};
	}, [ data ] );

	const headline = data ? (
		<StatGroup>
			<StatTile
				value={ pct( data.return_rate.rate ) }
				label={ __( 'Return rate', 'extrachill-studio' ) }
			/>
			<StatTile
				value={ data.return_rate.returning_visitors.toLocaleString() }
				label={ __( 'Returning visitors', 'extrachill-studio' ) }
				tone="muted"
			/>
			<StatTile
				value={ pct( data.cross_site_return.rate ) }
				label={ __( 'Cross-site return', 'extrachill-studio' ) }
				tone="muted"
			/>
		</StatGroup>
	) : null;

	const hasCohorts = ( data?.cohort_retention?.cohorts?.length ?? 0 ) > 0;

	// When the return rate is real but there isn't enough weekly-cohort history
	// to draw a curve, show a footnote instead of an empty chart area.
	const cohortFallback =
		! configuration && ! hasCohorts ? (
			<p className="ec-studio-network__footnote">
				{ __(
					'Not enough cohort history yet to chart a retention curve.',
					'extrachill-studio'
				) }
			</p>
		) : null;

	return (
		<ChartCard
			title={ __( 'Retention', 'extrachill-studio' ) }
			description={ __(
				'First-party visitor return rate and per-cohort retention, last 28 days.',
				'extrachill-studio'
			) }
			state={ state }
			errorMessage={ errorMessage }
			emptyMessage={ __(
				'No identified visitors in this window yet.',
				'extrachill-studio'
			) }
			headline={ headline }
		>
			{ configuration ? (
				<ChartCanvas
					configuration={ configuration }
					ariaLabel={ __(
						'Weekly cohort retention line chart',
						'extrachill-studio'
					) }
				/>
			) : (
				cohortFallback
			) }
		</ChartCard>
	);
};

export default RetentionChart;
