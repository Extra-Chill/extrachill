<?php
/**
 * Account-backed event market integration.
 *
 * Extra Chill Users owns the private preference. This integration consumes its
 * resolved coordinates and feeds them into Data Machine Events' existing geo
 * inputs without teaching the generic event layer about accounts.
 *
 * @package ExtraChillEvents
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Get the current user's resolved default event location.
 *
 * The Ability contract is provided by extrachill-users#179. Until that
 * dependency is available, or when it returns an invalid/empty preference,
 * this fails open.
 *
 * @return array{lat: float|null, lon: float|null, slug: string, term_id: int, label: string, url: string}|null
 */
function extrachill_events_get_account_market(): ?array {
	static $resolved = false;
	static $market   = null;

	if ( $resolved ) {
		return $market;
	}
	$resolved = true;

	if ( ! is_user_logged_in() || ! function_exists( 'wp_get_ability' ) ) {
		return null;
	}

	$ability = wp_get_ability( 'extrachill/get-user-settings' );
	if ( ! $ability ) {
		return null;
	}

	$result = $ability->execute( array() );
	if ( is_wp_error( $result ) || ! is_array( $result ) || ! is_array( $result['default_event_location'] ?? null ) ) {
		return null;
	}

	$location    = $result['default_event_location'];
	$coordinates = is_array( $location['coordinates'] ?? null ) ? $location['coordinates'] : array();
	$lat         = filter_var( $coordinates['lat'] ?? null, FILTER_VALIDATE_FLOAT, FILTER_NULL_ON_FAILURE );
	$lon         = filter_var( $coordinates['lon'] ?? null, FILTER_VALIDATE_FLOAT, FILTER_NULL_ON_FAILURE );
	$term_id     = absint( $location['term_id'] ?? 0 );
	$slug        = sanitize_title( $location['slug'] ?? '' );
	$hierarchy   = is_array( $location['hierarchy'] ?? null ) ? $location['hierarchy'] : array();
	$label       = sanitize_text_field( $hierarchy['label'] ?? $location['name'] ?? '' );
	$url         = esc_url_raw( $location['archive_url'] ?? '' );

	if ( $term_id < 1 || '' === $slug ) {
		return null;
	}
	if ( null !== $lat && ( $lat < -90 || $lat > 90 ) ) {
		$lat = null;
	}
	if ( null !== $lon && ( $lon < -180 || $lon > 180 ) ) {
		$lon = null;
	}

	$market = array(
		'lat'     => null !== $lat ? (float) $lat : null,
		'lon'     => null !== $lon ? (float) $lon : null,
		'slug'    => $slug,
		'term_id' => $term_id,
		'label'   => $label,
		'url'     => $url,
	);

	return $market;
}

/**
 * Whether this request explicitly opts out of account market fallback.
 */
function extrachill_events_is_exploring_all_markets(): bool {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public read-only discovery preference.
	$value = isset( $_GET['explore_all'] ) && is_scalar( $_GET['explore_all'] ) ? sanitize_text_field( wp_unslash( $_GET['explore_all'] ) ) : '';

	return '1' === $value;
}

/**
 * Whether the request already carries an explicit location selection.
 */
function extrachill_events_has_explicit_market(): bool {
	if ( extrachill_events_is_exploring_all_markets() ) {
		return true;
	}

	if ( is_tax() ) {
		return true;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public read-only calendar filters.
	if ( ! empty( $_GET['tax_filter'] ) ) {
		return true;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public read-only geo parameters.
	$lat = isset( $_GET['lat'] ) ? sanitize_text_field( wp_unslash( $_GET['lat'] ) ) : '';
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public read-only geo parameters.
	$lng = isset( $_GET['lng'] ) ? sanitize_text_field( wp_unslash( $_GET['lng'] ) ) : '';
	return '' !== $lat && '' !== $lng;
}

/**
 * Whether this page owns the unscoped public calendar/map experience.
 *
 * Embedded calendars such as My Shows and related events have their own query
 * contracts and must not inherit a market fallback.
 */
function extrachill_events_supports_account_market(): bool {
	if ( function_exists( 'ec_is_events_site' ) && ! ec_is_events_site() ) {
		return false;
	}

	return is_front_page()
		|| ( function_exists( 'extrachill_events_is_all_events_page' ) && extrachill_events_is_all_events_page() )
		|| ( function_exists( 'extrachill_events_is_near_me_page' ) && extrachill_events_is_near_me_page() );
}

/**
 * Add account market defaults before the calendar request is parsed.
 *
 * Explicit request/archive state wins. Near Me receives geo defaults so browser
 * geolocation can replace them; other public calendar surfaces receive the
 * canonical taxonomy term. CalendarRequest sanitizes every injected value.
 *
 * @param array $query_args Assembled calendar request arguments.
 * @param array $context    Calendar render context.
 * @return array
 */
function extrachill_events_calendar_account_market_defaults( array $query_args, array $context ): array {
	if ( ! extrachill_events_supports_account_market() || extrachill_events_is_exploring_all_markets() || ! empty( $context['archive_term'] ) || ! empty( $query_args['tax_filter'] ) ) {
		return $query_args;
	}

	if ( ! empty( $query_args['lat'] ) || ! empty( $query_args['lng'] ) ) {
		return $query_args;
	}

	$market = extrachill_events_get_account_market();
	if ( null === $market ) {
		return $query_args;
	}

	if ( function_exists( 'extrachill_events_is_near_me_page' ) && extrachill_events_is_near_me_page() ) {
		if ( null !== $market['lat'] && null !== $market['lon'] ) {
			$query_args += array(
				'lat' => $market['lat'],
				'lng' => $market['lon'],
			);
		}
	} else {
		$query_args += array(
			'tax_filter' => array(
				'location' => array( $market['term_id'] ),
			),
		);
	}

	return $query_args;
}
add_filter( 'data_machine_events_calendar_request_args', 'extrachill_events_calendar_account_market_defaults', 10, 2 );

/**
 * Use the account market as a map center only when no stronger context exists.
 *
 * @param mixed $center  Existing map center.
 * @param array $context Map render context.
 * @return mixed
 */
function extrachill_events_account_market_map_center( $center, array $context ) {
	if ( null !== $center || ! extrachill_events_supports_account_market() || ! empty( $context['is_taxonomy'] ) || extrachill_events_has_explicit_market() ) {
		return $center;
	}

	$market = extrachill_events_get_account_market();
	if ( null === $market || null === $market['lat'] || null === $market['lon'] ) {
		return $center;
	}

	return array(
		'lat' => $market['lat'],
		'lon' => $market['lon'],
	);
}
add_filter( 'data_machine_events_map_center', 'extrachill_events_account_market_map_center', 5, 2 );

/**
 * Render account market context on primary Events discovery surfaces.
 */
function extrachill_events_render_account_market_context(): void {
	if ( is_front_page() || ! extrachill_events_supports_account_market() || is_tax() || ( extrachill_events_has_explicit_market() && ! extrachill_events_is_exploring_all_markets() ) ) {
		return;
	}

	$community_url = function_exists( 'ec_get_site_url' ) ? ec_get_site_url( 'community' ) : 'https://community.extrachill.com';
	$settings_url  = trailingslashit( $community_url ) . 'settings/#tab-account-details';
	$current_url   = remove_query_arg( 'explore_all' );
	$explore_url   = add_query_arg( 'explore_all', '1', $current_url );
	$market        = extrachill_events_get_account_market();
	$is_logged_in  = is_user_logged_in();
	$is_exploring  = extrachill_events_is_exploring_all_markets();
	?>
	<aside class="events-market-context<?php echo $is_logged_in ? '' : ' events-market-context--quiet'; ?>" aria-label="<?php esc_attr_e( 'Event location preference', 'extrachill-events' ); ?>" role="status">
		<div class="events-market-context__copy">
			<?php if ( $market && $is_exploring ) : ?>
				<strong><?php esc_html_e( 'Exploring all locations', 'extrachill-events' ); ?></strong>
				<span><?php esc_html_e( 'Your saved market is still available whenever you want to focus the calendar again.', 'extrachill-events' ); ?></span>
			<?php elseif ( $market ) : ?>
				<strong>
					<?php
					printf(
						/* translators: %s: Market hierarchy label. */
						esc_html__( 'Showing events for %s', 'extrachill-events' ),
						esc_html( '' !== $market['label'] ? $market['label'] : $market['slug'] )
					);
					?>
				</strong>
			<?php elseif ( $is_logged_in ) : ?>
				<strong><?php esc_html_e( 'Focus Events around your city', 'extrachill-events' ); ?></strong>
				<span><?php esc_html_e( 'Set a default market once and use it across devices.', 'extrachill-events' ); ?></span>
			<?php else : ?>
				<span><?php esc_html_e( 'Sign in to save a default market across devices.', 'extrachill-events' ); ?></span>
			<?php endif; ?>
		</div>
		<div class="events-market-context__actions">
			<?php if ( $market && $is_exploring ) : ?>
				<a href="<?php echo esc_url( $current_url ); ?>"><?php esc_html_e( 'Use my default market', 'extrachill-events' ); ?></a>
				<a href="<?php echo esc_url( $settings_url ); ?>"><?php esc_html_e( 'Change default', 'extrachill-events' ); ?></a>
			<?php elseif ( $market ) : ?>
				<a href="<?php echo esc_url( $settings_url ); ?>"><?php esc_html_e( 'Change default', 'extrachill-events' ); ?></a>
				<a href="<?php echo esc_url( $explore_url ); ?>"><?php esc_html_e( 'Explore all locations', 'extrachill-events' ); ?></a>
			<?php elseif ( $is_logged_in ) : ?>
				<a class="button-1 button-small" href="<?php echo esc_url( $settings_url ); ?>"><?php esc_html_e( 'Set default market', 'extrachill-events' ); ?></a>
			<?php else : ?>
				<a href="<?php echo esc_url( wp_login_url( $current_url ) ); ?>"><?php esc_html_e( 'Sign in', 'extrachill-events' ); ?></a>
			<?php endif; ?>
		</div>
	</aside>
	<?php
}

/**
 * Render the progressive homepage market router.
 *
 * @param array $locations Canonical active city rows ordered by upcoming count.
 */
function extrachill_events_render_home_market_router( array $locations ): void {
	if ( ! is_front_page() || ! extrachill_events_supports_account_market() ) {
		return;
	}

	$community_url = function_exists( 'ec_get_site_url' ) ? ec_get_site_url( 'community' ) : 'https://community.extrachill.com';
	$settings_url  = trailingslashit( $community_url ) . 'settings/#tab-account-details';
	$market        = extrachill_events_get_account_market();
	$is_logged_in  = is_user_logged_in();
	$market_count  = 0;
	foreach ( $locations as $location ) {
		if ( $market && (int) $location['term_id'] === (int) $market['term_id'] ) {
			$market_count = (int) $location['count'];
			break;
		}
	}
	$sample_min_events = (int) apply_filters( 'extrachill_events_badge_min_count', 20 );
	$sample            = array_values(
		array_filter(
			$locations,
			static function ( array $location ) use ( $market, $sample_min_events ): bool {
				$is_other_market = ! $market || (int) $location['term_id'] !== (int) $market['term_id'];
				return $is_other_market && (int) $location['count'] >= $sample_min_events;
			}
		)
	);
	$sample            = array_slice( $sample, 0, 8 );
	?>
	<section class="events-home-router ec-edge-gutter" aria-labelledby="events-market-heading">
		<div class="events-home-router__heading">
		<?php if ( $market ) : ?>
			<h2 id="events-market-heading"><?php esc_html_e( 'Your market', 'extrachill-events' ); ?></h2>
		<?php elseif ( $is_logged_in ) : ?>
			<h2 id="events-market-heading"><?php esc_html_e( 'Choose your market', 'extrachill-events' ); ?></h2>
			<p><?php esc_html_e( 'Find a city now, or set a default to put it first on every visit.', 'extrachill-events' ); ?> <a href="<?php echo esc_url( $settings_url ); ?>"><?php esc_html_e( 'Set default market', 'extrachill-events' ); ?></a></p>
		<?php else : ?>
			<h2 id="events-market-heading"><?php esc_html_e( 'Find your city', 'extrachill-events' ); ?></h2>
			<p><?php esc_html_e( 'Search without an account.', 'extrachill-events' ); ?> <a href="<?php echo esc_url( wp_login_url( home_url( '/' ) ) ); ?>"><?php esc_html_e( 'Sign in to save a default', 'extrachill-events' ); ?></a></p>
		<?php endif; ?>
		</div>

		<form class="events-location-search" role="search" method="get" action="<?php echo esc_url( home_url( '/location/' ) ); ?>">
			<label for="events-location-search"><?php esc_html_e( 'Search cities', 'extrachill-events' ); ?></label>
			<div class="events-location-search__controls">
				<input id="events-location-search" name="search" type="search" autocomplete="off" required placeholder="<?php esc_attr_e( 'City or state', 'extrachill-events' ); ?>">
				<button class="button-1 button-small" type="submit"><?php esc_html_e( 'Search', 'extrachill-events' ); ?></button>
			</div>
		</form>

		<?php if ( $market ) : ?>
			<article class="events-primary-market">
				<div>
					<span class="events-primary-market__eyebrow"><?php esc_html_e( 'Your default market', 'extrachill-events' ); ?></span>
					<h3><?php echo esc_html( '' !== $market['label'] ? $market['label'] : $market['slug'] ); ?></h3>
					<?php if ( $market_count > 0 ) : ?>
						<p><?php echo esc_html( sprintf( /* translators: %s: Number of upcoming events. */ _n( '%s upcoming event', '%s upcoming events', $market_count, 'extrachill-events' ), number_format_i18n( $market_count ) ) ); ?></p>
					<?php endif; ?>
				</div>
				<nav class="events-primary-market__links" aria-label="<?php esc_attr_e( 'Default market event views', 'extrachill-events' ); ?>">
					<a href="<?php echo esc_url( trailingslashit( $market['url'] ) . 'tonight/' ); ?>"><?php esc_html_e( 'Tonight', 'extrachill-events' ); ?></a>
					<a href="<?php echo esc_url( trailingslashit( $market['url'] ) . 'this-weekend/' ); ?>"><?php esc_html_e( 'This Weekend', 'extrachill-events' ); ?></a>
					<a href="<?php echo esc_url( $market['url'] ); ?>"><?php esc_html_e( 'City calendar', 'extrachill-events' ); ?></a>
					<a href="<?php echo esc_url( $settings_url ); ?>"><?php esc_html_e( 'Change default', 'extrachill-events' ); ?></a>
				</nav>
			</article>
		<?php endif; ?>

		<?php if ( $sample ) : ?>
			<div class="events-market-sample">
				<h3><?php echo esc_html( $market ? __( 'Explore other active markets', 'extrachill-events' ) : __( 'Active markets', 'extrachill-events' ) ); ?></h3>
				<div class="taxonomy-badges">
					<?php foreach ( $sample as $location ) : ?>
						<a href="<?php echo esc_url( $location['url'] ); ?>" class="taxonomy-badge location-badge location-<?php echo esc_attr( $location['slug'] ); ?>"><?php echo esc_html( $location['label'] ); ?> (<?php echo esc_html( number_format_i18n( $location['count'] ) ); ?>)</a>
					<?php endforeach; ?>
				</div>
			</div>
		<?php endif; ?>

		<div class="events-home-router__paths">
			<a href="<?php echo esc_url( home_url( '/location/' ) ); ?>"><?php esc_html_e( 'Browse all locations', 'extrachill-events' ); ?> &rarr;</a>
			<a href="<?php echo esc_url( home_url( '/all/' ) ); ?>"><?php esc_html_e( 'See every event', 'extrachill-events' ); ?> &rarr;</a>
		</div>
	</section>
	<?php
}
