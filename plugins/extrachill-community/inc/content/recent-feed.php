<?php
/**
 * Activity Feed
 *
 * The one activity feed builder (extrachill_build_activity_feed()), its
 * source registry (`ec_activity_feed_sources`), the forum topic + reply
 * source, and the card renderer for the /recent page. Other sources register
 * through the filter: extrachill-users registers network comments.
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
		 * @param mixed  $default_value
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
	$avatar_html = (string) get_avatar($user_id, $size);
	if ( preg_match('/src=["\']([^"\']+)["\']/', $avatar_html, $matches) ) {
		return $matches[1];
	}
	return (string) get_avatar_url($user_id, array( 'size' => $size ));
}

/**
 * Resolve the complete public identity shown by a reply/activity card.
 *
 * @param int        $post_id             Topic or reply ID.
 * @param int        $human_id            Accountable human ID.
 * @param string     $human_name          Prefetched human display name.
 * @param string     $human_avatar_html   Human avatar markup.
 * @param string     $human_url           Accountable human profile URL.
 * @param array|null $prefetched_voice    Optional prefetched canonical identity.
 * @return array<string,mixed> Card author identity.
 */
function extrachill_community_get_reply_card_author( $post_id, $human_id, $human_name, $human_avatar_html, $human_url, $prefetched_voice = null ) {
	$voice = is_array( $prefetched_voice ) ? $prefetched_voice : null;
	if ( ! $voice && function_exists( 'extrachill_community_get_post_public_voice' ) ) {
		$voice = extrachill_community_get_post_public_voice( $post_id );
	}

	if ( ! $voice ) {
		return array(
			'id'           => absint( $human_id ),
			'name'         => (string) $human_name,
			'url'          => (string) $human_url,
			'avatar_html'  => (string) $human_avatar_html,
			'public_voice' => false,
		);
	}

	$avatar_url = esc_url_raw( (string) ( $voice['avatar_url'] ?? '' ) );
	$avatar     = '' !== $avatar_url
		? '<img src="' . esc_url( $avatar_url ) . '" alt="' . esc_attr( $voice['name'] ) . '" class="avatar" width="80" height="80">'
		: '<span class="avatar bbp-public-voice-avatar" aria-hidden="true">' . esc_html( strtoupper( substr( (string) $voice['type'], 0, 1 ) ) ) . '</span>';

	return array(
		'id'           => absint( $human_id ),
		'name'         => (string) $voice['name'],
		'url'          => (string) $voice['url'],
		'avatar_html'  => $avatar,
		'public_voice' => true,
	);
}

/** Render the canonical avatar/name/URL block used by reply and activity cards. */
function extrachill_community_render_reply_card_author( array $author ) {
	?>
	<div class="bbp-author-avatar">
		<a href="<?php echo esc_url( $author['url'] ); ?>" title="<?php echo esc_attr( $author['name'] ); ?>">
			<?php echo wp_kses_post( $author['avatar_html'] ); ?>
		</a>
	</div>
	<div class="author-name-badges">
		<a href="<?php echo esc_url( $author['url'] ); ?>" class="bbp-author-name"><?php echo esc_html( $author['name'] ); ?></a>
		<div class="forum-badges"><?php do_action( 'bbp_theme_after_reply_author_details' ); ?></div>
	</div>
	<?php
}

/**
 * Default ceiling on how deep the merged activity feed paginates.
 *
 * Merging several sources by date means page N needs the newest
 * per_page * N items from every source (a source cannot know which of its
 * items survive the merge). Bounding that depth keeps a deep page from
 * pulling an unbounded prefix. Filter through `ec_activity_feed_max_depth`.
 */
const EXTRACHILL_ACTIVITY_FEED_MAX_DEPTH = 1000;

/**
 * Get the registered activity feed sources.
 *
 * The `ec_activity_feed_sources` filter is the one extension seam of the
 * activity feed, in the spirit of `ec_points_sources` and
 * `ec_contribution_events`. Each source maps an id to:
 *
 *   - `types`   (string[]) Item types the source emits (e.g. topic, reply, comment).
 *   - `query`   (callable) fn( array $query ): array{items: array[], total: int}.
 *               Returns the newest `$query['limit']` items, newest first, each
 *               carrying `type` and a UTC unix `timestamp`, plus the total the
 *               source could return for the same scope. `$query` carries
 *               `limit`, `author` (0 = everyone), `types` (requested types the
 *               source emits), `exclude_authors` (viewer blocks), `artist`
 *               (artist slug scope, '' = none) and `audience` ('viewer' or
 *               'public' for portable projections).
 *   - `hydrate` (callable, optional) fn( array $item ): array|null. Expands an
 *               item once it lands on the requested page, so sources can keep
 *               the merge pass light.
 *
 * Forum topics and replies are registered here (`forum`); network comments
 * are registered by extrachill-users (`comments`).
 *
 * @return array<string,array{types:string[],query:callable,hydrate:callable|null}>
 */
function extrachill_get_activity_feed_sources() {
	$sources = array();

	foreach ( (array) apply_filters( 'ec_activity_feed_sources', array() ) as $id => $source ) {
		if ( ! is_array( $source ) || empty( $source['types'] ) || ! isset( $source['query'] ) || ! is_callable( $source['query'] ) ) {
			continue;
		}

		$sources[ (string) $id ] = array(
			'types'   => array_values( array_map( 'strval', (array) $source['types'] ) ),
			'query'   => $source['query'],
			'hydrate' => isset( $source['hydrate'] ) && is_callable( $source['hydrate'] ) ? $source['hydrate'] : null,
		);
	}

	return $sources;
}

/**
 * Build the activity feed: every registered source, merged by date.
 *
 * The single activity feed builder. It backs the /recent page, the
 * `extrachill/community-recent-public-activity` ability (and through it the
 * network sidebar widget and the blog's community and artist cards).
 *
 * Pagination across sources: page N asks every eligible source for its
 * newest per_page * N items, merges them newest first (ties keep source
 * registration order, then source order), and slices the page. The total is
 * the sum of the source totals. Depth is capped at
 * EXTRACHILL_ACTIVITY_FEED_MAX_DEPTH items (`ec_activity_feed_max_depth`), so
 * max_num_pages never points past what can be served.
 *
 * @param int      $per_page Items per page.
 * @param int|null $paged    Page number; null resolves from the current request.
 * @param int      $author   Optional author ID to scope the feed. 0 = everyone.
 * @param array    $args {
 *     Optional. Feed scope.
 *
 *     @type string[] $types    Item types to include. Default every registered type.
 *     @type string   $artist   Artist slug scope, honoured by sources that know
 *                              artists (forum topics). Default ''.
 *     @type string   $audience 'viewer' (default, the current viewer's view) or
 *                              'public' (strictly public items for portable
 *                              projections).
 * }
 *
 * @return array|false { items: array, pagination: ExtraChill_Community_Feed_Query } or false when empty.
 */
function extrachill_build_activity_feed($per_page = 15, $paged = null, $author = 0, $args = array()) {
	$per_page = max(1, (int) $per_page);
	if ( null === $paged ) {
		$paged = function_exists('bbp_get_paged') ? bbp_get_paged() : (int) get_query_var('paged', 1);
	}
	$paged = max(1, (int) $paged);

	$sources = extrachill_get_activity_feed_sources();
	$types   = isset( $args['types'] ) ? array_map( 'strval', (array) $args['types'] ) : null;
	$depth   = max( $per_page, (int) apply_filters( 'ec_activity_feed_max_depth', EXTRACHILL_ACTIVITY_FEED_MAX_DEPTH ) );
	$limit   = min( $per_page * $paged, $depth );
	$offset  = ( $paged - 1 ) * $per_page;
	$query   = array(
		'limit'           => $limit,
		'author'          => max( 0, (int) $author ),
		'exclude_authors' => function_exists( 'extrachill_community_viewer_member_blocked_ids' ) ? extrachill_community_viewer_member_blocked_ids() : array(),
		'artist'          => isset( $args['artist'] ) ? (string) $args['artist'] : '',
		'audience'        => isset( $args['audience'] ) && 'public' === $args['audience'] ? 'public' : 'viewer',
	);

	$merged = array();
	$total  = 0;
	foreach ( $sources as $id => $source ) {
		$source_types = null === $types ? $source['types'] : array_values( array_intersect( $source['types'], $types ) );
		if ( empty( $source_types ) ) {
			continue;
		}

		$result = call_user_func( $source['query'], array_merge( $query, array( 'types' => $source_types ) ) );
		if ( ! is_array( $result ) ) {
			continue;
		}

		$total += max( 0, (int) ( $result['total'] ?? 0 ) );
		foreach ( array_slice( (array) ( $result['items'] ?? array() ), 0, $limit ) as $item ) {
			if ( ! is_array( $item ) || ! isset( $item['type'], $item['timestamp'] ) || ! in_array( (string) $item['type'], $source_types, true ) ) {
				continue;
			}
			$merged[] = array(
				'time'   => (int) $item['timestamp'],
				'seq'    => count( $merged ),
				'source' => $id,
				'item'   => $item,
			);
		}
	}

	usort(
		$merged,
		static function ( $a, $b ) {
			return $a['time'] !== $b['time'] ? $b['time'] <=> $a['time'] : $a['seq'] <=> $b['seq'];
		}
	);

	$items = array();
	foreach ( array_slice( $merged, $offset, $per_page ) as $entry ) {
		$item    = $entry['item'];
		$hydrate = $sources[ $entry['source'] ]['hydrate'];
		if ( $hydrate ) {
			$item = call_user_func( $hydrate, $item );
		}
		if ( is_array( $item ) ) {
			$item['source'] = $entry['source'];
			$items[]        = $item;
		}
	}

	if ( empty( $items ) ) {
		return false;
	}

	return array(
		'items'      => $items,
		'pagination' => new ExtraChill_Community_Feed_Query( min( $total, $depth ), $per_page, $paged ),
	);
}

/**
 * Register forum topics and replies as an activity feed source.
 *
 * Priority 5 so forum items lead ties against later sources.
 *
 * @param array $sources Source-id => source definition.
 * @return array
 */
function extrachill_community_forum_activity_feed_source( $sources ) {
	if ( ! function_exists( 'bbp_get_reply_post_type' ) ) {
		return $sources;
	}

	$sources['forum'] = array(
		'types'   => array( 'topic', 'reply' ),
		'query'   => 'extrachill_community_query_forum_activity_feed_source',
		'hydrate' => 'extrachill_community_hydrate_forum_activity_item',
	);

	return $sources;
}
add_filter( 'ec_activity_feed_sources', 'extrachill_community_forum_activity_feed_source', 5 );

/**
 * Answer an activity feed source query with recent topics and replies.
 *
 * `viewer` audience keeps the /recent statuses (private and hidden forum
 * content stays subject to the viewer's read caps through WP_Query);
 * `public` keeps public and closed content only. Artist scope filters by
 * the topic's artist terms, which only topics carry.
 *
 * @param array $query Feed source query, see extrachill_get_activity_feed_sources().
 * @return array{items:array[],total:int}
 */
function extrachill_community_query_forum_activity_feed_source( array $query ) {
	$topic_type = bbp_get_topic_post_type();
	$post_types = array();
	foreach ( (array) $query['types'] as $type ) {
		if ( 'topic' === $type || ( 'reply' === $type && '' === $query['artist'] ) ) {
			$post_types[] = 'topic' === $type ? $topic_type : bbp_get_reply_post_type();
		}
	}

	if ( empty( $post_types ) ) {
		return array(
			'items' => array(),
			'total' => 0,
		);
	}

	$args = array(
		'post_type'           => 1 === count( $post_types ) ? $post_types[0] : $post_types,
		'post_status'         => 'public' === $query['audience']
			? array( bbp_get_public_status_id(), bbp_get_closed_status_id() )
			: array( 'publish', 'closed', 'acf-disabled', 'private', 'hidden' ),
		'posts_per_page'      => max( 1, (int) $query['limit'] ),
		'orderby'             => 'date',
		'order'               => 'DESC',
		'ignore_sticky_posts' => true,
	);

	if ( $query['author'] > 0 ) {
		$args['author'] = (int) $query['author'];
	}
	if ( ! empty( $query['exclude_authors'] ) ) {
		$args['author__not_in'] = array_map( 'intval', (array) $query['exclude_authors'] );
	}
	if ( '' !== $query['artist'] ) {
		$args['tax_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Explicit bounded relationship filter.
			array(
				'taxonomy' => 'artist',
				'field'    => 'slug',
				'terms'    => $query['artist'],
			),
		);
	}

	$wp_query = new WP_Query( $args );
	$items    = array();
	foreach ( (array) $wp_query->posts as $post ) {
		$post = $post instanceof WP_Post ? $post : get_post( $post );
		if ( ! $post instanceof WP_Post ) {
			continue;
		}
		$date      = $post->post_date_gmt && '0000-00-00 00:00:00' !== $post->post_date_gmt ? $post->post_date_gmt . ' UTC' : $post->post_date;
		$timestamp = strtotime( $date );
		if ( false === $timestamp ) {
			continue;
		}
		$items[] = array(
			'type'      => $topic_type === $post->post_type ? 'topic' : 'reply',
			'timestamp' => $timestamp,
			'author_id' => (int) $post->post_author,
			'post'      => $post,
		);
	}

	return array(
		'items' => $items,
		'total' => (int) $wp_query->found_posts,
	);
}

/**
 * Expand a forum feed item with the card data its renderer pre-fetches.
 *
 * @param array $item Forum feed item.
 * @return array
 */
function extrachill_community_hydrate_forum_activity_item( array $item ) {
	$post      = $item['post'];
	$post_id   = (int) $post->ID;
	$author_id = (int) $post->post_author;
	$is_topic  = bbp_get_topic_post_type() === $post->post_type;

	$topic_id = $is_topic ? $post_id : (int) bbp_get_reply_topic_id( $post_id );
	if ( ! $topic_id ) {
		$topic_id = (int) wp_get_post_parent_id( $post_id );
	}
	$forum_id = $is_topic ? (int) bbp_get_topic_forum_id( $post_id ) : (int) bbp_get_reply_forum_id( $post_id );

	return array_merge(
		$item,
		array(
			'author_id'         => $author_id,
			'author_name'       => get_the_author_meta( 'display_name', $author_id ),
			'author_avatar_url' => extrachill_get_avatar_url_with_custom_support( $author_id, 80 ),
			'public_voice'      => function_exists( 'extrachill_community_get_post_public_voice' ) ? extrachill_community_get_post_public_voice( $post_id ) : null,
			'topic_id'          => $topic_id,
			'topic_url'         => $topic_id ? get_permalink( $topic_id ) : '',
			'topic_title'       => $topic_id ? get_the_title( $topic_id ) : '',
			'forum_id'          => $forum_id,
			'forum_url'         => $forum_id ? get_permalink( $forum_id ) : '',
			'forum_title'       => $forum_id ? get_the_title( $forum_id ) : '',
		)
	);
}

/**
 * Global recent activity feed (unscoped).
 *
 * Owns the /recent page's data: forum topics and replies plus every other
 * registered source (network comments on articles and events), merged by
 * date across the network.
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
 * Map a comment feed item onto the existing blog comment card's data.
 *
 * @param array $feed_item Comment feed item (`comment` row from extrachill-users).
 * @return array Data for bbpress/loop-single-blog-comment-card.php.
 */
function extrachill_community_activity_comment_card_data( array $feed_item ) {
	$comment   = (array) ( $feed_item['comment'] ?? array() );
	$author_id = (int) ( $comment['user_id'] ?? 0 );

	return array(
		'comment_ID'        => (int) ( $comment['comment_id'] ?? 0 ),
		'post_title'        => (string) ( $comment['post_title'] ?? '' ),
		'post_permalink'    => (string) ( $comment['post_url'] ?? '' ),
		'comment_permalink' => (string) ( $comment['comment_url'] ?? '' ),
		'comment_date_gmt'  => (string) ( $comment['date_gmt'] ?? '' ),
		'comment_content'   => (string) ( $comment['content'] ?? '' ),
		'author_id'         => $author_id,
		'author_name'       => (string) ( $comment['author_name'] ?? '' ),
		'author_avatar'     => extrachill_get_avatar_url_with_custom_support( $author_id, 80 ),
		'upvote_count'      => 0,
	);
}

/**
 * Whether the current reply card is being rendered inside an activity feed.
 *
 * Set by extrachill_render_recent_feed() while it loops over feed items, and by
 * the profile conversations list, so the shared card templates render their
 * feed-specific chrome (the "in forum / in reply to" header and content
 * truncation).
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

/**
 * Render an activity feed payload as cards.
 *
 * Forum items render through the bbPress reply card; comment items render
 * through the existing blog comment card (the /blog-comments partial).
 * Echoes the markup directly (returns nothing).
 *
 * @param array|false $feed         Feed payload from extrachill_build_activity_feed().
 * @param string      $empty_notice Message shown when the feed is empty.
 *
 * @return void
 */
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
					if ( 'comment' === ( $feed_item['type'] ?? '' ) ) {
						set_query_var( 'comment_data', extrachill_community_activity_comment_card_data( $feed_item ) );
						bbp_get_template_part( 'loop', 'single-blog-comment-card' );
						set_query_var( 'comment_data', null );
						continue;
					}

					// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Intentionally sets the global $post for setup_postdata() and the bbPress template part below.
					$post = $feed_item['post'] ?? null;

					if ( ! $post instanceof WP_Post ) {
						continue;
					}

					setup_postdata($post);

					// Set pre-fetched author data for template use
					set_query_var('prefetch_author_id', $feed_item['author_id']);
					set_query_var('prefetch_author_name', $feed_item['author_name']);
					set_query_var('prefetch_author_avatar_url', $feed_item['author_avatar_url']);
					set_query_var('prefetch_public_voice', $feed_item['public_voice'] ?? null);

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
