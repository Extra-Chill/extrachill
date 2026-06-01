<?php
/**
 * Artist Profile <-> Artist Term Binding ("the hub join")
 *
 * An artist exists as BOTH an `artist_profile` CPT (on the artist blog) AND an
 * `artist` taxonomy term (on the MAIN blog, attached to `post`). Historically
 * the two were joined ONLY by matching slug (see extrachill-artist-platform#60),
 * which silently breaks when either side is renamed.
 *
 * This module establishes a stored, bidirectional, ID-based binding plus
 * resolvers that prefer the stored binding and fall back to slug-matching,
 * self-healing the stored binding on a successful fallback so the next call is
 * O(1).
 *
 *   - `_artist_term_id`    POST meta on the artist_profile  -> main-blog term_id
 *   - `_artist_profile_id` TERM meta on the artist term      -> artist-blog post ID
 *
 * The `artist` term lives on the MAIN blog; the profile lives on the ARTIST
 * blog. Every cross-blog hop pairs switch_to_blog() with restore_current_blog()
 * in a try/finally so the blog context can never leak.
 *
 * @package ExtraChillArtistPlatform
 */

defined( 'ABSPATH' ) || exit;

/**
 * Resolve the main-blog `artist` term_id bound to an artist_profile.
 *
 * Reads the stored `_artist_term_id` binding first. If absent, falls back to
 * matching the profile's slug against an `artist` term on the MAIN blog and,
 * on success, writes BOTH meta sides so the next lookup is O(1).
 *
 * @param int $profile_id Artist profile post ID (on the artist blog).
 * @return int Main-blog `artist` term_id, or 0 if none can be resolved.
 */
function ec_get_artist_term_id( $profile_id ) {
    $profile_id = (int) $profile_id;
    if ( $profile_id <= 0 ) {
        return 0;
    }

    // 1. Stored binding wins.
    $stored = (int) get_post_meta( $profile_id, '_artist_term_id', true );
    if ( $stored > 0 ) {
        return $stored;
    }

    // 2. Slug fallback. The profile lives on the artist blog; resolve its slug
    //    there, then look the term up on the main blog.
    $slug = get_post_field( 'post_name', $profile_id );
    if ( empty( $slug ) ) {
        return 0;
    }

    $main_blog_id = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'main' ) : null;
    if ( ! $main_blog_id ) {
        return 0;
    }

    $term_id   = 0;
    $term_slug = '';
    switch_to_blog( $main_blog_id );
    try {
        $term = get_term_by( 'slug', $slug, 'artist' );
        if ( $term && ! is_wp_error( $term ) ) {
            $term_id   = (int) $term->term_id;
            $term_slug = $term->slug;
        }
    } finally {
        restore_current_blog();
    }

    if ( $term_id <= 0 ) {
        return 0;
    }

    // 3. Self-heal: persist both sides of the binding.
    ec_bind_artist_profile_to_term( $profile_id, $term_id, $main_blog_id );

    return $term_id;
}

/**
 * Resolve the artist-blog artist_profile post ID bound to an `artist` term.
 *
 * Reads the stored `_artist_profile_id` term meta first. If absent, falls back
 * to matching the term's slug against an `artist_profile` post on the ARTIST
 * blog and, on success, writes BOTH meta sides so the next lookup is O(1).
 *
 * @param int $term_id Main-blog `artist` term_id.
 * @return int Artist profile post ID (on the artist blog), or 0 if none.
 */
function ec_get_artist_profile_id( $term_id ) {
    $term_id = (int) $term_id;
    if ( $term_id <= 0 ) {
        return 0;
    }

    $main_blog_id = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'main' ) : null;
    if ( ! $main_blog_id ) {
        return 0;
    }

    // 1. Stored binding wins. Term meta lives on the MAIN blog alongside the term.
    $stored    = 0;
    $term_slug = '';
    switch_to_blog( $main_blog_id );
    try {
        $stored = (int) get_term_meta( $term_id, '_artist_profile_id', true );
        if ( $stored <= 0 ) {
            $term = get_term( $term_id, 'artist' );
            if ( $term && ! is_wp_error( $term ) ) {
                $term_slug = $term->slug;
            }
        }
    } finally {
        restore_current_blog();
    }

    if ( $stored > 0 ) {
        return $stored;
    }

    if ( empty( $term_slug ) ) {
        return 0;
    }

    // 2. Slug fallback. Look the profile up on the artist blog by slug.
    $artist_blog_id = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'artist' ) : null;
    if ( ! $artist_blog_id ) {
        return 0;
    }

    $profile_id = 0;
    switch_to_blog( $artist_blog_id );
    try {
        $found = get_posts( array(
            'post_type'        => 'artist_profile',
            'name'             => $term_slug,
            'post_status'      => 'publish',
            'posts_per_page'   => 1,
            'fields'           => 'ids',
            'suppress_filters' => false,
        ) );
        if ( ! empty( $found ) ) {
            $profile_id = (int) $found[0];
        }
    } finally {
        restore_current_blog();
    }

    if ( $profile_id <= 0 ) {
        return 0;
    }

    // 3. Self-heal: persist both sides of the binding.
    ec_bind_artist_profile_to_term( $profile_id, $term_id, $main_blog_id );

    return $profile_id;
}

/**
 * Persist both sides of the profile <-> term binding.
 *
 * Writes `_artist_term_id` on the profile (artist blog) and `_artist_profile_id`
 * on the term (main blog). The term meta write is performed inside a
 * switch_to_blog()/restore_current_blog() pair so it always lands on the main
 * blog regardless of the caller's current context.
 *
 * @param int      $profile_id   Artist profile post ID (artist blog).
 * @param int      $term_id      Main-blog `artist` term_id.
 * @param int|null $main_blog_id Optional resolved main blog ID (avoids a lookup).
 * @return void
 */
function ec_bind_artist_profile_to_term( $profile_id, $term_id, $main_blog_id = null ) {
    $profile_id = (int) $profile_id;
    $term_id    = (int) $term_id;
    if ( $profile_id <= 0 || $term_id <= 0 ) {
        return;
    }

    // Profile-side meta. update_post_meta targets the post on whichever blog it
    // lives on; this binding is only ever resolved from the artist blog where
    // the profile is the current post, so write it directly.
    update_post_meta( $profile_id, '_artist_term_id', $term_id );

    // Term-side meta lives on the main blog.
    if ( null === $main_blog_id ) {
        $main_blog_id = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'main' ) : null;
    }
    if ( ! $main_blog_id ) {
        return;
    }

    switch_to_blog( $main_blog_id );
    try {
        update_term_meta( $term_id, '_artist_profile_id', $profile_id );
    } finally {
        restore_current_blog();
    }
}

/**
 * Ensure an artist_profile is bound to a matching `artist` term on save.
 *
 * Runs on the artist blog when a profile is created or updated. If no stored
 * binding exists, resolves (or creates) the matching main-blog `artist` term by
 * slug and persists both meta sides. A term without a profile is fine (most of
 * the 1,182 terms have none); this only guarantees that a profile always has a
 * term.
 *
 * @param int $profile_id Artist profile post ID.
 * @return void
 */
function ec_sync_artist_profile_term_binding( $profile_id ) {
    $profile_id = (int) $profile_id;
    if ( $profile_id <= 0 || get_post_type( $profile_id ) !== 'artist_profile' ) {
        return;
    }

    // Already bound? Nothing to do.
    if ( (int) get_post_meta( $profile_id, '_artist_term_id', true ) > 0 ) {
        return;
    }

    // Try the slug-fallback resolver first (it self-heals on success).
    $term_id = ec_get_artist_term_id( $profile_id );
    if ( $term_id > 0 ) {
        return;
    }

    // No matching term exists yet — create one on the main blog so the profile
    // is always represented in the network join key.
    $title = get_the_title( $profile_id );
    $slug  = get_post_field( 'post_name', $profile_id );
    if ( empty( $title ) || empty( $slug ) ) {
        return;
    }

    $main_blog_id = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'main' ) : null;
    if ( ! $main_blog_id ) {
        return;
    }

    $new_term_id = 0;
    switch_to_blog( $main_blog_id );
    try {
        $existing = get_term_by( 'slug', $slug, 'artist' );
        if ( $existing && ! is_wp_error( $existing ) ) {
            $new_term_id = (int) $existing->term_id;
        } else {
            $inserted = wp_insert_term( $title, 'artist', array( 'slug' => $slug ) );
            if ( ! is_wp_error( $inserted ) && ! empty( $inserted['term_id'] ) ) {
                $new_term_id = (int) $inserted['term_id'];
            }
        }
    } finally {
        restore_current_blog();
    }

    if ( $new_term_id > 0 ) {
        ec_bind_artist_profile_to_term( $profile_id, $new_term_id, $main_blog_id );
    }
}
add_action( 'ec_artist_profile_save', 'ec_sync_artist_profile_term_binding', 5, 1 );

/**
 * Idempotent, run-once-per-version backfill of profile <-> term bindings.
 *
 * Mirrors the version-gated option pattern used by
 * extrachill_artist_platform_heal_zero_modified_dates(): an option stores the
 * backfill version; the walk only runs when the stored version is behind.
 *
 * Walks every artist_profile on the artist blog, resolves each one's `artist`
 * term by slug on the main blog, and writes both meta sides. Profiles that
 * already carry a stored `_artist_term_id` are skipped. Terms without a profile
 * are left untouched (a term without a profile is just an untagged artist).
 *
 * @return void
 */
function ec_backfill_artist_term_bindings() {
    $backfill_version = '1.0.0';
    $stored = get_option( 'extrachill_artist_platform_term_binding_backfill', '0' );
    if ( version_compare( $stored, $backfill_version, '>=' ) ) {
        return;
    }

    $artist_blog_id = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'artist' ) : null;
    $main_blog_id   = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'main' ) : null;
    if ( ! $artist_blog_id || ! $main_blog_id ) {
        // Network helpers unavailable; do not burn the version gate. Retry next load.
        return;
    }

    // Collect candidate profiles (id + slug) from the artist blog.
    $profiles = array();
    switch_to_blog( $artist_blog_id );
    try {
        $found = get_posts( array(
            'post_type'        => 'artist_profile',
            'post_status'      => 'any',
            'posts_per_page'   => -1,
            'fields'           => 'ids',
            'suppress_filters' => false,
        ) );
        foreach ( (array) $found as $pid ) {
            $pid = (int) $pid;
            if ( (int) get_post_meta( $pid, '_artist_term_id', true ) > 0 ) {
                continue; // already bound
            }
            $slug = get_post_field( 'post_name', $pid );
            if ( ! empty( $slug ) ) {
                $profiles[ $pid ] = $slug;
            }
        }
    } finally {
        restore_current_blog();
    }

    if ( empty( $profiles ) ) {
        update_option( 'extrachill_artist_platform_term_binding_backfill', $backfill_version );
        return;
    }

    // Resolve each slug to a term on the main blog (single switch for the batch).
    $resolved = array();
    switch_to_blog( $main_blog_id );
    try {
        foreach ( $profiles as $pid => $slug ) {
            $term = get_term_by( 'slug', $slug, 'artist' );
            if ( $term && ! is_wp_error( $term ) ) {
                $term_id = (int) $term->term_id;
                $resolved[ $pid ] = $term_id;
                // Write the term-side meta while we are already on the main blog.
                update_term_meta( $term_id, '_artist_profile_id', $pid );
            }
        }
    } finally {
        restore_current_blog();
    }

    // Write the profile-side meta on the artist blog.
    if ( ! empty( $resolved ) ) {
        switch_to_blog( $artist_blog_id );
        try {
            foreach ( $resolved as $pid => $term_id ) {
                update_post_meta( $pid, '_artist_term_id', $term_id );
            }
        } finally {
            restore_current_blog();
        }
    }

    update_option( 'extrachill_artist_platform_term_binding_backfill', $backfill_version );
}
add_action( 'admin_init', 'ec_backfill_artist_term_bindings' );
