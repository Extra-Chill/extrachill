/**
 * TabAnalytics Component
 *
 * Analytics dashboard with Chart.js.
 */

import { useState, useEffect, useCallback, useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { useEditor } from '../../context/EditorContext';
import { getAnalytics } from '../../api/client';

export default function TabAnalytics() {
	const { artistId } = useEditor();
	const [ dateRange, setDateRange ] = useState( 30 );
	const [ analytics, setAnalytics ] = useState( null );
	const [ isLoading, setIsLoading ] = useState( true );
	const [ error, setError ] = useState( null );
	const chartRef = useRef( null );
	const chartInstance = useRef( null );

	const fetchAnalytics = useCallback( async () => {
		if ( ! artistId ) {
			return;
		}

		setIsLoading( true );
		setError( null );

		try {
			const data = await getAnalytics( artistId, dateRange );
			setAnalytics( data );
		} catch ( err ) {
			setError( err.message || 'Failed to load analytics' );
		} finally {
			setIsLoading( false );
		}
	}, [ artistId, dateRange ] );

	useEffect( () => {
		fetchAnalytics();
	}, [ fetchAnalytics ] );

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
					labels: analytics.chart_data.labels || [],
					datasets: [
						{
							label: __( 'Page Views', 'extrachill-artist-platform' ),
							data: analytics.chart_data.views || [],
							borderColor: '#e94560',
							backgroundColor: 'rgba(233, 69, 96, 0.1)',
							tension: 0.4,
							fill: true,
						},
						{
							label: __( 'Link Clicks', 'extrachill-artist-platform' ),
							data: analytics.chart_data.clicks || [],
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
	}, [] );

	if ( isLoading ) {
		return (
			<div className="ec-tab ec-tab--analytics ec-tab--loading">
				<span className="spinner is-active"></span>
				<p>{ __( 'Loading analytics...', 'extrachill-artist-platform' ) }</p>
			</div>
		);
	}

	if ( error ) {
		return (
			<div className="ec-tab ec-tab--analytics">
				<div className="notice notice-error">
					<p>{ error }</p>
				</div>
			</div>
		);
	}

	return (
		<div className="ec-tab ec-tab--analytics">
			<div className="ec-analytics__header">
				<h3>{ __( 'Analytics', 'extrachill-artist-platform' ) }</h3>
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
					<span className="ec-stat__value">{ analytics?.total_views || 0 }</span>
					<span className="ec-stat__label">{ __( 'Total Views', 'extrachill-artist-platform' ) }</span>
				</div>
				<div className="ec-stat">
					<span className="ec-stat__value">{ analytics?.total_clicks || 0 }</span>
					<span className="ec-stat__label">{ __( 'Total Clicks', 'extrachill-artist-platform' ) }</span>
				</div>
				<div className="ec-stat">
					<span className="ec-stat__value">{ analytics?.click_rate || '0%' }</span>
					<span className="ec-stat__label">{ __( 'Click Rate', 'extrachill-artist-platform' ) }</span>
				</div>
			</div>

			<div className="ec-analytics__chart">
				<canvas ref={ chartRef } height="300"></canvas>
			</div>

			<div className="ec-analytics__top-links">
				<h4>{ __( 'Top Links', 'extrachill-artist-platform' ) }</h4>
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
										<span className="ec-top-link__title">{ link.title }</span>
										{ link.url && (
											<span className="ec-top-link__url">{ link.url }</span>
										) }
									</td>
									<td>{ link.clicks }</td>
								</tr>
							) )
						) : (
							<tr>
								<td colSpan="2">{ __( 'No data available.', 'extrachill-artist-platform' ) }</td>
							</tr>
						) }
					</tbody>
				</table>
			</div>

			<p className="ec-analytics__note">
				{ __( 'Note: Analytics data is updated daily. Data older than 90 days is automatically pruned.', 'extrachill-artist-platform' ) }
			</p>
		</div>
	);
}
