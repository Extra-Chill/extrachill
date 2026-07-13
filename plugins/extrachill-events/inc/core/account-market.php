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
 * Get the current user's resolved Local Scene.
 *
 * When the Users Ability is unavailable or returns an invalid/empty
 * preference, this fails open.
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
	if ( is_wp_error( $result ) || ! is_array( $result ) || ! is_array( $result['local_scene'] ?? null ) ) {
		return null;
	}

	$location    = $result['local_scene'];
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
				<span><?php esc_html_e( 'Your Local Scene is still available whenever you want to focus the calendar again.', 'extrachill-events' ); ?></span>
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
				<span><?php esc_html_e( 'Set your Local Scene once and use it across devices.', 'extrachill-events' ); ?></span>
			<?php else : ?>
				<span><?php esc_html_e( 'Sign in to save your Local Scene across devices.', 'extrachill-events' ); ?></span>
			<?php endif; ?>
		</div>
		<div class="events-market-context__actions">
			<?php if ( $market && $is_exploring ) : ?>
				<a href="<?php echo esc_url( $current_url ); ?>"><?php esc_html_e( 'Use my Local Scene', 'extrachill-events' ); ?></a>
				<a href="<?php echo esc_url( $settings_url ); ?>"><?php esc_html_e( 'Change Local Scene', 'extrachill-events' ); ?></a>
			<?php elseif ( $market ) : ?>
				<a href="<?php echo esc_url( $settings_url ); ?>"><?php esc_html_e( 'Change Local Scene', 'extrachill-events' ); ?></a>
				<a href="<?php echo esc_url( $explore_url ); ?>"><?php esc_html_e( 'Explore all locations', 'extrachill-events' ); ?></a>
			<?php elseif ( $is_logged_in ) : ?>
				<a class="button-1 button-small" href="<?php echo esc_url( $settings_url ); ?>"><?php esc_html_e( 'Set Local Scene', 'extrachill-events' ); ?></a>
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
			<h2 id="events-market-heading"><?php esc_html_e( 'Your Local Scene', 'extrachill-events' ); ?></h2>
		<?php elseif ( $is_logged_in ) : ?>
			<h2 id="events-market-heading"><?php esc_html_e( 'Choose your Local Scene', 'extrachill-events' ); ?></h2>
			<p><?php esc_html_e( 'Find a city now, or set your Local Scene to put it first on every visit.', 'extrachill-events' ); ?> <a href="<?php echo esc_url( $settings_url ); ?>"><?php esc_html_e( 'Set Local Scene', 'extrachill-events' ); ?></a></p>
		<?php else : ?>
			<h2 id="events-market-heading"><?php esc_html_e( 'Find your city', 'extrachill-events' ); ?></h2>
			<p><?php esc_html_e( 'Search without an account.', 'extrachill-events' ); ?> <a href="<?php echo esc_url( wp_login_url( home_url( '/' ) ) ); ?>"><?php esc_html_e( 'Sign in to save your Local Scene', 'extrachill-events' ); ?></a></p>
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
					<span class="events-primary-market__eyebrow"><?php esc_html_e( 'Your Local Scene', 'extrachill-events' ); ?></span>
					<h3><?php echo esc_html( '' !== $market['label'] ? $market['label'] : $market['slug'] ); ?></h3>
					<?php if ( $market_count > 0 ) : ?>
						<p><?php echo esc_html( sprintf( /* translators: %s: Number of upcoming events. */ _n( '%s upcoming event', '%s upcoming events', $market_count, 'extrachill-events' ), number_format_i18n( $market_count ) ) ); ?></p>
					<?php endif; ?>
				</div>
				<nav class="events-primary-market__links" aria-label="<?php esc_attr_e( 'Local Scene event views', 'extrachill-events' ); ?>">
					<a href="<?php echo esc_url( trailingslashit( $market['url'] ) . 'tonight/' ); ?>"><?php esc_html_e( 'Tonight', 'extrachill-events' ); ?></a>
					<a href="<?php echo esc_url( trailingslashit( $market['url'] ) . 'this-weekend/' ); ?>"><?php esc_html_e( 'This Weekend', 'extrachill-events' ); ?></a>
					<a href="<?php echo esc_url( $market['url'] ); ?>"><?php esc_html_e( 'City calendar', 'extrachill-events' ); ?></a>
					<a href="<?php echo esc_url( $settings_url ); ?>"><?php esc_html_e( 'Change Local Scene', 'extrachill-events' ); ?></a>
				</nav>
			</article>
		<?php endif; ?>

		<?php if ( $sample ) : ?>
			<div class="events-market-sample">
				<h3><?php echo esc_html( $market ? __( 'Explore other active scenes', 'extrachill-events' ) : __( 'Active scenes', 'extrachill-events' ) ); ?></h3>
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

/**
 * Get a selectable city from the current location archive.
 */
function extrachill_events_get_archive_scene_term(): ?WP_Term {
	if ( ! is_tax( 'location' ) ) {
		return null;
	}

	$term = get_queried_object();
	if ( ! $term instanceof WP_Term || count( get_ancestors( $term->term_id, 'location', 'taxonomy' ) ) < 2 ) {
		return null;
	}

	return $term;
}

/**
 * Save an explicitly selected archive city through the Users settings Ability.
 *
 * @param WP_Term $term  Selected city term.
 * @param string  $nonce Submitted nonce.
 * @return bool Whether the preference was saved.
 */
function extrachill_events_update_archive_scene( WP_Term $term, string $nonce ): bool {
	if ( ! is_user_logged_in() || ! wp_verify_nonce( $nonce, 'extrachill_events_save_scene_' . $term->term_id ) || ! function_exists( 'wp_get_ability' ) ) {
		return false;
	}

	$ability = wp_get_ability( 'extrachill/update-user-settings' );
	if ( ! $ability ) {
		return false;
	}

	return ! is_wp_error( $ability->execute( array( 'local_scene' => $term->slug ) ) );
}

/**
 * Handle the archive Local Scene form submission.
 */
function extrachill_events_handle_archive_scene_update(): void {
	$term           = extrachill_events_get_archive_scene_term();
	$request_method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
	if ( null === $term || 'POST' !== $request_method || ! isset( $_POST['extrachill_events_scene_action'] ) || ! isset( $_POST['extrachill_events_scene_nonce'] ) ) {
		return;
	}

	$nonce = isset( $_POST['extrachill_events_scene_nonce'] ) && is_scalar( $_POST['extrachill_events_scene_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['extrachill_events_scene_nonce'] ) ) : '';
	if ( ! wp_verify_nonce( $nonce, 'extrachill_events_save_scene_' . $term->term_id ) ) {
		wp_safe_redirect( add_query_arg( 'scene_status', 'failed', get_term_link( $term ) ) );
		exit;
	}

	$status = extrachill_events_update_archive_scene( $term, $nonce ) ? 'saved' : 'failed';
	wp_safe_redirect( add_query_arg( 'scene_status', $status, get_term_link( $term ) ) );
	exit;
}
add_action( 'template_redirect', 'extrachill_events_handle_archive_scene_update', 5 );

/**
 * Render an explicit Local Scene choice on selectable city archives.
 */
function extrachill_events_render_archive_scene_cta(): void {
	$term = extrachill_events_get_archive_scene_term();
	if ( null === $term ) {
		return;
	}

	$archive_url  = get_term_link( $term );
	$is_logged_in = is_user_logged_in();
	$current      = extrachill_events_get_account_market();
	$is_current   = $current && (int) $current['term_id'] === (int) $term->term_id;
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only flash status set by this handler's nonce-protected redirect.
	$status = isset( $_GET['scene_status'] ) && is_scalar( $_GET['scene_status'] ) ? sanitize_text_field( wp_unslash( $_GET['scene_status'] ) ) : '';

	if ( $is_logged_in ) {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		nocache_headers();
	}
	?>
	<aside class="events-market-context<?php echo $is_logged_in ? '' : ' events-market-context--quiet'; ?>" aria-label="<?php esc_attr_e( 'Local Scene preference', 'extrachill-events' ); ?>" role="status">
		<div class="events-market-context__copy">
			<strong><?php echo esc_html( sprintf( /* translators: %s: City name. */ __( 'Is %s your local scene?', 'extrachill-events' ), $term->name ) ); ?></strong>
			<?php if ( 'failed' === $status ) : ?>
				<span><?php esc_html_e( 'We could not update your Local Scene. Please try again.', 'extrachill-events' ); ?></span>
			<?php elseif ( $is_current || 'saved' === $status ) : ?>
				<span><?php esc_html_e( 'This is your Local Scene.', 'extrachill-events' ); ?></span>
			<?php endif; ?>
		</div>
		<div class="events-market-context__actions">
			<?php if ( ! $is_logged_in ) : ?>
				<a class="button-1 button-small" href="<?php echo esc_url( wp_login_url( $archive_url ) ); ?>"><?php esc_html_e( 'Sign in to save', 'extrachill-events' ); ?></a>
			<?php elseif ( ! $is_current && 'saved' !== $status ) : ?>
				<form method="post" action="<?php echo esc_url( $archive_url ); ?>">
					<?php wp_nonce_field( 'extrachill_events_save_scene_' . $term->term_id, 'extrachill_events_scene_nonce' ); ?>
					<input type="hidden" name="extrachill_events_scene_action" value="save">
					<button class="button-1 button-small" type="submit"><?php esc_html_e( 'Make this my Local Scene', 'extrachill-events' ); ?></button>
				</form>
			<?php endif; ?>
		</div>
	</aside>
	<?php
}
add_action( 'extrachill_archive_below_description', 'extrachill_events_render_archive_scene_cta', 4 );
