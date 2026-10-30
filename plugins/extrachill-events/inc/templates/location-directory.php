<?php
/**
 * Location Directory Template (/location/ — taxonomy root)
 *
 * Full directory of every city with upcoming events, grouped by region. The
 * region grouping is driven entirely by the location taxonomy hierarchy
 * (region root -> state -> city); a region section only renders when it has
 * cities with upcoming events. Region headers show the rolled-up total via
 * the data-machine-events ancestor rollup primitive.
 *
 * Rendered at the location taxonomy's own base slug (/location/) rather than
 * an invented route, so "the index of all locations" lives at the natural URL
 * the taxonomy already implies.
 *
 * No region names are hard-coded here — buckets come from the taxonomy tree.
 *
 * @package ExtraChillEvents
 * @since 0.24.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();
extrachill_breadcrumbs();

/**
 * Minimum upcoming-event count for a city to appear in the directory.
 *
 * @since 0.24.0
 */
$min_events = (int) apply_filters( 'extrachill_events_directory_min_count', 1 );

// All cities with upcoming events (leaf location terms), count desc.
$cities_request = new WP_REST_Request( 'GET', '/extrachill/v1/events/upcoming-counts' );
$cities_request->set_query_params( array( 'taxonomy' => 'location' ) );
$cities_response = rest_do_request( $cities_request );
$cities          = $cities_response->is_error() ? array() : extrachill_events_prepare_location_rows( (array) $cities_response->get_data() );

// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public read-only directory search.
$directory_search = isset( $_GET['search'] ) && is_scalar( $_GET['search'] ) ? sanitize_text_field( wp_unslash( $_GET['search'] ) ) : '';
if ( '' !== $directory_search ) {
	$cities = array_values(
		array_filter(
			$cities,
			static function ( array $city ) use ( $directory_search ): bool {
				return false !== stripos( $city['label'], $directory_search ) || false !== stripos( $city['region'], $directory_search );
			}
		)
	);
}

// Region rollup totals (ancestor subtree counts). Keyed by term_id.
$rollup_request = new WP_REST_Request( 'GET', '/extrachill/v1/events/upcoming-counts' );
$rollup_request->set_query_params(
	array(
		'taxonomy' => 'location',
		'rollup'   => true,
	)
);
$rollup_response = rest_do_request( $rollup_request );
$rollup_terms    = $rollup_response->is_error() ? array() : (array) $rollup_response->get_data();

$rollup_by_id = array();
foreach ( $rollup_terms as $r ) {
	$rollup_by_id[ (int) $r['term_id'] ] = (int) $r['count'];
}

// Bucket canonical cities by region and state/province.
$regions = array();

foreach ( $cities as $city ) {
	if ( (int) $city['count'] < $min_events ) {
		continue;
	}

	$region_id = (int) $city['region_id'];
	$state_id  = (int) $city['state_id'];
	if ( ! isset( $regions[ $region_id ] ) ) {
		$regions[ $region_id ] = array(
			'name'   => $city['region'],
			'states' => array(),
		);
	}
	if ( ! isset( $regions[ $region_id ]['states'][ $state_id ] ) ) {
		$regions[ $region_id ]['states'][ $state_id ] = array(
			'name'   => $city['state'],
			'cities' => array(),
		);
	}
	$regions[ $region_id ]['states'][ $state_id ]['cities'][] = $city;
}

// Order regions by their rolled-up total, descending.
uksort(
	$regions,
	static function ( $a, $b ) use ( $rollup_by_id ): int {
		return ( $rollup_by_id[ $b ] ?? 0 ) <=> ( $rollup_by_id[ $a ] ?? 0 );
	}
);

foreach ( $regions as &$region ) {
	uksort(
		$region['states'],
		static function ( $a, $b ) use ( $rollup_by_id ): int {
			return ( $rollup_by_id[ $b ] ?? 0 ) <=> ( $rollup_by_id[ $a ] ?? 0 );
		}
	);
}
unset( $region );
?>

<div class="events-calendar-container ec-mobile-full-width-panel">
	<div class="page-content">
		<header class="taxonomy-archive-header">
			<h1 class="page-title"><?php esc_html_e( 'Live Music by Location', 'extrachill-events' ); ?></h1>
			<p class="cities-directory-intro">
				<?php esc_html_e( 'Every city with upcoming live music. Pick a city to see its calendar.', 'extrachill-events' ); ?>
			</p>
		</header>
		<form class="events-location-search" role="search" method="get" action="<?php echo esc_url( home_url( '/location/' ) ); ?>">
			<label for="location-directory-search"><?php esc_html_e( 'Search cities', 'extrachill-events' ); ?></label>
			<div class="events-location-search__controls">
				<input id="location-directory-search" name="search" type="search" value="<?php echo esc_attr( $directory_search ); ?>" placeholder="<?php esc_attr_e( 'City or state', 'extrachill-events' ); ?>">
				<button class="button-1 button-small" type="submit"><?php esc_html_e( 'Search', 'extrachill-events' ); ?></button>
			</div>
		</form>
	</div>

	<div class="page-content cities-directory">
		<?php if ( '' !== $directory_search ) : ?>
			<p class="cities-directory-results" role="status">
				<?php
				printf(
					/* translators: 1: Result count, 2: Search query. */
					esc_html( _n( '%1$s active city matching “%2$s”', '%1$s active cities matching “%2$s”', count( $cities ), 'extrachill-events' ) ),
					esc_html( number_format_i18n( count( $cities ) ) ),
					esc_html( $directory_search )
				);
				?>
			</p>
		<?php endif; ?>

		<?php foreach ( $regions as $root_id => $region ) : ?>
			<?php
			$region_term  = get_term( $root_id, 'location' );
			$region_link  = $region_term instanceof WP_Term ? get_term_link( $region_term ) : new WP_Error();
			$region_total = $rollup_by_id[ $root_id ] ?? 0;
			?>
			<section class="cities-region">
				<h2 class="cities-region-title">
					<?php if ( ! is_wp_error( $region_link ) ) : ?>
						<a href="<?php echo esc_url( $region_link ); ?>"><?php echo esc_html( $region['name'] ); ?></a>
					<?php else : ?>
						<?php echo esc_html( $region['name'] ); ?>
					<?php endif; ?>
					<?php if ( $region_total > 0 ) : ?>
						<span class="cities-region-count">(<?php echo esc_html( number_format_i18n( $region_total ) ); ?>)</span>
					<?php endif; ?>
				</h2>
				<?php foreach ( $region['states'] as $state_id => $state ) : ?>
					<section class="cities-state">
						<h3 class="cities-state-title"><?php echo esc_html( $state['name'] ); ?> <span>(<?php echo esc_html( number_format_i18n( $rollup_by_id[ $state_id ] ?? 0 ) ); ?>)</span></h3>
						<div class="taxonomy-badges">
							<?php foreach ( $state['cities'] as $city ) : ?>
								<a href="<?php echo esc_url( $city['url'] ); ?>" class="taxonomy-badge location-badge location-<?php echo esc_attr( $city['slug'] ); ?>"><?php echo esc_html( $city['name'] ); ?> (<?php echo esc_html( number_format_i18n( $city['count'] ) ); ?>)</a>
							<?php endforeach; ?>
						</div>
					</section>
				<?php endforeach; ?>
			</section>
		<?php endforeach; ?>

		<?php if ( empty( $regions ) ) : ?>
			<p class="cities-directory-empty"><?php esc_html_e( 'No active cities matched your search. Try another city or state.', 'extrachill-events' ); ?></p>
		<?php endif; ?>
	</div>
</div>

<?php get_footer(); ?>
