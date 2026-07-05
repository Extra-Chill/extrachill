<?php
/**
 * Custom User Profile Display
 *
 * Extends bbPress user profiles with cross-site data aggregation from main site.
 * Uses the canonical blog ID provider to access extrachill.com (main site) post counts and comments.
 *
 * Cross-Site Data:
 * - User post count from main site (displayed as "articles")
 * - User comments from main site blog
 * - Links to author archive: extrachill.com/author/{slug}/
 *
 * Integration: Always uses try/finally pattern with restore_current_blog() for safety.
 *
 * @package ExtraChillCommunity
 */

function display_main_site_post_count_on_profile() {
	$user_id = bbp_get_displayed_user_id();

	$main_blog_id = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'main' ) : null;
	if ( ! $main_blog_id ) {
		return;
	}

	switch_to_blog( $main_blog_id );
	try {
		$post_count = count_user_posts( $user_id, 'post', true );
	} finally {
		restore_current_blog();
	}

	if ( $post_count > 0 && function_exists( 'ec_get_user_author_archive_url' ) ) {
		$author_url = ec_get_user_author_archive_url( $user_id );
		if ( ! $author_url ) {
			return;
		}

		echo '<p><b>Extra Chill Articles:</b> ' . (int) $post_count . ' <a href="' . esc_url( $author_url ) . '">(View All)</a></p>';
	}
}

/**
 * Display the legacy "Music Fan Details" card.
 *
 * Renders the free-text favorite_artists / top_concerts / top_venues usermeta.
 * This is the pre-My-Shows manual record; it is now demoted below the real
 * Concert History card (see inc/user-profiles/concert-history.php). When the
 * profile owner has legacy top_concerts text, a nudge invites them to convert
 * it into structured tracked shows via My Shows.
 *
 * Rendered on `bbp_template_after_user_details` (priority 20) so it appears in
 * the profile body, below the Concert History card (priority 5). The previous
 * `bbp_init` hook fired during init and the echoed markup was discarded, so
 * this card never actually rendered — moving to the template hook fixes that.
 */
function display_music_fan_details() {
	$user_id = bbp_get_displayed_user_id();

	// Music Fan Section variables
	$favorite_artists = get_user_meta( $user_id, 'favorite_artists', true );
	$top_concerts     = get_user_meta( $user_id, 'top_concerts', true );
	$top_venues       = get_user_meta( $user_id, 'top_venues', true );

	if ( ! ( $favorite_artists || $top_concerts || $top_venues ) ) {
		return;
	}

	$is_own = ( (int) get_current_user_id() === (int) $user_id );
	?>
	<div class="bbp-user-profile-card ec-music-fan-details-card">
		<h3><?php esc_html_e( 'Music Fan Details', 'extra-chill-community' ); ?></h3>
		<?php if ( $favorite_artists ) : ?>
			<p><strong><?php esc_html_e( 'Favorite Artists:', 'extra-chill-community' ); ?></strong> <?php echo nl2br( esc_html( $favorite_artists ) ); ?></p>
		<?php endif; ?>

		<?php if ( $top_concerts ) : ?>
			<p><strong><?php esc_html_e( 'Top Concerts:', 'extra-chill-community' ); ?></strong> <?php echo nl2br( esc_html( $top_concerts ) ); ?></p>
		<?php endif; ?>

		<?php if ( $top_venues ) : ?>
			<p><strong><?php esc_html_e( 'Top Venues:', 'extra-chill-community' ); ?></strong> <?php echo nl2br( esc_html( $top_venues ) ); ?></p>
		<?php endif; ?>

		<?php
		// These are legacy free-text notes from before My Shows existed.
		// We deliberately do NOT promise to "convert" them: the events
		// catalog is a forward-looking calendar with almost no historic /
		// out-of-market shows, so searching for these past memories mostly
		// finds nothing. Instead, invite the owner to start tracking shows
		// going forward via My Shows — the path that actually works today.
		if ( $is_own && function_exists( 'ec_community_my_shows_url' ) ) :
			$my_shows_url = ec_community_my_shows_url( (int) $user_id );
			if ( $my_shows_url ) :
				?>
				<p class="ec-music-fan-nudge">
					<em><?php esc_html_e( 'These are your personal notes.', 'extra-chill-community' ); ?></em>
					<a href="<?php echo esc_url( $my_shows_url ); ?>"><?php esc_html_e( 'Start tracking shows you attend →', 'extra-chill-community' ); ?></a>
				</p>
				<?php
			endif;
		endif;
		?>
	</div>
	<?php
}

// Render the legacy Music Fan Details card inside the profile body, below the
// Concert History card (priority 5). Priority 20 keeps it last.
add_action( 'bbp_template_after_user_details', 'display_music_fan_details', 20 );

// Load the function after bbPress is fully loaded
add_action( 'after_setup_theme', 'override_bbp_user_role_after_bbp_load' );

function override_bbp_user_role_after_bbp_load() {
	// Hook into bbPress filter after it's available
	add_filter( 'bbp_get_user_display_role', 'override_bbp_user_forum_role', 10, 2 );
}

function override_bbp_user_forum_role( $role, $user_id ) {
	$custom_title = get_user_meta( $user_id, 'ec_custom_title', true );
	return ! empty( $custom_title ) ? $custom_title : 'Extra Chillian';
}