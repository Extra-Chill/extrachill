<?php
/**
 * Footer Bottom Menu
 *
 * Bottom footer menu with legal/policy links.
 * Plugins can add items via the extrachill_footer_bottom_menu_items filter.
 *
 * @package ExtraChill
 * @since 1.0.0
 */

$default_items = array(
	array(
		'url'      => 'https://extrachill.com/affiliate-disclosure/',
		'label'    => 'Affiliate Disclosure',
		'priority' => 10,
	),
	array(
		'url'      => 'https://extrachill.com/privacy-policy/',
		'label'    => 'Privacy Policy',
		'rel'      => 'privacy-policy',
		'priority' => 20,
	),
);

$footer_bottom_items = apply_filters( 'extrachill_footer_bottom_menu_items', $default_items );

if ( empty( $footer_bottom_items ) || ! is_array( $footer_bottom_items ) ) {
	return;
}

usort( $footer_bottom_items, function( $a, $b ) {
	$priority_a = isset( $a['priority'] ) ? (int) $a['priority'] : 10;
	$priority_b = isset( $b['priority'] ) ? (int) $b['priority'] : 10;
	return $priority_a <=> $priority_b;
});
?>

<div class="footer-extra-menu">
    <div class="menu-footer-bottom-container">
        <ul class="menu">
            <?php foreach ( $footer_bottom_items as $item ) : ?>
                <?php if ( isset( $item['url'] ) && isset( $item['label'] ) ) : ?>
                    <li class="menu-item<?php echo isset( $item['rel'] ) && 'privacy-policy' === $item['rel'] ? ' menu-item-privacy-policy' : ''; ?>">
                        <a href="<?php echo esc_url( $item['url'] ); ?>"<?php echo isset( $item['rel'] ) ? ' rel="' . esc_attr( $item['rel'] ) . '"' : ''; ?>><?php echo esc_html( $item['label'] ); ?></a>
                    </li>
                <?php endif; ?>
            <?php endforeach; ?>
        </ul>
    </div>
</div>