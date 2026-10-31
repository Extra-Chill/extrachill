<?php
/**
 * Secondary Header Navigation
 *
 * Renders a secondary navigation bar below the main header when plugins
 * provide items via the extrachill_secondary_header_items filter.
 * Does not render if no items are hooked in.
 *
 * @package ExtraChill
 * @since 1.0.9
 */

$secondary_header_items = apply_filters( 'extrachill_secondary_header_items', array() );

if ( empty( $secondary_header_items ) || ! is_array( $secondary_header_items ) ) {
	return;
}

usort(
	$secondary_header_items,
	function ( $a, $b ) {
		$priority_a = isset( $a['priority'] ) ? (int) $a['priority'] : 10;
		$priority_b = isset( $b['priority'] ) ? (int) $b['priority'] : 10;
		return $priority_a <=> $priority_b;
	}
);
?>

<nav class="secondary-header" role="navigation" aria-label="<?php esc_attr_e( 'Secondary Navigation', 'extrachill' ); ?>">
	<?php
	foreach ( $secondary_header_items as $item ) {
		if ( isset( $item['url'] ) && isset( $item['label'] ) ) {
			printf(
				'<a href="%s">%s</a>',
				esc_url( $item['url'] ),
				esc_html( $item['label'] )
			);
		}
	}
	?>
</nav>
