<?php
/**
 * Extra Chill Footer Main Menu
 *
 * Network-centric footer navigation for Extra Chill Platform.
 * Hooks into theme's extrachill_footer_main_content action.
 *
 * @package ExtraChill\Network
 * @since 1.4.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'extrachill_footer_main_content', 'extrachill_network_footer_main_menu', 10 );

function extrachill_network_footer_main_menu() {
	$network_items = array(
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
	);

	/**
	 * Filter the footer network menu items.
	 *
	 * Allows plugins to add or remove items from the Network column in the footer.
	 * Each item should be an array with 'label' and 'url' keys.
	 *
	 * @since 1.8.0
	 * @param array $network_items Array of menu item arrays with 'label' and 'url' keys.
	 */
	$network_items = apply_filters( 'extrachill_footer_network_items', $network_items );

	// Add team-member-only sites.
	if ( function_exists( 'ec_is_team_member' ) && ec_is_team_member() ) {
		$network_items[] = array(
			'label' => 'Studio',
			'url'   => ec_get_site_url( 'studio' ),
		);
	}
	?>
	<div class="footer-menus">
		<div class="footer-menu-column">
			<ul class="footer-column-menu">
				<li class="menu-item menu-item-has-children">
					<a href="<?php echo esc_url( ec_get_site_url( 'main' ) ); ?>">Network</a>
					<ul class="sub-menu">
						<?php foreach ( $network_items as $item ) : ?>
							<li class="menu-item">
								<a href="<?php echo esc_url( $item['url'] ); ?>"><?php echo esc_html( $item['label'] ); ?></a>
							</li>
						<?php endforeach; ?>
					</ul>
				</li>
			</ul>
		</div>

		<div class="footer-menu-column">
			<ul class="footer-column-menu">
				<li class="menu-item menu-item-has-children">
					<a href="<?php echo esc_url( ec_get_site_url( 'main' ) ); ?>/blog">Explore</a>
					<ul class="sub-menu">
						<li class="menu-item">
							<a href="<?php echo esc_url( ec_get_site_url( 'main' ) ); ?>/category/interviews/">Interviews</a>
						</li>
						<li class="menu-item">
							<a href="<?php echo esc_url( ec_get_site_url( 'main' ) ); ?>/category/live-music-reviews/">Live Reviews</a>
						</li>
						<li class="menu-item">
							<a href="<?php echo esc_url( ec_get_site_url( 'wire' ) ); ?>">Festival Wire</a>
						</li>
						<li class="menu-item">
							<a href="<?php echo esc_url( ec_get_site_url( 'main' ) ); ?>/category/song-meanings/">Song Meanings</a>
						</li>
						<li class="menu-item">
							<a href="<?php echo esc_url( ec_get_site_url( 'main' ) ); ?>/category/music-news/">Music News</a>
						</li>
					</ul>
				</li>
			</ul>
		</div>

		<div class="footer-menu-column">
			<ul class="footer-column-menu">
				<li class="menu-item menu-item-has-children">
					<a href="<?php echo esc_url( ec_get_site_url( 'main' ) ); ?>/about/">About</a>
					<ul class="sub-menu">
						<li class="menu-item">
							<a href="<?php echo esc_url( ec_get_site_url( 'main' ) ); ?>/power/">The Power of Extra Chill</a>
						</li>
						<li class="menu-item">
							<a href="<?php echo esc_url( ec_get_site_url( 'docs' ) ); ?>">Documentation</a>
						</li>
						<li class="menu-item">
							<a href="<?php echo esc_url( ec_get_site_url( 'community' ) ); ?>/r/tech-support">Tech Support</a>
						</li>
						<li class="menu-item">
							<a href="<?php echo esc_url( ec_get_site_url( 'main' ) ); ?>/contact/">Contact Us</a>
						</li>
						<li class="menu-item">
							<a href="<?php echo esc_url( ec_get_site_url( 'main' ) ); ?>/about/in-the-press/">In the Press</a>
						</li>
						<li class="menu-item">
							<a href="<?php echo esc_url( ec_get_site_url( 'main' ) ); ?>/contribute">Contribute</a>
						</li>
					</ul>
				</li>
			</ul>
		</div>
	</div>
	<?php
}

add_action( 'extrachill_footer_below_menu', 'extrachill_network_footer_newsletter', 10 );

function extrachill_network_footer_newsletter() {
	?>
	<div class="footer-newsletter-below-menu">
		<?php do_action( 'extrachill_render_newsletter_form', 'navigation' ); ?>
	</div>
	<?php
}
