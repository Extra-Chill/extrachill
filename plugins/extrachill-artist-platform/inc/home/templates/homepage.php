<?php
/**
 * Artist Platform Homepage Template
 *
 * Homepage template for artist.extrachill.com (site #6 in multisite network).
 * Displays dashboard for logged-in artists or welcome screen for visitors.
 *
 * @package ExtraChillArtistPlatform
 */

get_header(); ?>

<div class="breadcrumb-notice-container">
	<?php
	// Add breadcrumbs
	if ( function_exists( 'extrachill_breadcrumbs' ) ) {
		extrachill_breadcrumbs();
	}
	?>
</div>

<?php while ( have_posts() ) : the_post(); ?>
	<article id="post-<?php the_ID(); ?>" <?php post_class(); ?>>
		<div class="inside-article">
			<header class="entry-header">
				<h1 class="entry-title page-title"><?php the_title(); ?></h1>
			</header><!-- .entry-header -->

			<div class="entry-content" itemprop="text">
				<?php
				// Initialize user data
				$current_user     = wp_get_current_user();
				$is_logged_in     = is_user_logged_in();
				$can_create_artists = ec_can_create_artist_profiles( get_current_user_id() );
				$user_artist_ids  = ec_get_user_owned_artists( $current_user->ID );
				?>

				<?php if ( ! $is_logged_in ) : ?>
					<!-- Not Logged In Section -->
					<div class="artist-platform-welcome">
						<div class="welcome-hero">
							<h2><?php esc_html_e( 'Welcome to the Artist Platform', 'extrachill-artist-platform' ); ?></h2>
							<p><?php esc_html_e( 'Create your artist profile, build a custom link page, connect with fans, and manage your music career all in one place.', 'extrachill-artist-platform' ); ?></p>

							<div class="welcome-actions">
								<a href="<?php echo esc_url( wp_login_url( get_permalink() ) ); ?>" class="button button-primary">
									<?php esc_html_e( 'Log In', 'extrachill-artist-platform' ); ?>
								</a>
								<a href="<?php echo esc_url( home_url( '/login/#tab-register' ) ); ?>" class="button">
									<?php esc_html_e( 'Sign Up', 'extrachill-artist-platform' ); ?>
								</a>
							</div>
						</div>

						<div class="featured-artists-section">
							<h3><?php esc_html_e( 'Active Artists', 'extrachill-artist-platform' ); ?></h3>
							<?php ec_display_artist_cards_grid( 6, false ); // Show 6 artists, don't exclude any ?>

							<div class="view-all-artists">
								<a href="<?php echo esc_url( get_post_type_archive_link( 'artist_profile' ) ); ?>" class="button">
									<?php esc_html_e( 'View All Artists', 'extrachill-artist-platform' ); ?>
								</a>
							</div>
						</div>
					</div>

				<?php elseif ( empty( $user_artist_ids ) ) : ?>
					<!-- Logged In, No Artists -->
					<div class="artist-platform-getting-started">
						<div class="welcome-user">
							<h2><?php printf( esc_html__( 'Welcome, %s!', 'extrachill-artist-platform' ), esc_html( $current_user->display_name ) ); ?></h2>
							<p><?php esc_html_e( 'Ready to get started with your artist journey? Create your first artist profile to unlock all platform features.', 'extrachill-artist-platform' ); ?></p>
						</div>

						<?php if ( $can_create_artists ) : ?>
							<div class="getting-started-actions">
								<div class="primary-action-card">
									<h3><?php esc_html_e( 'Create Your First Artist Profile', 'extrachill-artist-platform' ); ?></h3>
									<p><?php esc_html_e( 'Start by creating your artist profile. You can always add more details later.', 'extrachill-artist-platform' ); ?></p>
									<a href="<?php echo esc_url( home_url( '/manage-artist-profiles/' ) ); ?>" class="button button-primary button-large">
										<?php esc_html_e( 'Create Artist Profile', 'extrachill-artist-platform' ); ?>
									</a>
								</div>
							</div>
						<?php else : ?>
							<div class="bp-notice bp-notice-info">
								<p><?php esc_html_e( 'To create artist profiles, you need artist or professional membership. Contact us to upgrade your account.', 'extrachill-artist-platform' ); ?></p>
							</div>
						<?php endif; ?>

						<div class="featured-artists-section">
							<h3><?php esc_html_e( 'Discover Artists', 'extrachill-artist-platform' ); ?></h3>
							<?php ec_display_artist_cards_grid( 8, false ); // Show 8 artists, don't exclude any ?>

							<div class="view-all-artists">
								<a href="<?php echo esc_url( get_post_type_archive_link( 'artist_profile' ) ); ?>" class="button">
									<?php esc_html_e( 'View All Artists', 'extrachill-artist-platform' ); ?>
								</a>
							</div>
						</div>
					</div>

				<?php else : ?>
					<!-- Logged In, Has Artists - Dashboard -->
					<div class="artist-platform-dashboard">
						<div class="dashboard-welcome">
							<h2><?php printf( esc_html__( 'Welcome back, %s!', 'extrachill-artist-platform' ), esc_html( $current_user->display_name ) ); ?></h2>
							<p><?php printf( esc_html( _n( 'Manage your artist profile and platform features below.', 'Manage your %d artist profiles and platform features below.', count( $user_artist_ids ), 'extrachill-artist-platform' ) ), count( $user_artist_ids ) ); ?></p>
						</div>

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

						<!-- Quick Actions -->
						<div class="quick-actions-section">
							<h3><?php esc_html_e( 'Quick Actions', 'extrachill-artist-platform' ); ?></h3>
							<div class="quick-actions-grid">
								<?php if ( $can_create_artists ) : ?>
									<div class="action-card">
										<h4><?php esc_html_e( 'Create New Artist Profile', 'extrachill-artist-platform' ); ?></h4>
										<p><?php esc_html_e( 'Add another artist to your portfolio', 'extrachill-artist-platform' ); ?></p>
										<a href="<?php echo esc_url( $smart_manage_url ); ?>" class="button button-primary">
											<?php esc_html_e( 'Create New', 'extrachill-artist-platform' ); ?>
										</a>
									</div>
								<?php endif; ?>

								<div class="action-card">
									<h4><?php esc_html_e( 'Browse Artist Directory', 'extrachill-artist-platform' ); ?></h4>
									<p><?php esc_html_e( 'Discover other artists in the community', 'extrachill-artist-platform' ); ?></p>
									<a href="<?php echo esc_url( get_post_type_archive_link( 'artist_profile' ) ); ?>" class="button">
										<?php esc_html_e( 'Explore', 'extrachill-artist-platform' ); ?>
									</a>
								</div>
							</div>
						</div>

						<!-- Artist Profiles -->
						<div class="artist-profiles-section">
							<h3><?php esc_html_e( 'Your Artist Profiles', 'extrachill-artist-platform' ); ?></h3>
							<div class="artist-cards-grid">
								<?php
								foreach ( $user_artist_ids as $artist_id ) :
									echo ec_render_template(
										'artist-profile-card',
										array(
											'artist_id' => $artist_id,
											'context'   => 'dashboard',
										)
									);
								endforeach;
								?>
							</div>
						</div>

						<!-- Featured Artists from Community -->
						<div class="featured-artists-section">
							<h3><?php esc_html_e( 'Discover Other Artists', 'extrachill-artist-platform' ); ?></h3>
							<?php ec_display_artist_cards_grid( 12, true ); // Show 12 artists, exclude user's own ?>

							<div class="view-all-artists">
								<a href="<?php echo esc_url( get_post_type_archive_link( 'artist_profile' ) ); ?>" class="button">
									<?php esc_html_e( 'View All Artists', 'extrachill-artist-platform' ); ?>
								</a>
							</div>
						</div>
					</div>
				<?php endif; ?>

			</div><!-- .entry-content -->
		</div><!-- .inside-article -->
	</article><!-- #post-## -->
<?php endwhile; ?>

<?php get_footer(); ?>
