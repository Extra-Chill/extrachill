<?php
/**
 * Social Links Component
 *
 * Displays ExtraChill social media links with SVG sprite icons.
 *
 * @package ExtraChill
 * @since 1.0.0
 */

if ( ! function_exists( 'extrachill_social_links' ) ) :
	/**
	 * Display social media links
	 */
	function extrachill_social_links() {
		$social_links = apply_filters( 'extrachill_social_links_data', array() );

		if ( empty( $social_links ) ) {
			return;
		}

		?>
		<div class="social-links">
			<ul>
				<?php foreach ( $social_links as $social ) : ?>
					<li>
						<a href="<?php echo esc_url( $social['url'] ); ?>" target="_blank" aria-label="<?php echo esc_attr( $social['label'] ); ?>">
							<?php echo ec_icon( $social['icon'], 'social-icon-svg' ); ?>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php
	}
endif;

// Hook registration for social links
add_action( 'extrachill_social_links', 'extrachill_social_links', 10 );
