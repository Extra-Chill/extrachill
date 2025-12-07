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
			'url'   => 'https://extrachill.com/blog',
		),
		array(
			'label' => 'Community',
			'url'   => 'https://community.extrachill.com',
		),
		array(
			'label' => 'Events',
			'url'   => 'https://events.extrachill.com',
		),
		array(
			'label' => 'Artist Platform',
			'url'   => 'https://artist.extrachill.com',
		),
		array(
			'label' => 'Newsletter',
			'url'   => 'https://newsletter.extrachill.com',
		),
		array(
			'label' => 'Shop',
			'url'   => 'https://shop.extrachill.com',
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
	<span class="network-dropdown">
		<button class="network-dropdown-toggle" aria-expanded="false" aria-haspopup="true">
			<?php echo esc_html( $current_label ); ?>
			<?php echo ec_icon( 'chevron-down' ); ?>
		</button>
		<ul class="network-dropdown-menu" role="menu">
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
