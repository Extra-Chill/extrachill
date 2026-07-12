/**
 * Contribution Heatmap — frontend view.
 *
 * Hydrates from the server-inlined initial payload (trailing 12-month
 * window), then fetches past calendar years client-side through the
 * extrachill/get-user-contribution-calendar ability when a year tab is
 * clicked — no page reload. Fetched years are cached in component state
 * (past years are immutable).
 */
import { useState, useCallback, useMemo, createRoot } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { WPNativeClient } from 'wp-native-client';
import { WpApiFetchTransport } from 'wp-native-client/wordpress';
import { __, sprintf, _n } from '@wordpress/i18n';
import './style.css';

const client = new WPNativeClient( new WpApiFetchTransport( apiFetch ), {
	validateAbilityNames: false,
} );

interface CalendarPayload {
	user_id: number;
	days: number;
	weeks: number;
	year?: number;
	first_sunday: string;
	window_start: string;
	window_end: string;
	total_contributions: number;
	max_day_count: number;
	current_streak: number;
	longest_streak: number;
	counts: Record< string, number >;
}

interface HeatmapConfig {
	userId: number;
	joinYear: number;
	currentYear: number;
	isOwn: boolean;
	initial: CalendarPayload;
}

// ─── Date helpers (site-local calendar math on Y-m-d strings) ────────────────

function ymdToDate( ymd: string ): Date {
	const [ y, m, d ] = ymd.split( '-' ).map( Number );
	return new Date( y, m - 1, d );
}

function dateToYmd( date: Date ): string {
	const y = date.getFullYear();
	const m = String( date.getMonth() + 1 ).padStart( 2, '0' );
	const d = String( date.getDate() ).padStart( 2, '0' );
	return `${ y }-${ m }-${ d }`;
}

function addDays( date: Date, days: number ): Date {
	const copy = new Date( date );
	copy.setDate( copy.getDate() + days );
	return copy;
}

function shadeLevel( count: number, maxDay: number ): number {
	if ( count < 1 || maxDay < 1 ) {
		return 0;
	}
	const ratio = count / maxDay;
	if ( ratio <= 0.25 ) {
		return 1;
	}
	if ( ratio <= 0.5 ) {
		return 2;
	}
	if ( ratio <= 0.75 ) {
		return 3;
	}
	return 4;
}

const MONTH_ABBREVS = [
	'Jan',
	'Feb',
	'Mar',
	'Apr',
	'May',
	'Jun',
	'Jul',
	'Aug',
	'Sep',
	'Oct',
	'Nov',
	'Dec',
];
const WEEKDAY_LABELS = [ '', 'Mon', '', 'Wed', '', 'Fri', '' ];

function formatDayLabel( ymd: string, count: number ): string {
	const date = ymdToDate( ymd ).toLocaleDateString( undefined, {
		year: 'numeric',
		month: 'long',
		day: 'numeric',
	} );
	if ( count > 0 ) {
		return sprintf(
			/* translators: 1: contribution count, 2: localized date. */
			_n(
				'%1$d contribution on %2$s',
				'%1$d contributions on %2$s',
				count,
				'extra-chill-community'
			),
			count,
			date
		);
	}
	/* translators: %s: localized date. */
	return sprintf(
		__( 'No contributions on %s', 'extra-chill-community' ),
		date
	);
}

// ─── Components ──────────────────────────────────────────────────────────────

function HeatmapGrid( { calendar }: { calendar: CalendarPayload } ) {
	const firstSunday = ymdToDate( calendar.first_sunday );
	const windowEnd = ymdToDate( calendar.window_end );
	const weeks = calendar.weeks;

	// Month labels: one per column, shown when the month changes.
	const monthLabels = useMemo( () => {
		const labels: string[] = [];
		let prevMonth = -1;
		for ( let col = 0; col < weeks; col++ ) {
			const sunday = addDays( firstSunday, col * 7 );
			const month = sunday.getMonth();
			labels.push( month !== prevMonth ? MONTH_ABBREVS[ month ] : '' );
			prevMonth = month;
		}
		return labels;
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ calendar.first_sunday, weeks ] );

	const cells: JSX.Element[] = [];
	outer: for ( let col = 0; col < weeks; col++ ) {
		for ( let dow = 0; dow < 7; dow++ ) {
			const date = addDays( firstSunday, col * 7 + dow );
			if ( date > windowEnd ) {
				break outer;
			}
			const ymd = dateToYmd( date );
			const count = calendar.counts[ ymd ] ?? 0;
			const level = shadeLevel( count, calendar.max_day_count );
			cells.push(
				<span
					key={ ymd }
					className={ `ec-heat-cell level-${ level }` }
					data-ec-tip={ formatDayLabel( ymd, count ) }
					tabIndex={ 0 }
				/>
			);
		}
	}

	const gridAriaLabel = calendar.year
		? sprintf(
				/* translators: 1: total contribution count, 2: year. */
				__( '%1$d contributions in %2$d', 'extra-chill-community' ),
				calendar.total_contributions,
				calendar.year
		  )
		: sprintf(
				/* translators: %d: total contribution count. */
				__(
					'%d contributions in the last year',
					'extra-chill-community'
				),
				calendar.total_contributions
		  );

	return (
		<div className="ec-heatmap-scroll">
			<div
				className="ec-heatmap"
				role="img"
				style={ { '--ec-heat-weeks': weeks } as React.CSSProperties }
				aria-label={ gridAriaLabel }
			>
				<div className="ec-heatmap-corner" aria-hidden="true" />
				<div
					className="ec-heatmap-months"
					style={ {
						gridTemplateColumns: `repeat(${ weeks }, var(--ec-heat-cell))`,
					} }
				>
					{ monthLabels.map( ( label, i ) => (
						<span
							key={ i }
							className={ `ec-heat-month${
								label === '' ? ' is-empty' : ''
							}` }
						>
							{ label }
						</span>
					) ) }
				</div>
				<div className="ec-heatmap-weekdays" aria-hidden="true">
					{ WEEKDAY_LABELS.map( ( label, i ) => (
						<span
							key={ i }
							className={ `ec-heat-weekday${
								label === '' ? ' is-empty' : ''
							}` }
						>
							{ label }
						</span>
					) ) }
				</div>
				<div className="ec-heatmap-cells">{ cells }</div>
			</div>
		</div>
	);
}

function Legend() {
	return (
		<div className="ec-heatmap-legend">
			<span className="ec-heatmap-legend-label">
				{ __( 'Less', 'extra-chill-community' ) }
			</span>
			{ [ 0, 1, 2, 3, 4 ].map( ( level ) => (
				<span
					key={ level }
					className={ `ec-heat-cell level-${ level }` }
					aria-hidden="true"
				/>
			) ) }
			<span className="ec-heatmap-legend-label">
				{ __( 'More', 'extra-chill-community' ) }
			</span>
		</div>
	);
}

function Heatmap( { config }: { config: HeatmapConfig } ) {
	// selectedYear 0 = trailing window (the server-rendered initial payload).
	const [ selectedYear, setSelectedYear ] = useState( 0 );
	const [ cache, setCache ] = useState< Record< number, CalendarPayload > >( {
		0: config.initial,
	} );
	const [ loading, setLoading ] = useState( false );
	const [ error, setError ] = useState< string | null >( null );

	const selectYear = useCallback(
		( year: number ) => {
			setSelectedYear( year );
			setError( null );
			if ( cache[ year ] ) {
				return;
			}
			setLoading( true );
			client
				.execute< CalendarPayload >(
					'extrachill/get-user-contribution-calendar',
					{
						user_id: config.userId,
						year,
					}
				)
				.then( ( response ) => {
					setCache( ( prev ) => ( { ...prev, [ year ]: response } ) );
					setLoading( false );
				} )
				.catch( () => {
					setError(
						__(
							'Unable to load that year.',
							'extra-chill-community'
						)
					);
					setLoading( false );
				} );
		},
		[ cache, config.userId ]
	);

	const calendar = cache[ selectedYear ];
	const heading = config.isOwn
		? __( 'My Contribution Activity', 'extra-chill-community' )
		: __( 'Contribution Activity', 'extra-chill-community' );

	// Year tabs: trailing view + each calendar year back to the join year.
	const years: number[] = [];
	if ( config.joinYear > 0 && config.joinYear < config.currentYear ) {
		for ( let y = config.currentYear - 1; y >= config.joinYear; y-- ) {
			years.push( y );
		}
	}

	const summaryText =
		calendar &&
		( selectedYear > 0
			? sprintf(
					/* translators: %d: year. */
					_n(
						'contribution in %d',
						'contributions in %d',
						calendar.total_contributions,
						'extra-chill-community'
					),
					selectedYear
			  )
			: _n(
					'contribution in the last year',
					'contributions in the last year',
					calendar.total_contributions,
					'extra-chill-community'
			  ) );

	return (
		<>
			<h3>{ heading }</h3>

			{ calendar && (
				<div className="ec-heatmap-summary">
					<span className="ec-heatmap-total">
						<strong>
							{ calendar.total_contributions.toLocaleString() }
						</strong>{ ' ' }
						{ summaryText }
					</span>
					<span className="ec-heatmap-streaks">
						{ selectedYear === 0 && (
							<>
								{ sprintf(
									/* translators: %d: day count. */
									__(
										'Current streak: %d days',
										'extra-chill-community'
									),
									calendar.current_streak
								) }
								<span
									className="ec-heatmap-streak-sep"
									aria-hidden="true"
								>
									·
								</span>
							</>
						) }
						{ sprintf(
							/* translators: %d: day count. */
							__( 'Longest: %d days', 'extra-chill-community' ),
							calendar.longest_streak
						) }
					</span>
				</div>
			) }

			{ years.length > 0 && (
				<nav
					className="ec-heatmap-years"
					aria-label={ __(
						'Contribution activity by year',
						'extra-chill-community'
					) }
				>
					<button
						type="button"
						className={ `ec-heatmap-year${
							selectedYear === 0 ? ' is-active' : ''
						}` }
						aria-current={ selectedYear === 0 ? 'true' : undefined }
						onClick={ () => selectYear( 0 ) }
					>
						{ __( 'Last year', 'extra-chill-community' ) }
					</button>
					{ years.map( ( y ) => (
						<button
							key={ y }
							type="button"
							className={ `ec-heatmap-year${
								selectedYear === y ? ' is-active' : ''
							}` }
							aria-current={
								selectedYear === y ? 'true' : undefined
							}
							onClick={ () => selectYear( y ) }
						>
							{ y }
						</button>
					) ) }
				</nav>
			) }

			{ error && <p className="ec-heatmap-error">{ error }</p> }
			{ loading && ! calendar && (
				<p className="ec-heatmap-loading">
					{ __( 'Loading…', 'extra-chill-community' ) }
				</p>
			) }
			{ calendar && (
				<div className={ loading ? 'ec-heatmap-stale' : undefined }>
					<HeatmapGrid calendar={ calendar } />
					<Legend />
				</div>
			) }
		</>
	);
}

// ─── Mount ───────────────────────────────────────────────────────────────────

document
	.querySelectorAll< HTMLElement >(
		'.wp-block-extrachill-contribution-heatmap'
	)
	.forEach( ( container ) => {
		const dataEl = container.querySelector( 'script.ec-heatmap-data' );
		if ( ! dataEl?.textContent ) {
			return;
		}

		let config: HeatmapConfig;
		try {
			config = JSON.parse( dataEl.textContent );
		} catch {
			return;
		}

		if ( ! config?.userId || ! config?.initial ) {
			return;
		}

		const root = createRoot( container );
		root.render( <Heatmap config={ config } /> );
	} );
