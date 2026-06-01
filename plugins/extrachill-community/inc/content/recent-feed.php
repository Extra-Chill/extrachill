<?php
/**
 * Recent Feed Query Functions
 *
 * Centralized database queries for the Recent Activity Feed page template.
 * Returns topics and replies in chronological order with pagination support.
 *
 * @package ExtraChillCommunity
 */

// Prevent direct access
if ( ! defined('ABSPATH') ) {
	exit;
}

if ( ! class_exists('ExtraChill_Community_Feed_Query') ) {
	/**
	 * Lightweight pagination helper mimicking WP_Query shape for template compatibility.
	 */
	class ExtraChill_Community_Feed_Query {
		/** @var int */
		public $found_posts = 0;

		/** @var int */
		public $max_num_pages = 0;

		/** @var array */
		public $query_vars = array();

		/**
		 * @param int $total_posts
		 * @param int $per_page
		 * @param int $paged
		 */
		public function __construct($total_posts, $per_page, $paged) {
			$this->found_posts   = (int) $total_posts;
			$this->max_num_pages = $per_page > 0 ? (int) ceil($this->found_posts / $per_page) : 0;
			$this->query_vars    = array(
				'posts_per_page' => (int) $per_page,
				'paged'          => (int) $paged,
			);
		}

		/**
		 * Mirror WP_Query::get() behaviour for pagination helper compatibility.
		 *
		 * @param string $key
		 * @param mixed  $default
		 *
		 * @return mixed
		 */
		public function get($key, $default_value = null) {
			if ( isset($this->query_vars[ $key ]) ) {
				return $this->query_vars[ $key ];
			}

			return $default_value;
		}
	}
}

/**
 * Get avatar URL with custom avatar support.
 *
 * Unlike get_avatar_url(), this ensures the pre_get_avatar filter is triggered
 * so custom avatars from extrachill-users plugin work correctly.
 *
 * @param int $user_id User ID
 * @param int $size Avatar size
 * @return string Avatar URL
 */
// phpcs:ignore Universal.Files.SeparateFunctionsFromOO.Mixed -- Feed query functions and their lightweight WP_Query-shaped helper class are a single cohesive unit; splitting would fragment includes without behavior benefit.
function extrachill_get_avatar_url_with_custom_support($user_id, $size = 80) {
	$avatar_html = get_avatar($user_id, $size);
	if ( preg_match('/src=["\']([^"\']+)["\']/', $avatar_html, $matches) ) {
		return $matches[1];
	}
	return get_avatar_url($user_id, array( 'size' => $size ));
}

/**
 * Build base WP_Query args for a topic+reply activity feed.
 *
 * Pass an author ID to scope the feed to a single user (profile activity feed);
 * omit it (0) for the unscoped global feed.
 *
 * @param int $per_page Items per page.
 * @param int $paged    Page number.
 * @param int $author   Optional author ID to scope the feed. 0 = unscoped/global.
 *
 * @return array WP_Query args.
 */
function extrachill_get_recent_replies_args($per_page = 15, $paged = 1, $author = 0) {
	$args = array(
		'post_type'      => array( 'topic', 'reply' ),
		'posts_per_page' => $per_page,
		'paged'          => $paged,
		'orderby'        => 'date',
		'order'          => 'DESC',
		'post_status'    => array( 'publish', 'closed', 'acf-disabled', 'private', 'hidden' ),
	);

	$author = (int) $author;
	if ( $author > 0 ) {
		$args['author'] = $author;
	}

	return $args;
}

/**
 * Run an activity-feed query and shape the results for the feed renderer.
 *
 * Shared implementation behind both the global recent feed and the user-scoped
 * profile activity feed. Pass an author ID to scope to a single user.
 *
 * @param int      $per_page Items per page.
 * @param int|null $paged    Page number; null resolves from the current request.
 * @param int      $author   Optional author ID to scope the feed. 0 = unscoped/global.
 *
 * @return array|false { items: array, pagination: ExtraChill_Community_Feed_Query } or false when empty.
 */
function extrachill_build_activity_feed($per_page = 15, $paged = null, $author = 0) {
	if ( ! function_exists('bbp_get_reply_post_type') ) {
		return false;
	}

	$per_page  = max(1, (int) $per_page);
	$bbp_paged = function_exists('bbp_get_paged') ? bbp_get_paged() : max(1, (int) get_query_var('paged', 1));
	$paged     = null === $paged ? $bbp_paged : max(1, (int) $paged);

	$args                  = extrachill_get_recent_replies_args($per_page, $paged, $author);
	$args['no_found_rows'] = false;

	$query = new WP_Query($args);

	if ( ! $query->have_posts() ) {
		return false;
	}

	$items = array();

	while ( $query->have_posts() ) {
		$query->the_post();
		$author_id = get_the_author_meta('ID');
		$post_id   = get_the_ID();
		$post_type = get_post_type();

		$topic_id = ( 'topic' === $post_type ) ? $post_id : bbp_get_reply_topic_id($post_id);
		if ( ! $topic_id ) {
			$topic_id = wp_get_post_parent_id($post_id);
		}
		$forum_id = ( 'topic' === $post_type ) ? bbp_get_topic_forum_id($post_id) : bbp_get_reply_forum_id($post_id);

		$items[] = array(
			'post'              => get_post(),
			'author_id'         => $author_id,
			'author_name'       => get_the_author(),
			'author_avatar_url' => extrachill_get_avatar_url_with_custom_support($author_id, 80),
			'topic_id'          => $topic_id,
			'topic_url'         => $topic_id ? get_permalink($topic_id) : '',
			'topic_title'       => $topic_id ? get_the_title($topic_id) : '',
			'forum_id'          => $forum_id,
			'forum_url'         => $forum_id ? get_permalink($forum_id) : '',
			'forum_title'       => $forum_id ? get_the_title($forum_id) : '',
		);
	}
	wp_reset_postdata();

	$pagination = new ExtraChill_Community_Feed_Query($query->found_posts, $per_page, $paged);

	return array(
		'items'      => $items,
		'pagination' => $pagination,
	);
}

/**
 * Global recent activity feed (unscoped).
 *
 * Owns the /recent page's data. Intentionally forum-scoped (topics + replies
 * across all forums) — NOT cross-network. See epic #53 Phase 3.
 *
 * @param int      $per_page Items per page.
 * @param int|null $paged    Page number; null resolves from the current request.
 *
 * @return array|false Feed payload or false when empty.
 */
function extrachill_get_recent_feed_query($per_page = 15, $paged = null) {
	return extrachill_build_activity_feed($per_page, $paged, 0);
}

/**
 * User-scoped profile activity feed.
 *
 * Owns a single user's profile activity feed: the same topic+reply stream as
 * the global feed, filtered to the displayed user. Replaces the former
 * near-duplicate extrachill_get_recent_activity_query() helper.
 *
 * @param int      $user_id  User to scope the feed to.
 * @param int      $per_page Items per page.
 * @param int|null $paged    Page number; null resolves from the current request.
 *
 * @return array|false Feed payload or false when empty / invalid user.
 */
function extrachill_get_user_activity_query($user_id, $per_page = 15, $paged = null) {
	$user_id = (int) $user_id;
	if ( $user_id <= 0 ) {
		return false;
	}

	return extrachill_build_activity_feed($per_page, $paged, $user_id);
}

/**
 * Render an activity feed payload as bbPress reply cards.
 *
 * Shared renderer for both the global /recent feed and the user-scoped profile
 * activity feed, so both surfaces produce identical card markup. Echoes the
 * markup directly (returns nothing).
 *
 * @param array|false $feed         Feed payload from a feed query function.
 * @param string      $empty_notice Message shown when the feed is empty.
 *
 * @return void
 */
/**
 * Whether the current reply card is being rendered inside an activity feed.
 *
 * Set by extrachill_render_recent_feed() while it loops over feed items, so the
 * shared loop-single-reply-card.php template can render its feed-specific chrome
 * (the "in forum / in reply to" header and content truncation) regardless of
 * which surface — global /recent or a user profile — is rendering the feed.
 *
 * @param bool|null $set Internal: pass true/false to toggle the flag. Reads when null.
 *
 * @return bool
 */
function extrachill_is_activity_feed_card($set = null) {
	static $active = false;

	if ( null !== $set ) {
		$active = (bool) $set;
	}

	return $active;
}

function extrachill_render_recent_feed($feed, $empty_notice = '') {
	if ( ! $feed || empty($feed['items']) ) {
		if ( '' === $empty_notice ) {
			$empty_notice = __('No recent activity found.', 'extra-chill-community');
		}
		echo '<div class="notice notice-info"><p>' . esc_html($empty_notice) . '</p></div>';
		return;
	}

	$feed_items        = $feed['items'];
	$pagination        = $feed['pagination'];
	$bbp               = bbpress();
	$previous_reply_id = isset($bbp->current_reply_id) ? $bbp->current_reply_id : 0;
	$previous_topic_id = isset($bbp->current_topic_id) ? $bbp->current_topic_id : 0;
	$previous_forum_id = isset($bbp->current_forum_id) ? $bbp->current_forum_id : 0;
	?>
	<div id="bbpress-forums" class="bbpress-wrapper">
		<ul class="forums bbp-replies">
			<li class="bbp-body">
				<?php
				global $post;
				extrachill_is_activity_feed_card(true);
				foreach ( $feed_items as $feed_item ) {
					// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Intentionally sets the global $post for setup_postdata() and the bbPress template part below.
					$post = $feed_item['post'];

					if ( ! $post || ! is_object($post) ) {
						continue;
					}

					setup_postdata($post);

					// Set pre-fetched author data for template use
					set_query_var('prefetch_author_id', $feed_item['author_id']);
					set_query_var('prefetch_author_name', $feed_item['author_name']);
					set_query_var('prefetch_author_avatar_url', $feed_item['author_avatar_url']);

					// Set pre-fetched topic/forum data for template use
					set_query_var('prefetch_topic_id', $feed_item['topic_id']);
					set_query_var('prefetch_topic_url', $feed_item['topic_url']);
					set_query_var('prefetch_topic_title', $feed_item['topic_title']);
					set_query_var('prefetch_forum_id', $feed_item['forum_id']);
					set_query_var('prefetch_forum_url', $feed_item['forum_url']);
					set_query_var('prefetch_forum_title', $feed_item['forum_title']);

					$bbp->current_reply_id = $post->ID;

					if ( bbp_get_topic_post_type() === $post->post_type ) {
						$topic_id = $post->ID;
					} else {
						$topic_id = (int) get_post_field( 'post_parent', $post->ID );
						if ( empty( $topic_id ) ) {
							$topic_id = (int) bbp_get_reply_topic_id( $post->ID );
						}
					}

					$bbp->current_topic_id = $topic_id;
					$bbp->current_forum_id = $topic_id ? bbp_get_topic_forum_id($topic_id) : 0;

					bbp_get_template_part('loop', 'single-reply-card');

					$bbp->current_reply_id = 0;
					$bbp->current_topic_id = 0;
					$bbp->current_forum_id = 0;

					wp_reset_postdata();
				}

				extrachill_is_activity_feed_card(false);
				wp_reset_postdata();
				?>
			</li>
		</ul>
		<?php extrachill_pagination($pagination, 'bbpress'); ?>
	</div>
	<?php
	$bbp->current_reply_id = $previous_reply_id;
	$bbp->current_topic_id = $previous_topic_id;
	$bbp->current_forum_id = $previous_forum_id;
}
