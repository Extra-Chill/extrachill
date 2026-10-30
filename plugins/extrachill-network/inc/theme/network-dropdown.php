<?php
/**
 * Network Dropdown Component
 *
 * Renders a dropdown site-switcher for network homepage breadcrumbs.
 * Hooks into theme's extrachill_breadcrumbs_trail_output filter.
 *
 * @package ExtraChill\Network
 * @since 1.4.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter( 'extrachill_breadcrumbs_trail_output', 'extrachill_network_breadcrumb_dropdown' );

function extrachill_network_breadcrumb_dropdown( $trail ) {
	if ( preg_match( '/^<span class="network-dropdown-target">(.+)<\/span>$/', $trail, $matches ) ) {
		return extrachill_network_dropdown( $matches[1] );
	}
	return $trail;
}

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

	/**
	 * Filter the list of network sites shown in the dropdown.
	 *
	 * Allows plugins to add or remove sites from the network dropdown.
	 * Each site entry should be an array with 'label' and 'url' keys.
	 *
	 * @since 1.8.0
	 * @param array $sites Array of site arrays with 'label' and 'url' keys.
	 */
	$sites = apply_filters( 'extrachill_network_dropdown_sites', $sites );

	// Add team-member-only sites.
	if ( function_exists( 'ec_is_team_member' ) && ec_is_team_member() ) {
		$sites[] = array(
			'label' => 'Studio',
			'url'   => ec_get_site_url( 'studio' ),
		);
	}

	return $sites;
}

function extrachill_network_dropdown( $current_label ) {
	wp_enqueue_script( 'extrachill-mini-dropdown' );

	$sites = extrachill_get_dropdown_network_sites();

	$other_sites = array_filter(
		$sites,
		function ( $site ) use ( $current_label ) {
			return $site['label'] !== $current_label;
		}
	);

	if ( empty( $other_sites ) ) {
		return '<span>' . esc_html( $current_label ) . '</span>';
	}

	ob_start();
	?>
	<span class="ec-mini-dropdown" aria-expanded="false">
		<button class="ec-mini-dropdown-toggle network-dropdown-toggle" aria-haspopup="true">
			<?php echo esc_html( $current_label ); ?>
			<?php echo ec_icon( 'chevron-down' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- ec_icon() returns trusted local SVG sprite markup. ?>
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
