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
 * @property object|null  $reply_query      Active reply query state.
 * @property object|null  $topic_query      Active topic query state.
 * @property object|null  $forum_query      Active forum query state.
 */
class bbPress {} // phpcs:ignore
