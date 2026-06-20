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
$cities          = $cities_response->is_error() ? array() : (array) $cities_response->get_data();

// Region rollup totals (ancestor subtree counts). Keyed by term_id.
$rollup_request = new WP_REST_Request( 'GET', '/extrachill/v1/events/upcoming-counts' );
$rollup_request->set_query_params(
	array(
		'taxonomy' => 'location',
		'rollup'   => true,
	)
);
$rollup_response = $rollup_request->is_error() ? null : rest_do_request( $rollup_request );
$rollup_terms    = ( $rollup_response && ! $rollup_response->is_error() ) ? (array) $rollup_response->get_data() : array();

$rollup_by_id = array();
foreach ( $rollup_terms as $r ) {
	$rollup_by_id[ (int) $r['term_id'] ] = (int) $r['count'];
}

// Bucket cities under their region root using taxonomy ancestry. A city with
// no region ancestor falls into a generic bucket so nothing is dropped.
$regions             = array(); // root_term_id => array of city rows.
$region_meta         = array(); // root_term_id => WP_Term.
$ungrouped           = array();
$ungrouped_label_key = 0;

foreach ( $cities as $city ) {
	if ( (int) $city['count'] < $min_events ) {
		continue;
	}

	$ancestors = get_ancestors( (int) $city['term_id'], 'location', 'taxonomy' );
	if ( empty( $ancestors ) ) {
		$ungrouped[] = $city;
		continue;
	}

	// Topmost ancestor is the region root.
	$root_id = (int) end( $ancestors );
	if ( ! isset( $region_meta[ $root_id ] ) ) {
		$root_term = get_term( $root_id, 'location' );
		if ( ! $root_term || is_wp_error( $root_term ) ) {
			$ungrouped[] = $city;
			continue;
		}
		$region_meta[ $root_id ] = $root_term;
		$regions[ $root_id ]     = array();
	}
	$regions[ $root_id ][] = $city;
}

// Order regions by their rolled-up total, descending.
uksort(
	$regions,
	static function ( $a, $b ) use ( $rollup_by_id ) {
		return ( $rollup_by_id[ $b ] ?? 0 ) <=> ( $rollup_by_id[ $a ] ?? 0 );
	}
);
?>

<div class="events-calendar-container ec-mobile-full-width-panel">
	<div class="page-content">
		<header class="taxonomy-archive-header">
			<h1 class="page-title"><?php esc_html_e( 'Live Music by Location', 'extrachill-events' ); ?></h1>
			<p class="cities-directory-intro">
				<?php esc_html_e( 'Every city with upcoming live music. Pick a city to see its calendar.', 'extrachill-events' ); ?>
			</p>
		</header>
	</div>

	<div class="page-content cities-directory">
		<?php foreach ( $regions as $root_id => $region_cities ) : ?>
			<?php
			$region       = $region_meta[ $root_id ];
			$region_link  = get_term_link( $region );
			$region_total = $rollup_by_id[ $root_id ] ?? 0;
			?>
			<section class="cities-region">
				<h2 class="cities-region-title">
					<?php if ( ! is_wp_error( $region_link ) ) : ?>
						<a href="<?php echo esc_url( $region_link ); ?>"><?php echo esc_html( $region->name ); ?></a>
					<?php else : ?>
						<?php echo esc_html( $region->name ); ?>
					<?php endif; ?>
					<?php if ( $region_total > 0 ) : ?>
						<span class="cities-region-count">(<?php echo esc_html( number_format_i18n( $region_total ) ); ?>)</span>
					<?php endif; ?>
				</h2>
				<div class="taxonomy-badges">
					<?php foreach ( $region_cities as $city ) : ?>
						<a href="<?php echo esc_url( $city['url'] ); ?>" class="taxonomy-badge location-badge location-<?php echo esc_attr( $city['slug'] ); ?>">
							<?php echo esc_html( $city['name'] ); ?> (<?php echo esc_html( $city['count'] ); ?>)
						</a>
					<?php endforeach; ?>
				</div>
			</section>
		<?php endforeach; ?>

		<?php if ( ! empty( $ungrouped ) ) : ?>
			<section class="cities-region cities-region-other">
				<h2 class="cities-region-title"><?php esc_html_e( 'Other', 'extrachill-events' ); ?></h2>
				<div class="taxonomy-badges">
					<?php foreach ( $ungrouped as $city ) : ?>
						<a href="<?php echo esc_url( $city['url'] ); ?>" class="taxonomy-badge location-badge location-<?php echo esc_attr( $city['slug'] ); ?>">
							<?php echo esc_html( $city['name'] ); ?> (<?php echo esc_html( $city['count'] ); ?>)
						</a>
					<?php endforeach; ?>
				</div>
			</section>
		<?php endif; ?>
	</div>
</div>

<?php get_footer(); ?>
