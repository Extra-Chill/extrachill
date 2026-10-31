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
	<div class="ec-edge-gutter">
		<div class="entry-content" itemprop="text">
			<?php
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- template-local; deliberately reuses the WP $current_user name inside isolated template scope.
			$current_user       = wp_get_current_user();
			$is_logged_in       = is_user_logged_in();
			$can_create_artists = ec_can_create_artist_profiles( get_current_user_id() );
			$user_artist_ids    = ec_get_artists_for_user( $current_user->ID );
			?>

			<?php if ( ! $is_logged_in ) : ?>
				<div class="artist-platform-welcome">
					<?php do_action( 'extrachill_artist_home_hero', $current_user, $is_logged_in, $can_create_artists, $user_artist_ids ); ?>

				<div class="platform-onboarding-cards">
					<div class="onboarding-card">
						<div class="onboarding-card-icon">
							<span class="dashicons dashicons-admin-links" aria-hidden="true"></span>
						</div>
						<h4><?php esc_html_e( 'Link Page', 'extrachill-artist-platform' ); ?></h4>
						<p><?php esc_html_e( 'Share your work, socials and merch with one customizable page at extrachill.link.', 'extrachill-artist-platform' ); ?></p>
					</div>

					<div class="onboarding-card">
						<div class="onboarding-card-icon"><span class="dashicons dashicons-admin-users" aria-hidden="true"></span></div>
						<h4><?php esc_html_e( 'Artist Profile', 'extrachill-artist-platform' ); ?></h4>
						<p><?php esc_html_e( 'Give fans a home for your bio, work and links on Extra Chill.', 'extrachill-artist-platform' ); ?></p>
					</div>

					<div class="onboarding-card">
						<div class="onboarding-card-icon"><span class="dashicons dashicons-calendar-alt" aria-hidden="true"></span></div>
						<h4><?php esc_html_e( 'Shows', 'extrachill-artist-platform' ); ?></h4>
						<p><?php esc_html_e( 'Display your shows from the Extra Chill Events calendar, where anyone can submit a show.', 'extrachill-artist-platform' ); ?></p>
					</div>

					<div class="onboarding-card">
						<div class="onboarding-card-icon"><span class="dashicons dashicons-email-alt" aria-hidden="true"></span></div>
						<h4><?php esc_html_e( 'Fan Subscribers', 'extrachill-artist-platform' ); ?></h4>
						<p><?php esc_html_e( 'Fans subscribe on your page so you can message them directly.', 'extrachill-artist-platform' ); ?></p>
					</div>

					<div class="onboarding-card">
						<div class="onboarding-card-icon"><span class="dashicons dashicons-cart" aria-hidden="true"></span></div>
						<h4><?php esc_html_e( 'Artist Shop', 'extrachill-artist-platform' ); ?></h4>
						<p><?php esc_html_e( 'Sell merch directly to fans and receive payouts through Stripe.', 'extrachill-artist-platform' ); ?></p>
					</div>

					<div class="onboarding-card">
						<div class="onboarding-card-icon"><span class="dashicons dashicons-megaphone" aria-hidden="true"></span></div>
						<h4><?php esc_html_e( 'Artist Dispatch', 'extrachill-artist-platform' ); ?></h4>
						<p><?php esc_html_e( 'Post updates on Extra Chill to share what you are working on.', 'extrachill-artist-platform' ); ?></p>
					</div>

					<div class="onboarding-card">
						<div class="onboarding-card-icon"><span class="dashicons dashicons-groups" aria-hidden="true"></span></div>
						<h4><?php esc_html_e( 'Team Access', 'extrachill-artist-platform' ); ?></h4>
						<p><?php esc_html_e( 'Add bandmates or a manager to help manage your presence.', 'extrachill-artist-platform' ); ?></p>
					</div>

					<div class="onboarding-card">
						<div class="onboarding-card-icon"><span class="dashicons dashicons-location" aria-hidden="true"></span></div>
						<h4><?php esc_html_e( 'Local Scene', 'extrachill-artist-platform' ); ?></h4>
						<p><?php esc_html_e( 'Show up in your city’s scene and support local artists.', 'extrachill-artist-platform' ); ?></p>
					</div>

					<div class="onboarding-card">
						<div class="onboarding-card-icon"><span class="dashicons dashicons-media-document" aria-hidden="true"></span></div>
						<h4><?php esc_html_e( 'Extra Chill Coverage', 'extrachill-artist-platform' ); ?></h4>
						<p><?php esc_html_e( 'Keep Extra Chill articles about you collected on your profile.', 'extrachill-artist-platform' ); ?></p>
					</div>

					<div class="onboarding-card">
						<div class="onboarding-card-icon">
							<span class="dashicons dashicons-chart-bar" aria-hidden="true"></span>
						</div>
						<h4><?php esc_html_e( 'Analytics', 'extrachill-artist-platform' ); ?></h4>
						<p><?php esc_html_e( 'See page visits and link clicks to learn how fans find and explore your work.', 'extrachill-artist-platform' ); ?></p>
					</div>
				</div>

				<?php $example = ec_artist_home_example(); ?>
				<?php if ( $example ) : ?>
					<section class="artist-home-example">
						<h3><?php esc_html_e( 'See it live', 'extrachill-artist-platform' ); ?></h3>
						<h4><?php echo esc_html( $example['name'] ); ?></h4>
						<p><?php esc_html_e( 'Explore a real artist profile and link page on Extra Chill.', 'extrachill-artist-platform' ); ?></p>
						<div class="welcome-actions">
							<a class="button-1 button-medium" href="<?php echo esc_url( $example['profile_url'] ); ?>"><?php esc_html_e( 'View Profile', 'extrachill-artist-platform' ); ?></a>
							<a class="button-2 button-medium" href="<?php echo esc_url( $example['link_url'] ); ?>"><?php esc_html_e( 'View Link Page', 'extrachill-artist-platform' ); ?></a>
						</div>
					</section>
				<?php endif; ?>

				<section class="artist-home-how-it-works">
					<h3><?php esc_html_e( 'How it works', 'extrachill-artist-platform' ); ?></h3>
					<ol>
						<li><?php esc_html_e( 'Create a free account.', 'extrachill-artist-platform' ); ?></li>
						<li><?php esc_html_e( 'Request artist access.', 'extrachill-artist-platform' ); ?></li>
						<li><?php esc_html_e( 'Build your profile and link page.', 'extrachill-artist-platform' ); ?></li>
					</ol>
				</section>

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
	</div>
</article><!-- .artist-platform-homepage -->
