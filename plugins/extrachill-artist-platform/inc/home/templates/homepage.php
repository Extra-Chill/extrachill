<?php
/**
 * Artist Platform Homepage Content
 *
 * Homepage content for artist.extrachill.com.
 * Hooked via extrachill_homepage_content action.
 *
 * @package ExtraChillArtistPlatform
 */

extrachill_breadcrumbs();
?>

<article class="artist-platform-homepage">
	<div class="inside-article">
		<div class="entry-content" itemprop="text">
			<?php
			$current_user     = wp_get_current_user();
			$is_logged_in     = is_user_logged_in();
			$can_create_artists = ec_can_create_artist_profiles( get_current_user_id() );
			$user_artist_ids  = ec_get_artists_for_user( $current_user->ID );
			?>

			<?php if ( ! $is_logged_in ) : ?>
				<div class="artist-platform-welcome">
					<?php do_action( 'extrachill_artist_home_hero', $current_user, $is_logged_in, $can_create_artists, $user_artist_ids ); ?>

				<div class="platform-onboarding-cards">
					<div class="onboarding-card">
						<div class="onboarding-card-icon">
							<span class="dashicons dashicons-admin-links"></span>
						</div>
						<h4><?php esc_html_e( 'Link Page', 'extrachill-artist-platform' ); ?></h4>
						<p><?php esc_html_e( 'Get a beautiful, customizable link page at extrachill.link to share your music, socials, and merch.', 'extrachill-artist-platform' ); ?></p>
					</div>

					<div class="onboarding-card">
						<div class="onboarding-card-icon">
							<span class="dashicons dashicons-cart"></span>
						</div>
						<h4><?php esc_html_e( 'Artist Shop', 'extrachill-artist-platform' ); ?></h4>
						<p><?php esc_html_e( 'Sell your merchandise directly to fans with a dedicated artist shop and integrated payments.', 'extrachill-artist-platform' ); ?></p>
						<span class="coming-soon-badge"><?php esc_html_e( 'Coming Soon', 'extrachill-artist-platform' ); ?></span>
					</div>

					<div class="onboarding-card">
						<div class="onboarding-card-icon">
							<span class="dashicons dashicons-chart-bar"></span>
						</div>
						<h4><?php esc_html_e( 'Analytics', 'extrachill-artist-platform' ); ?></h4>
						<p><?php esc_html_e( 'Track visits, link clicks, and fan engagement across your profile with real-time insights.', 'extrachill-artist-platform' ); ?></p>
					</div>
				</div>

				<div class="featured-artists-section">
						<h3><?php esc_html_e( 'Active Artists', 'extrachill-artist-platform' ); ?></h3>
						<?php ec_display_artist_cards_grid( 12, false, false ); ?>
						<div class="browse-all-artists">
							<a href="<?php echo esc_url( home_url( '/artists/' ) ); ?>" class="button-2 button-medium">
								<?php esc_html_e( 'Browse All Artists', 'extrachill-artist-platform' ); ?>
							</a>
						</div>
					</div>
					</div>

				<?php elseif ( empty( $user_artist_ids ) ) : ?>
					<div class="artist-platform-getting-started">
						<?php do_action( 'extrachill_artist_home_hero', $current_user, $is_logged_in, $can_create_artists, $user_artist_ids ); ?>

					<div class="featured-artists-section">
						<h3><?php esc_html_e( 'Discover Artists', 'extrachill-artist-platform' ); ?></h3>
						<?php ec_display_artist_cards_grid( 12, false, false ); ?>
						<div class="browse-all-artists">
							<a href="<?php echo esc_url( home_url( '/artists/' ) ); ?>" class="button-2 button-medium">
								<?php esc_html_e( 'Browse All Artists', 'extrachill-artist-platform' ); ?>
							</a>
						</div>
					</div>
					</div>

				<?php else : ?>
					<div class="artist-platform-dashboard">
						<?php do_action( 'extrachill_artist_home_hero', $current_user, $is_logged_in, $can_create_artists, $user_artist_ids ); ?>

					<?php do_action( 'extrachill_above_artist_grid', $user_artist_ids ); ?>
					<div class="featured-artists-section">
						<h3><?php esc_html_e( 'Discover Other Artists', 'extrachill-artist-platform' ); ?></h3>
						<?php ec_display_artist_cards_grid( 12, true, false ); ?>
						<div class="browse-all-artists">
							<a href="<?php echo esc_url( home_url( '/artists/' ) ); ?>" class="button-2 button-medium">
								<?php esc_html_e( 'Browse All Artists', 'extrachill-artist-platform' ); ?>
							</a>
						</div>
					</div>
					</div>
			<?php endif; ?>

		</div><!-- .entry-content -->
	</div><!-- .inside-article -->
</article><!-- .artist-platform-homepage -->
