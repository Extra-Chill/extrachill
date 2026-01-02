<?php
/**
 * Network Dropdown Component
 *
 * Renders a dropdown site-switcher for network homepage breadcrumbs.
 * Allows users to navigate between network sites directly from any subsite homepage.
 *
 * @package ExtraChill
 * @since 1.1.8
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Get network sites for dropdown
 *
 * Returns array of network sites for the breadcrumb dropdown.
 * Sites listed here should match the footer Network menu.
 *
 * @return array Array of sites with 'label' and 'url' keys
 */
function extrachill_get_dropdown_network_sites() {
	$sites = array(
		array(
			'label' => 'Blog',
			'url'   => ec_get_site_url( 'main' ) . '/blog',
		),
		array(
			'label' => 'Community',
			'url'   => ec_get_site_url( 'community' ),
		),
		array(
			'label' => 'Events Calendar',
			'url'   => ec_get_site_url( 'events' ),
		),
		array(
			'label' => 'Artist Platform',
			'url'   => ec_get_site_url( 'artist' ),
		),
		array(
			'label' => 'Newsletter',
			'url'   => ec_get_site_url( 'newsletter' ),
		),
		array(
			'label' => 'Shop',
			'url'   => ec_get_site_url( 'shop' ),
		),
		array(
			'label' => 'Documentation',
			'url'   => ec_get_site_url( 'docs' ),
		),
		array(
			'label' => 'News Wire',
			'url'   => ec_get_site_url( 'wire' ),
		),
	);

	return $sites;
}

/**
 * Render network dropdown
 *
 * Outputs a dropdown component with the current site label and links to other network sites.
 * Excludes the current site from the dropdown menu.
 *
 * @param string $current_label The label for the current site (e.g., "Chat", "Events")
 * @return string HTML output for the dropdown
 */
function extrachill_network_dropdown( $current_label ) {
	wp_enqueue_script( 'extrachill-mini-dropdown' );

	$sites = extrachill_get_dropdown_network_sites();

	// Filter out current site by matching label
	$other_sites = array_filter( $sites, function( $site ) use ( $current_label ) {
		return $site['label'] !== $current_label;
	} );

	// If no other sites, just return the label as a span
	if ( empty( $other_sites ) ) {
		return '<span>' . esc_html( $current_label ) . '</span>';
	}

	ob_start();
	?>
	<span class="ec-mini-dropdown" aria-expanded="false">
		<button class="ec-mini-dropdown-toggle network-dropdown-toggle" aria-haspopup="true">
			<?php echo esc_html( $current_label ); ?>
			<?php echo ec_icon( 'chevron-down' ); ?>
		</button>
		<ul class="ec-mini-dropdown-menu" role="menu">
			<?php foreach ( $other_sites as $site ) : ?>
				<li role="menuitem">
					<a href="<?php echo esc_url( $site['url'] ); ?>"><?php echo esc_html( $site['label'] ); ?></a>
				</li>
			<?php endforeach; ?>
		</ul>
	</span>
	<?php
	return ob_get_clean();
}
