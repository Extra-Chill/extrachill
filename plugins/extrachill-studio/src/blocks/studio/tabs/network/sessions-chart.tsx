/**
 * Sessions-over-time chart — GA4 daily sessions line.
 *
 * Reads POST /datamachine/v1/analytics/ga (action: date_stats). This is the ONE
 * Network-tab chart backed by Data Machine Business's generic GA4 route, which
 * is NOT team-accessible yet: it depends on a DM read-only analytics cap plus an
 * Extra Chill `user_has_cap` grant still in flight (see the permission-model
 * comment on extrachill-studio#104). Until that lands, the whole team can read
 * the three first-party (ECA) charts, but GA4 is admin-only.
 *
 * So this component GRACEFULLY DEGRADES: a 401/403 (or 404 if the route is
 * absent on this install) renders an "available to admins / coming soon"
 * placeholder rather than an error. Only an unexpected failure surfaces as an
 * error. This keeps the tab fully useful for the team today while the GA4
 * permission wiring catches up.
 */
/**
 * WordPress dependencies
 */
import { useEffect, useMemo, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * External dependencies
 */
import type { ReactElement } from 'react';

/**
 * Internal dependencies
 */
import { studioAnalyticsApi } from '../../app/client';
import type { GaDateStatsResponse } from '../../types/analytics';
import type { AnalyticsDateRange } from '../../types/date-range';
import { ChartCard, type ChartCardState } from './chart-card';
import { ChartCanvas } from './chart-canvas';
import type { ChartConfiguration } from './chart-loader';

interface SessionsChartProps {
	/** Empty means the full GA property; otherwise scope to one network host. */
	host?: string;
	dateRange: AnalyticsDateRange;
}

/** Extra state: GA4 not authorized for this user (the expected degrade path). */
type SessionsState = ChartCardState | 'unauthorized';

/**
 * HTTP statuses that mean "not allowed yet", i.e. degrade rather than error.
 * @param error
 */
const isUnauthorizedError = ( error: unknown ): boolean => {
	const status = ( error as { data?: { status?: number } } )?.data?.status;
	const code = ( error as { code?: string } )?.code;
	if ( status === 401 || status === 403 || status === 404 ) {
		return true;
	}
	// apiFetch surfaces REST auth failures with these codes; route-absent
	// installs surface rest_no_route. All are "not available to you yet".
	return (
		code === 'rest_forbidden' ||
		code === 'rest_cookie_invalid_nonce' ||
		code === 'rest_no_route'
	);
};

export const SessionsChart = ( {
	host = '',
	dateRange,
}: SessionsChartProps ): ReactElement => {
	const [ data, setData ] = useState< GaDateStatsResponse | null >( null );
	const [ state, setState ] = useState< SessionsState >( 'loading' );
	const [ errorMessage, setErrorMessage ] = useState( '' );
	const scopeDescription = host
		? __( 'GA4 daily sessions for the selected site.', 'extrachill-studio' )
		: __( 'GA4 daily sessions across the network.', 'extrachill-studio' );
	const rangeDescription = host
		? __(
				'GA4 daily sessions for the selected site, over the selected range.',
				'extrachill-studio'
		  )
		: __(
				'GA4 daily sessions across the network, over the selected range.',
				'extrachill-studio'
		  );

	useEffect( () => {
		let cancelled = false;
		setState( 'loading' );
		setErrorMessage( '' );

		studioAnalyticsApi
			.getGaDateStats( {
				hostname: host,
				start_date: dateRange.startDate,
				end_date: dateRange.endDate,
			} )
			.then( ( response ) => {
				if ( cancelled ) {
					return;
				}
				if ( ! response || response.success === false ) {
					// A structured non-success (e.g. GA unconfigured) is a
					// coverage gap, not a hard error — treat as not-instrumented.
					setState( 'notInstrumented' );
					return;
				}
				setData( response );
				const rows = response.results ?? [];
				setState( rows.length > 0 ? 'ready' : 'empty' );
			} )
			.catch( ( error: unknown ) => {
				if ( cancelled ) {
					return;
				}
				if ( isUnauthorizedError( error ) ) {
					setState( 'unauthorized' );
					return;
				}
				setErrorMessage( ( error as Error )?.message || '' );
				setState( 'error' );
			} );

		return () => {
			cancelled = true;
		};
	}, [ dateRange.endDate, dateRange.startDate, host ] );

	const configuration = useMemo< ChartConfiguration | null >( () => {
		const rows = [ ...( data?.results ?? [] ) ].sort( ( a, b ) =>
			String( a.date ?? '' ).localeCompare( String( b.date ?? '' ) )
		);
		if ( rows.length === 0 ) {
			return null;
		}

		return {
			type: 'line',
			data: {
				labels: rows.map( ( r ) => {
					const date = String( r.date ?? '' );
					return date.length === 8
						? `${ date.slice( 4, 6 ) }/${ date.slice( 6, 8 ) }`
						: date;
				} ),
				datasets: [
					{
						label: __( 'Sessions', 'extrachill-studio' ),
						data: rows.map( ( r ) => Number( r.sessions ?? 0 ) ),
						borderColor: 'rgba(60, 132, 206, 1)',
						backgroundColor: 'rgba(60, 132, 206, 0.2)',
						fill: true,
						tension: 0.3,
					},
				],
			},
			options: {
				responsive: true,
				maintainAspectRatio: false,
				plugins: { legend: { display: false } },
				scales: { y: { beginAtZero: true } },
			},
		};
	}, [ data ] );

	// The graceful-degrade placeholder: GA4 is admin-only until the read-only
	// cap + team grant land. Render an informational note, never an error.
	if ( state === 'unauthorized' ) {
		return (
			<ChartCard
				title={ __( 'Sessions over time', 'extrachill-studio' ) }
				description={ scopeDescription }
				state="notInstrumented"
				notInstrumentedReason={ __(
					'Sessions chart is available to admins for now — team access is coming soon once the read-only analytics permission ships.',
					'extrachill-studio'
				) }
			/>
		);
	}

	return (
		<ChartCard
			title={ __( 'Sessions over time', 'extrachill-studio' ) }
			description={ rangeDescription }
			state={ state }
			errorMessage={ errorMessage }
			emptyMessage={ __(
				'No GA4 sessions returned for this window.',
				'extrachill-studio'
			) }
		>
			{ configuration ? (
				<ChartCanvas
					configuration={ configuration }
					ariaLabel={ __(
						'Daily sessions line chart',
						'extrachill-studio'
					) }
				/>
			) : null }
		</ChartCard>
	);
};

export default SessionsChart;
