<?php
/**
 * Artist Platform Homepage Template
 *
 * Homepage template for artist.extrachill.com (site #4 in multisite network).
 * Displays dashboard for logged-in artists or welcome screen for visitors.
 *
 * @package ExtraChillArtistPlatform
 */

get_header();

extrachill_breadcrumbs();
?>

<article class="artist-platform-homepage">
	<div class="inside-article">
		<header class="entry-header">
			<h1 class="entry-title page-title"><?php esc_html_e( 'Artist Platform', 'extrachill-artist-platform' ); ?></h1>
		</header><!-- .entry-header -->

		<div class="entry-content" itemprop="text">
			<?php
			// Initialize user data
			$current_user     = wp_get_current_user();
			$is_logged_in     = is_user_logged_in();
			$can_create_artists = ec_can_create_artist_profiles( get_current_user_id() );
			$user_artist_ids  = ec_get_artists_for_user( $current_user->ID );
			?>

				<?php if ( ! $is_logged_in ) : ?>
					<!-- Not Logged In Section -->
					<div class="artist-platform-welcome">
						<?php do_action( 'extrachill_artist_home_hero', $current_user, $is_logged_in, $can_create_artists, $user_artist_ids ); ?>

						<div class="featured-artists-section">
							<h3><?php esc_html_e( 'Active Artists', 'extrachill-artist-platform' ); ?></h3>
							<?php ec_display_artist_cards_grid( 24, false ); ?>
						</div>
					</div>

				<?php elseif ( empty( $user_artist_ids ) ) : ?>
					<!-- Logged In, No Artists -->
					<div class="artist-platform-getting-started">
						<?php do_action( 'extrachill_artist_home_hero', $current_user, $is_logged_in, $can_create_artists, $user_artist_ids ); ?>

						<?php if ( $can_create_artists ) : ?>
							<div class="getting-started-actions">
								<div class="primary-action-card">
									<h3><?php esc_html_e( 'Create Your First Artist Profile', 'extrachill-artist-platform' ); ?></h3>
									<p><?php esc_html_e( 'Start by creating your artist profile. You can always add more details later.', 'extrachill-artist-platform' ); ?></p>
									<a href="<?php echo esc_url( home_url( '/manage-artist-profiles/' ) ); ?>" class="button-1 button-large" data-action-button>
										<?php esc_html_e( 'Create Artist Profile', 'extrachill-artist-platform' ); ?>
									</a>
								</div>
							</div>
						<?php else : ?>
							<div class="notice notice-info">
								<p><?php esc_html_e( 'To create artist profiles, you need artist or professional membership. Contact us to upgrade your account.', 'extrachill-artist-platform' ); ?></p>
							</div>
						<?php endif; ?>

						<div class="featured-artists-section">
							<h3><?php esc_html_e( 'Discover Artists', 'extrachill-artist-platform' ); ?></h3>
							<?php ec_display_artist_cards_grid( 24, false ); ?>
						</div>
					</div>

				<?php else : ?>
					<!-- Logged In, Has Artists - Dashboard -->
					<div class="artist-platform-dashboard">
						<?php do_action( 'extrachill_artist_home_hero', $current_user, $is_logged_in, $can_create_artists, $user_artist_ids ); ?>

						<?php
						// Find most recently modified artist profile (same logic as avatar menu)
						$latest_artist_id             = 0;
						$latest_modified_timestamp = 0;

						foreach ( $user_artist_ids as $artist_id ) {
							$artist_id_int = absint( $artist_id );
							if ( $artist_id_int > 0 ) {
								$post_modified_gmt = get_post_field( 'post_modified_gmt', $artist_id_int, 'raw' );
								if ( $post_modified_gmt ) {
									$current_timestamp = strtotime( $post_modified_gmt );
									if ( $current_timestamp > $latest_modified_timestamp ) {
										$latest_modified_timestamp = $current_timestamp;
										$latest_artist_id          = $artist_id_int;
									}
								}
							}
						}

						// Set smart management URLs
						$smart_manage_url = $latest_artist_id > 0 ?
							add_query_arg( 'artist_id', $latest_artist_id, home_url( '/manage-artist-profiles/' ) ) :
							home_url( '/manage-artist-profiles/' );
						?>

						<?php do_action( 'extrachill_above_artist_grid', $user_artist_ids ); ?>

						<!-- Featured Artists from Community -->
						<div class="featured-artists-section">
							<h3><?php esc_html_e( 'Discover Other Artists', 'extrachill-artist-platform' ); ?></h3>
							<?php ec_display_artist_cards_grid( 24, true ); ?>
						</div>
					</div>
			<?php endif; ?>

		</div><!-- .entry-content -->
	</div><!-- .inside-article -->
</article><!-- .artist-platform-homepage -->

<?php get_footer(); ?>
