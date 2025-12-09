/**
 * Analytics Component
 *
 * Main analytics dashboard with Chart.js visualization.
 */

import { useEffect, useRef, useCallback } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { useAnalyticsContext } from '../context/AnalyticsContext';
import useAnalytics from '../hooks/useAnalytics';
import ArtistSwitcher from './ArtistSwitcher';

export default function Analytics() {
	const { artistId, userArtists, switchArtist } = useAnalyticsContext();
	const { analytics, dateRange, setDateRange, isLoading, error } = useAnalytics( artistId );
	const chartRef = useRef( null );
	const chartInstance = useRef( null );

	useEffect( () => {
		if ( ! analytics?.chart_data || ! chartRef.current ) {
			return;
		}

		const initChart = async () => {
			const Chart = ( await import( 'chart.js/auto' ) ).default;

			if ( chartInstance.current ) {
				chartInstance.current.destroy();
			}

			chartInstance.current = new Chart( chartRef.current, {
				type: 'line',
				data: {
					labels: analytics.chart_data?.labels || [],
					datasets: [
						{
							label: __( 'Page Views', 'extrachill-artist-platform' ),
							data: analytics.chart_data?.datasets?.[ 0 ]?.data || [],
							borderColor: '#e94560',
							backgroundColor: 'rgba(233, 69, 96, 0.1)',
							tension: 0.4,
							fill: true,
						},
						{
							label: __( 'Link Clicks', 'extrachill-artist-platform' ),
							data: analytics.chart_data?.datasets?.[ 1 ]?.data || [],
							borderColor: '#4ecdc4',
							backgroundColor: 'rgba(78, 205, 196, 0.1)',
							tension: 0.4,
							fill: true,
						},
					],
				},
				options: {
					responsive: true,
					maintainAspectRatio: false,
					plugins: {
						legend: {
							position: 'top',
						},
					},
					scales: {
						y: {
							beginAtZero: true,
						},
					},
				},
			} );
		};

		initChart();

		return () => {
			if ( chartInstance.current ) {
				chartInstance.current.destroy();
			}
		};
	}, [ analytics ] );

	const handleDateRangeChange = useCallback( ( e ) => {
		setDateRange( parseInt( e.target.value, 10 ) );
	}, [ setDateRange ] );

	if ( isLoading ) {
		return (
			<div className="ec-analytics ec-analytics--loading">
				<span className="spinner is-active"></span>
				<p>{ __( 'Loading analytics...', 'extrachill-artist-platform' ) }</p>
			</div>
		);
	}

	if ( error ) {
		return (
			<div className="ec-analytics">
				<div className="notice notice-error">
					<p>{ error }</p>
				</div>
			</div>
		);
	}

	return (
		<div className="ec-analytics">
			<div className="ec-analytics__header">
				<div className="ec-analytics__header-left">
					<h2>{ __( 'Link Page Analytics', 'extrachill-artist-platform' ) }</h2>
					<ArtistSwitcher
						artists={ userArtists }
						selectedId={ artistId }
						onChange={ switchArtist }
					/>
				</div>
				<select
					value={ dateRange }
					onChange={ handleDateRangeChange }
					className="ec-analytics__date-range"
				>
					<option value={ 7 }>{ __( 'Last 7 days', 'extrachill-artist-platform' ) }</option>
					<option value={ 30 }>{ __( 'Last 30 days', 'extrachill-artist-platform' ) }</option>
					<option value={ 90 }>{ __( 'Last 90 days', 'extrachill-artist-platform' ) }</option>
				</select>
			</div>

			<div className="ec-analytics__stats">
				<div className="ec-stat">
					<span className="ec-stat__value">{ analytics?.summary?.total_views || 0 }</span>
					<span className="ec-stat__label">{ __( 'Total Views', 'extrachill-artist-platform' ) }</span>
				</div>
				<div className="ec-stat">
					<span className="ec-stat__value">{ analytics?.summary?.total_clicks || 0 }</span>
					<span className="ec-stat__label">{ __( 'Total Clicks', 'extrachill-artist-platform' ) }</span>
				</div>
				<div className="ec-stat">
					<span className="ec-stat__value">
						{ analytics?.summary?.total_views
							? `${ ( ( analytics.summary.total_clicks / analytics.summary.total_views ) * 100 ).toFixed( 1 ) }%`
							: '0%' }
					</span>
					<span className="ec-stat__label">{ __( 'Click Rate', 'extrachill-artist-platform' ) }</span>
				</div>
			</div>

			<div className="ec-analytics__chart">
				<canvas ref={ chartRef } height="300"></canvas>
			</div>

			<div className="ec-analytics__top-links">
				<h3>{ __( 'Top Links', 'extrachill-artist-platform' ) }</h3>
				<table className="ec-analytics__table">
					<thead>
						<tr>
							<th>{ __( 'Link Text / URL', 'extrachill-artist-platform' ) }</th>
							<th>{ __( 'Clicks', 'extrachill-artist-platform' ) }</th>
						</tr>
					</thead>
					<tbody>
						{ analytics?.top_links?.length > 0 ? (
							analytics.top_links.map( ( link, index ) => (
								<tr key={ index }>
									<td>
										<span className="ec-top-link__title">{ link.text || link.identifier }</span>
										{ link.identifier && (
											<span className="ec-top-link__url">{ link.identifier }</span>
										) }
									</td>
									<td>{ link.clicks }</td>
								</tr>
							) )
						) : (
							<tr>
								<td colSpan="2">{ __( 'No link click data available.', 'extrachill-artist-platform' ) }</td>
							</tr>
						) }
					</tbody>
				</table>
			</div>

			<p className="ec-analytics__note">
				{ __( 'Analytics data is updated daily. Data older than 90 days is automatically pruned.', 'extrachill-artist-platform' ) }
			</p>
		</div>
	);
}
