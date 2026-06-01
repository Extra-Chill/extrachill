<?php
/**
 * Browse Rooms Chip Row
 *
 * Demoted forum navigation for the feed-first homepage. Replaces the
 * 2010-era [bbp-forum-index] directory table with a compact row of room
 * chips. For a low-volume community a directory table advertises emptiness;
 * a chip row keeps browsing available without leading with it.
 *
 * Rooms are pulled DYNAMICALLY from public top-level forums — no hardcoded
 * forum IDs. The consolidated public room set (Music Discussion, Live Shows &
 * Scenes, Artist Corner, The Lab, The Back Bar) are the published, top-level
 * forums; artist sub-forums are children (non-zero parent) and the staff forum
 * is `hidden`, so both are excluded.
 *
 * Visibility note (the actual bug): bbPress registers `hidden` and `private`
 * as forum post statuses, and hooks `pre_get_posts`
 * (bbp_pre_get_posts_normalize_forum_visibility) to OVERWRITE the queried
 * `post_status` with [public, hidden, private] for any viewer who can
 * `read_hidden_forums` / `read_private_forums`. So a `post_status => publish`
 * query silently returns hidden/private forums for logged-in admins/team —
 * which is exactly how the hidden staff forum leaked onto the homepage chips.
 *
 * Passing the public status does not help (bbPress overwrites it post-hook).
 * The robust, viewer-independent fix is to filter the returned posts down to
 * the genuine public status afterwards, so hidden/private forums never appear
 * regardless of who is viewing. We also drop any IDs bbPress tracks in the
 * `_bbp_hidden_forums` / `_bbp_private_forums` options as a secondary guard.
 *
 * Loaded via the extrachill_community_home_after_feed action hook
 * (registered in inc/home/actions.php).
 *
 * @package ExtraChillCommunity
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'extrachill_community_get_room_chips' ) ) {
	/**
	 * Fetch the room forums for the homepage chip row.
	 *
	 * Published, top-level forums ordered by the bbPress menu order so the
	 * room ordering matches the rest of the platform. Filterable for later
	 * phases without touching the template.
	 *
	 * @return WP_Post[]
	 */
	function extrachill_community_get_room_chips() {
		$public_status = 'publish';
		if ( function_exists( 'bbp_get_public_status_id' ) ) {
			$public_status = bbp_get_public_status_id();
		}

		// Secondary guard: forum IDs bbPress flags as hidden/private in its
		// options. (Not sufficient alone — a forum can be hidden purely by its
		// post_status without appearing in these options — but cheap to apply.)
		$exclude = array();

		if ( function_exists( 'bbp_get_hidden_forum_ids' ) ) {
			$exclude = array_merge( $exclude, bbp_get_hidden_forum_ids() );
		}

		if ( function_exists( 'bbp_get_private_forum_ids' ) ) {
			$exclude = array_merge( $exclude, bbp_get_private_forum_ids() );
		}

		$exclude = array_values( array_unique( array_map( 'absint', $exclude ) ) );

		$query_args = array(
			'post_type'              => bbp_get_forum_post_type(),
			'post_status'            => $public_status,
			'post_parent'            => 0,
			'posts_per_page'         => -1,
			'orderby'                => array(
				'menu_order' => 'ASC',
				'title'      => 'ASC',
			),
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
			'update_post_meta_cache' => false,
		);

		if ( ! empty( $exclude ) ) {
			$query_args['post__not_in'] = $exclude;
		}

		$found = get_posts( $query_args );

		// Primary guard: bbPress overwrites the queried post_status with
		// [public, hidden, private] via pre_get_posts for viewers who can read
		// hidden/private forums, so the query above can still return hidden or
		// private forums to logged-in admins/team. Keep only the genuine public
		// status so non-public forums never render as chips, regardless of the
		// current viewer's capabilities.
		$rooms = array_filter(
			$found,
			static function ( $room ) use ( $public_status ) {
				$room_status = get_post_status( $room );
				return $public_status === $room_status;
			}
		);
		$rooms = array_values( $rooms );

		/**
		 * Filter the room forums shown as homepage chips.
		 *
		 * @param WP_Post[] $rooms Public top-level forum posts.
		 */
		return apply_filters( 'extrachill_community_room_chips', $rooms );
	}

	/**
	 * Render the Browse rooms chip row.
	 *
	 * Hooked to extrachill_community_home_after_feed.
	 */
	function extrachill_community_render_browse_rooms() {
		if ( ! function_exists( 'bbp_get_forum_post_type' ) ) {
			return;
		}

		$rooms = extrachill_community_get_room_chips();

		if ( empty( $rooms ) ) {
			return;
		}
		?>
		<nav class="community-browse-rooms" aria-labelledby="community-browse-rooms-heading">
			<div class="community-section-header">
				<h2 id="community-browse-rooms-heading"><?php esc_html_e( 'Browse rooms', 'extra-chill-community' ); ?></h2>
			</div>
			<div class="taxonomy-badges community-room-chips ec-mobile-full-width-panel">
				<?php foreach ( $rooms as $room ) : ?>
					<a href="<?php echo esc_url( get_permalink( $room ) ); ?>" class="taxonomy-badge">
						<?php echo esc_html( get_the_title( $room ) ); ?>
					</a>
				<?php endforeach; ?>
			</div>
		</nav>
		<?php
	}
}
