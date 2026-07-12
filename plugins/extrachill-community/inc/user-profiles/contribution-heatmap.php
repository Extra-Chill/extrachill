<?php
/**
 * Contribution Heatmap Profile Card
 *
 * Renders a GitHub-style contribution heatmap on the bbPress user profile — a
 * 53-week × 7-day grid of intensity-shaded squares, one per day, over the
 * trailing year. It is the profile's primary at-a-glance engagement surface
 * and a stickiness driver (people come back to watch their streak grow).
 *
 * This is a CONSUMER of the `extrachill/get-user-contribution-calendar`
 * ability (inc/social/rank-system/contribution-calendar-ability.php), which in
 * turn consumes the users-owned dated-contributions seam. This file does NO
 * cross-site aggregation of its own — it only projects the assembled calendar
 * onto a CSS grid.
 *
 * Placement: full-width card rendered via `bbp_template_after_user_profile`
 * at priority 1, below the About card that follows the profile hero.
 * Concert History (priority 5) and the Recent Activity feed (priority 99)
 * follow it on the same hook.
 *
 * Empty grid on a brand-new user is expected and rendered as-is — the "dead
 * chart" invites the owner to fill it in. If the contribution seam is not yet
 * deployed (extrachill-users #166 absent), the card degrades by not rendering
 * at all rather than showing a chart that can never populate.
 *
 * @package ExtraChillCommunity
 * @since 0.x
 */

defined( 'ABSPATH' ) || exit;

/**
 * Map a day's contribution count to a 0–4 shade level.
 *
 * GitHub-style: level-0 = no contributions, 1–4 are relative to the window's
 * busiest day. When the whole window is empty, everything stays level-0.
 *
 * @param int $count        Contributions on the day.
 * @param int $max_day_count The window's busiest day (0 when empty).
 * @return int Level 0..4.
 */
function ec_community_heatmap_level( $count, $max_day_count ) {
	$count = (int) $count;
	if ( $count < 1 ) {
		return 0;
	}
	$max = (int) $max_day_count;
	if ( $max < 1 ) {
		return 0;
	}
	$ratio = $count / $max;
	if ( $ratio <= 0.25 ) {
		return 1;
	}
	if ( $ratio <= 0.50 ) {
		return 2;
	}
	if ( $ratio <= 0.75 ) {
		return 3;
	}
	return 4;
}

/**
 * Display the Contribution Activity heatmap on the bbPress user profile.
 *
 * Pulls the assembled calendar from the contribution-calendar ability builder
 * and renders the grid + summary + legend. Renders on the profile overview
 * tab only (bbp_is_single_user_profile), where the card grid lives.
 */
function ec_community_display_contribution_heatmap() {
	// Only on the profile overview tab — the heatmap is a profile-body element,
	// not a header chrome element repeated across the Topics/Replies/Edit tabs.
	if ( function_exists( 'bbp_is_single_user_profile' ) && ! bbp_is_single_user_profile() ) {
		return;
	}

	$user_id = bbp_get_displayed_user_id();
	if ( ! $user_id ) {
		return;
	}

	// The dated-contributions seam is owned by extrachill-users. If it is not
	// loaded yet, skip the card entirely (graceful — no fatal, no dead chart).
	if ( ! function_exists( 'ec_get_contribution_events' ) ) {
		return;
	}

	if ( ! function_exists( 'extrachill_community_get_contribution_calendar' ) ) {
		return;
	}

	try {
		$now = current_datetime();
	} catch ( Exception $e ) {
		$now = new DateTimeImmutable( 'now', wp_timezone() );
	}
	$current_year = (int) $now->format( 'Y' );

	// Year navigation: ?contrib_year=YYYY selects a past calendar year.
	// Valid years span the user's registration year through last year; the
	// default (no param / invalid) is the trailing 12-month view.
	$join_year = 0;
	$user_data = get_userdata( (int) $user_id );
	if ( $user_data && ! empty( $user_data->user_registered ) ) {
		$join_year = (int) get_date_from_gmt( $user_data->user_registered, 'Y' );
	}

	$requested_year = isset( $_GET['contrib_year'] ) ? (int) $_GET['contrib_year'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view selector on a public profile.
	$selected_year  = ( $requested_year >= $join_year && $join_year > 0 && $requested_year < $current_year ) ? $requested_year : 0;

	$calendar = extrachill_community_get_contribution_calendar( (int) $user_id, 365, $selected_year );
	if ( ! is_array( $calendar ) ) {
		return;
	}

	$weeks        = isset( $calendar['weeks'] ) ? (int) $calendar['weeks'] : 53;
	$counts       = isset( $calendar['counts'] ) && is_array( $calendar['counts'] ) ? $calendar['counts'] : array();
	$max_day      = isset( $calendar['max_day_count'] ) ? (int) $calendar['max_day_count'] : 0;
	$total        = isset( $calendar['total_contributions'] ) ? (int) $calendar['total_contributions'] : 0;
	$current      = isset( $calendar['current_streak'] ) ? (int) $calendar['current_streak'] : 0;
	$longest      = isset( $calendar['longest_streak'] ) ? (int) $calendar['longest_streak'] : 0;
	$first_ymd    = isset( $calendar['first_sunday'] ) ? (string) $calendar['first_sunday'] : '';

	try {
		$first_sunday = new DateTimeImmutable( $first_ymd, wp_timezone() );
	} catch ( Exception $e ) {
		return;
	}

	// Cell emission stops at the window end: today for the trailing view,
	// Dec 31 for a selected past year.
	$window_end_ymd = isset( $calendar['window_end'] ) ? (string) $calendar['window_end'] : '';
	try {
		$today = '' !== $window_end_ymd ? new DateTimeImmutable( $window_end_ymd, wp_timezone() ) : current_datetime();
	} catch ( Exception $e ) {
		$today = new DateTimeImmutable( 'now', wp_timezone() );
	}

	$is_own    = ( (int) get_current_user_id() === (int) $user_id );
	$heading   = $is_own
		? __( 'My Contribution Activity', 'extra-chill-community' )
		: __( 'Contribution Activity', 'extra-chill-community' );

	// Weekday labels down the left edge (GitHub shows Mon / Wed / Fri).
	global $wp_locale;
	$weekday_labels = array( '', '', '', '', '', '', '' );
	if ( $wp_locale instanceof WP_Locale ) {
		$weekday_labels[1] = $wp_locale->get_weekday_abbrev( $wp_locale->get_weekday( 1 ) ); // Mon.
		$weekday_labels[3] = $wp_locale->get_weekday_abbrev( $wp_locale->get_weekday( 3 ) ); // Wed.
		$weekday_labels[5] = $wp_locale->get_weekday_abbrev( $wp_locale->get_weekday( 5 ) ); // Fri.
	}

	$date_format = get_option( 'date_format' );
	?>

	<div class="bbp-user-profile-card ec-contribution-heatmap-card">
		<h3><?php echo esc_html( $heading ); ?></h3>

		<div class="ec-heatmap-summary">
			<span class="ec-heatmap-total">
				<strong><?php echo esc_html( number_format_i18n( $total ) ); ?></strong>
				<?php
				if ( $selected_year > 0 ) {
					echo esc_html(
						sprintf(
							/* translators: 1: contribution count context, 2: year. */
							_n( 'contribution in %2$d', 'contributions in %2$d', $total, 'extra-chill-community' ),
							'',
							$selected_year
						)
					);
				} else {
					echo esc_html(
						sprintf(
							/* translators: %s: localized description of the window. */
							_n( 'contribution in the last year', 'contributions in the last year', $total, 'extra-chill-community' ),
							''
						)
					);
				}
				?>
			</span>
			<span class="ec-heatmap-streaks">
				<?php if ( 0 === $selected_year ) : ?>
					<?php
					printf(
						/* translators: %d: day count. */
						esc_html__( 'Current streak: %d days', 'extra-chill-community' ),
						(int) $current
					);
					?>
					<span class="ec-heatmap-streak-sep" aria-hidden="true">·</span>
				<?php endif; ?>
				<?php
				printf(
					/* translators: %d: day count. */
					esc_html__( 'Longest: %d days', 'extra-chill-community' ),
					(int) $longest
				);
				?>
			</span>
		</div>

		<?php
		// Year navigation: only when the user has history beyond the trailing
		// window (joined before the current year).
		if ( $join_year > 0 && $join_year < $current_year ) :
			$profile_url = bbp_get_user_profile_url( (int) $user_id );
			?>
			<nav class="ec-heatmap-years" aria-label="<?php esc_attr_e( 'Contribution activity by year', 'extra-chill-community' ); ?>">
				<a href="<?php echo esc_url( $profile_url ); ?>" class="ec-heatmap-year<?php echo 0 === $selected_year ? ' is-active' : ''; ?>"<?php echo 0 === $selected_year ? ' aria-current="true"' : ''; ?>><?php esc_html_e( 'Last year', 'extra-chill-community' ); ?></a>
				<?php for ( $y = $current_year - 1; $y >= $join_year; $y-- ) : ?>
					<a href="<?php echo esc_url( add_query_arg( 'contrib_year', $y, $profile_url ) ); ?>" class="ec-heatmap-year<?php echo $y === $selected_year ? ' is-active' : ''; ?>"<?php echo $y === $selected_year ? ' aria-current="true"' : ''; ?>><?php echo esc_html( (string) $y ); ?></a>
				<?php endfor; ?>
			</nav>
		<?php endif; ?>

		<div class="ec-heatmap-scroll">
			<?php
			$grid_aria_label = $selected_year > 0
				? sprintf( /* translators: 1: total contribution count, 2: year. */ __( '%1$d contributions in %2$d', 'extra-chill-community' ), $total, $selected_year )
				: sprintf( /* translators: %d: total contribution count. */ __( '%d contributions in the last year', 'extra-chill-community' ), $total );
			?>
			<div class="ec-heatmap" role="img" style="--ec-heat-weeks:<?php echo (int) $weeks; ?>;" aria-label="<?php echo esc_attr( $grid_aria_label ); ?>">
				<div class="ec-heatmap-corner" aria-hidden="true"></div>

				<div class="ec-heatmap-months" style="grid-template-columns:repeat(<?php echo (int) $weeks; ?>,var(--ec-heat-cell));">
					<?php
					$prev_month = '';
					for ( $col = 0; $col < $weeks; $col++ ) {
						$sunday = $first_sunday->modify( ( $col * 7 ) . ' days' );
						$month  = (int) $sunday->format( 'n' );
						$label  = '';
						if ( $month !== $prev_month && $wp_locale instanceof WP_Locale ) {
							$label = $wp_locale->get_month_abbrev( $wp_locale->get_month( $month ) );
						}
						$prev_month = $month;
						?>
						<span class="ec-heat-month<?php echo '' === $label ? ' is-empty' : ''; ?>"><?php echo esc_html( $label ); ?></span>
						<?php
					}
					?>
				</div>

				<div class="ec-heatmap-weekdays" aria-hidden="true">
					<?php foreach ( $weekday_labels as $label ) : ?>
						<span class="ec-heat-weekday<?php echo '' === $label ? ' is-empty' : ''; ?>"><?php echo esc_html( $label ); ?></span>
					<?php endforeach; ?>
				</div>

				<div class="ec-heatmap-cells">
					<?php
					for ( $col = 0; $col < $weeks; $col++ ) {
						for ( $dow = 0; $dow < 7; $dow++ ) {
							$date = $first_sunday->modify( ( $col * 7 + $dow ) . ' days' );

							// Days after today are in the future — stop emitting
							// (grid-auto-flow:column leaves the last column partial,
							// exactly like GitHub's trailing week).
							if ( $date > $today ) {
								break 2;
							}

							$ymd   = $date->format( 'Y-m-d' );
							$count = isset( $counts[ $ymd ] ) ? (int) $counts[ $ymd ] : 0;
							$level = ec_community_heatmap_level( $count, $max_day );
							$ts    = $date->getTimestamp();
							$label = $count > 0
								? sprintf(
									/* translators: 1: contribution count, 2: localized date. */
									_n( '%1$d contribution on %2$s', '%1$d contributions on %2$s', $count, 'extra-chill-community' ),
									$count,
									wp_date( $date_format, $ts )
								)
								: sprintf(
									/* translators: %s: localized date. */
									__( 'No contributions on %s', 'extra-chill-community' ),
									wp_date( $date_format, $ts )
								);
							?>
							<span
								class="ec-heat-cell level-<?php echo (int) $level; ?>"
								data-ec-tip="<?php echo esc_attr( $label ); ?>"
								tabindex="0"
							></span>
							<?php
						}
					}
					?>
				</div>
			</div>
		</div>

		<div class="ec-heatmap-legend">
			<span class="ec-heatmap-legend-label"><?php esc_html_e( 'Less', 'extra-chill-community' ); ?></span>
			<span class="ec-heat-cell level-0" aria-hidden="true"></span>
			<span class="ec-heat-cell level-1" aria-hidden="true"></span>
			<span class="ec-heat-cell level-2" aria-hidden="true"></span>
			<span class="ec-heat-cell level-3" aria-hidden="true"></span>
			<span class="ec-heat-cell level-4" aria-hidden="true"></span>
			<span class="ec-heatmap-legend-label"><?php esc_html_e( 'More', 'extra-chill-community' ); ?></span>
		</div>
	</div>
	<?php
}

// Render below the profile card grid (About / Community Activity / Artists),
// which sits directly under the header. Priority 1 leads the post-grid
// sections; Concert History (priority 5) follows, with the Recent Activity
// feed closing out the page (99).
add_action( 'bbp_template_after_user_profile', 'ec_community_display_contribution_heatmap', 1 );
