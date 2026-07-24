<?php
/**
 * PHPStan purity corrections for WordPress stateful reads.
 */

/** @phpstan-impure */
function metadata_exists( string $meta_type, int $object_id, string $meta_key ): bool {}

/** @phpstan-impure */
function get_userdata( int $user_id ): WP_User|false {}

/** @phpstan-impure */
function is_user_member_of_blog( int $user_id = 0, int $blog_id = 0 ): bool {}

/** @phpstan-impure */
function ec_users_is_event_marked( int $user_id, int $event_id, int $blog_id = 0 ): bool {}
