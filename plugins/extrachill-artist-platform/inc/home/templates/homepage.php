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

						<?php
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

						$smart_manage_url = $latest_artist_id > 0 ?
							add_query_arg( 'artist_id', $latest_artist_id, home_url( '/manage-artist-profiles/' ) ) :
							home_url( '/manage-artist-profiles/' );
						?>

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
