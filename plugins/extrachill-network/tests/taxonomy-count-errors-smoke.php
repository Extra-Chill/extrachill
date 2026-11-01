<?php
/**
 * Taxonomy count errors remain WP_Error results instead of return TypeErrors.
 *
 * @package ExtraChillNetwork
 */

define( 'ABSPATH', __DIR__ . '/' );

class WP_Error {
	public function __construct( public string $code, public string $message, public array $data ) {}
}

function add_action( ...$args ) {}
function __( $text, $domain ) {
	return $text;
}
function ec_get_blog_id( $site_key ) {
	return 'main' === $site_key ? 1 : 0;
}
function sanitize_title( $slug ) {
	return $slug;
}
function get_taxonomy( $taxonomy ) {
	return false;
}
function switch_to_blog( $blog_id ) {
	$GLOBALS['taxonomy_count_blog_depth']++;
}
function restore_current_blog() {
	$GLOBALS['taxonomy_count_blog_depth']--;
}

require_once dirname( __DIR__ ) . '/inc/Abilities/TaxonomyCountAbilities.php';

$GLOBALS['taxonomy_count_blog_depth'] = 0;
$ability = new ExtraChillNetwork\Abilities\TaxonomyCountAbilities();
foreach ( array(
	array( 'unknown', '', 'unknown_site' ),
	array( 'main', '', 'taxonomy_not_found' ),
	array( 'main', 'missing-term', 'taxonomy_not_found' ),
) as $case ) {
	$result = $ability->executeGetTaxonomyPostCounts(
		array( 'site' => $case[0], 'taxonomy' => 'missing', 'slug' => $case[1] )
	);
	if ( ! $result instanceof WP_Error || $case[2] !== $result->code || 0 !== $GLOBALS['taxonomy_count_blog_depth'] ) {
		throw new RuntimeException( 'Taxonomy count error or blog restoration contract failed.' );
	}
}
echo "PASS: taxonomy count errors preserve their result and restore blog context.\n";
