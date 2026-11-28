<?php
/**
 * PHPStan stubs for bbPress runtime state.
 *
 * bbPress stores most of its runtime state in a private `$data` array exposed
 * through `__get()`/`__set()` magic methods, and ships no `@property`
 * annotations. Static analysis therefore reports `property.notFound` for the
 * loop context properties bbPress itself assigns in `bbPress::setup_globals()`.
 *
 * These declarations document the properties bbPress genuinely provides so the
 * analyzer can verify template code that reads and restores loop context. They
 * are analysis-only and are never loaded at runtime.
 *
 * @see bbPress::setup_globals()
 *
 * @package ExtraChillCommunity\Tests
 */

/**
 * Main bbPress class runtime state.
 *
 * @property int          $current_forum_id Current forum ID within a loop.
 * @property int          $current_topic_id Current topic ID within a loop.
 * @property int          $current_reply_id Current reply ID within a loop.
 * @property \WP_User     $current_user     Currently logged in user.
 * @property \WP_Query|null $reply_query    Active reply query (bbPress::setup_globals() seeds a WP_Query).
 * @property \WP_Query|null $topic_query    Active topic query (bbPress::setup_globals() seeds a WP_Query).
 * @property \WP_Query|null $forum_query    Active forum query (bbPress::setup_globals() seeds a WP_Query).
 */
class bbPress {} // phpcs:ignore

/*
 * Function signature corrections.
 *
 * bbPress documents several template loop functions as returning `object`
 * while their bodies return `WP_Query::have_posts()` (a bool), and documents a
 * few parameters narrower than the values the functions accept. The analyzer
 * trusts those docblocks, so it reports every `if ( bbp_has_topics() )` and
 * `while ( bbp_topics() )` as always true. The declarations below restate the
 * real signatures; they are analysis-only and never loaded at runtime.
 */

/**
 * @see bbp_has_forums() Returns `$bbp->forum_query->have_posts()` through the `bbp_has_forums` filter.
 * @param array|string $args Query arguments.
 * @return bool
 */
function bbp_has_forums( $args = array() ) {} // phpcs:ignore

/**
 * @see bbp_forums() Returns `$bbp->forum_query->have_posts()`.
 * @return bool
 */
function bbp_forums() {} // phpcs:ignore

/**
 * @see bbp_has_topics() Returns `$bbp->topic_query->have_posts()` through the `bbp_has_topics` filter.
 * @param array|string $args Query arguments.
 * @return bool
 */
function bbp_has_topics( $args = array() ) {} // phpcs:ignore

/**
 * @see bbp_topics() Returns `$bbp->topic_query->have_posts()`.
 * @return bool
 */
function bbp_topics() {} // phpcs:ignore

/**
 * @see bbp_replies() Returns `$bbp->reply_query->have_posts()`.
 * @return bool
 */
function bbp_replies() {} // phpcs:ignore

/**
 * @see bbp_get_reply_author_id() Returns `(int) apply_filters( 'bbp_get_reply_author_id', ... )`.
 * @param int $reply_id Optional reply ID.
 * @return int
 */
function bbp_get_reply_author_id( $reply_id = 0 ) {} // phpcs:ignore

/**
 * @see bbp_get_topic_author_id() Returns `(int) apply_filters( 'bbp_get_topic_author_id', ... )`.
 * @param int $topic_id Optional topic ID.
 * @return int
 */
function bbp_get_topic_author_id( $topic_id = 0 ) {} // phpcs:ignore

/**
 * @see bbp_get_author_ip() Treats a numeric `$args` as the post ID.
 * @param array|int $args Arguments array, or a post ID.
 * @return void
 */
function bbp_author_ip( $args = array() ) {} // phpcs:ignore

/**
 * @see bbp_stick_topic() Defaults `$super` to false and reads it with empty().
 * @param int      $topic_id Topic ID.
 * @param bool|int $super    Whether to make the topic super sticky.
 * @return bool
 */
function bbp_stick_topic( $topic_id = 0, $super = false ) {} // phpcs:ignore
