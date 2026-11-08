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

/** Register the code-owned, opt-in breadcrumb experiment. */
function extrachill_network_register_dropdown_experiment( $definitions ) {
	$definitions['network-dropdown-everywhere'] = array(
		'key'                  => 'network-dropdown-everywhere',
		'definition_version'   => 1,
		'assignment_policy'    => 'weighted_random',
		'default_state'        => 'inactive',
		'default_variant'      => 'control',
		'control_variant'      => 'control',
		'variants'             => array(
			'control'   => 50,
			'treatment' => 50,
		),
		'surfaces'             => array( 'breadcrumb-network-dropdown' ),
		'eligibility_callback' => 'extrachill_network_dropdown_experiment_eligible',
	);
	return $definitions;
}
add_filter( 'extrachill_experiment_definitions', 'extrachill_network_register_dropdown_experiment' );

/** Context travels to the assignment endpoint, where page conditionals are unavailable. */
function extrachill_network_dropdown_experiment_eligible( $context, $surface ) {
	return ! is_admin()
		&& 'breadcrumb-network-dropdown' === $surface
		&& isset( $context['blog_id'], $context['existing_dropdown'] )
		&& (string) get_current_blog_id() === (string) $context['blog_id']
		&& '0' === (string) $context['existing_dropdown'];
}

/** Match the current site's canonical URL to the filtered dropdown labels. */
function extrachill_network_dropdown_current_label() {
	$site_key = ec_get_blog_slug_by_id( get_current_blog_id() );
	if ( ! $site_key ) {
		return '';
	}
	$current_url = untrailingslashit( ec_get_site_url( $site_key ) );
	foreach ( extrachill_get_dropdown_network_sites() as $site ) {
		$url = untrailingslashit( $site['url'] );
		if ( $current_url === $url || ( ec_get_blog_id( 'main' ) === get_current_blog_id() && ec_get_site_url( 'main' ) . '/blog' === $url ) ) {
			return $site['label'];
		}
	}
	return '';
}

/** Register presentation after the shared assignment runtime. */
function extrachill_network_register_dropdown_experiment_script() {
	$path = EXTRACHILL_NETWORK_PLUGIN_DIR . 'assets/js/network-dropdown-experiment.js';
	wp_register_script(
		'extrachill-network-dropdown-experiment',
		EXTRACHILL_NETWORK_PLUGIN_URL . 'assets/js/network-dropdown-experiment.js',
		array( 'extrachill-experiment-assignment' ),
		(string) filemtime( $path ),
		array(
			'strategy'  => 'defer',
			'in_footer' => true,
		)
	);
}
add_action( 'wp_enqueue_scripts', 'extrachill_network_register_dropdown_experiment_script', 6 );

/** Append a hidden, cache-neutral candidate immediately after the root crumb. */
function extrachill_network_dropdown_experiment_root( $root ) {
	if ( is_admin() ) {
		return $root;
	}

	// Use the same exact trail predicate as the existing dropdown, not a list
	// of page types: site plugins own their front-page/archive overrides.
	$trail = apply_filters( 'extrachill_breadcrumbs_override_trail', '' );
	if ( preg_match( '/^<span class="network-dropdown-target">(.+)<\/span>$/', $trail ) ) {
		return $root;
	}

	$label = extrachill_network_dropdown_current_label();
	if ( '' === $label || ! wp_script_is( 'extrachill-network-dropdown-experiment', 'registered' ) ) {
		return $root;
	}
	$attributes = extrachill_experiment_attributes(
		'network-dropdown-everywhere',
		'breadcrumb-network-dropdown',
		array(
			'blog_id'           => get_current_blog_id(),
			'existing_dropdown' => '0',
		)
	);
	if ( '' === $attributes ) {
		return $root;
	}

	wp_enqueue_script( 'extrachill-network-dropdown-experiment' );
	return $root . '<span class="ec-network-dropdown-experiment" hidden inert aria-hidden="true" ' . $attributes . '> › ' . extrachill_network_dropdown( $label ) . '</span>';
}
/** Output trusted component HTML through the theme's unsanitized sibling hook. */
function extrachill_network_dropdown_experiment_after_root() {
	echo extrachill_network_dropdown_experiment_root( '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Component escapes labels, URLs and experiment attributes.
}
add_action( 'extrachill_breadcrumbs_after_root', 'extrachill_network_dropdown_experiment_after_root' );

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
	<span class="ec-mini-dropdown" aria-expanded="false" data-ec-track-placement="<?php echo esc_attr( 'network-dropdown' ); ?>">
		<button class="ec-mini-dropdown-toggle network-dropdown-toggle" aria-haspopup="true" data-ec-track="<?php echo esc_attr( 'network-dropdown-open' ); ?>">
			<?php echo esc_html( $current_label ); ?>
			<?php echo ec_icon( 'chevron-down' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- ec_icon() returns trusted local SVG sprite markup. ?>
		</button>
		<ul class="ec-mini-dropdown-menu" role="menu">
			<?php foreach ( $other_sites as $site ) : ?>
				<li role="menuitem">
					<a href="<?php echo esc_url( $site['url'] ); ?>" data-ec-track="<?php echo esc_attr( 'network-dropdown-link' ); ?>"><?php echo esc_html( $site['label'] ); ?></a>
				</li>
			<?php endforeach; ?>
		</ul>
	</span>
	<?php
	return ob_get_clean();
}
