<?php
/**
 * Canonical artist identity read.
 *
 * One cached, network-wide read of an artist's identity across the two
 * blogs that own it. Artist Platform is only active on the artist blog, so
 * consumers on other blogs used to re-implement this lookup with their own
 * switch_to_blog() calls. They now call ec_get_artist_identity() instead.
 *
 * Ownership:
 * - Main blog: the `artist` taxonomy term. Its term meta `_artist_profile_id`
 *   points at the profile.
 * - Artist blog: the `artist_profile` post. Its post meta `_artist_term_id`
 *   points at the term.
 *
 * Canonical binding rule (binding_valid):
 *   A binding is valid only when ALL of these hold:
 *   1. The profile is a post of type `artist_profile` on the artist blog, in
 *      any post status.
 *   2. The term exists in the `artist` taxonomy on the main blog.
 *   3. The profile's `_artist_term_id` is that term's ID.
 *   4. The term's `_artist_profile_id` is that profile's ID.
 *   Pointers are parsed strictly: only a positive integer, or a string of
 *   digits, counts as a pointer. Anything else reads as unbound (0).
 *
 * This is the owner's rule in Artist Platform's ec_read_locked_artist_binding().
 * Publication is consumer policy, not part of the binding. A consumer that
 * needs a published, bound artist (as the Events authorization path does)
 * checks `binding_valid && 'publish' === status`.
 *
 * The record returned for a lookup:
 * - profile_id / term_id: the looked-up entity's own ID, plus the parsed
 *   pointer it holds to the other side. These are reported even when the
 *   binding is invalid; check binding_valid before treating them as a pair.
 * - name, slug, status, permalink, thumbnail_id, thumbnail_url: read from the
 *   profile post. They are empty when no `artist_profile` exists at
 *   profile_id.
 * - term_name, term_slug: read from the term. They are empty when no
 *   `artist` term exists at term_id.
 *
 * Caching: records live in a network-global object-cache group. Every key is
 * prefixed with a generation counter. ec_forget_artist_identity() bumps the
 * counter, which retires every cached record at once, including slug keys
 * and records that hold one-sided pointers. Artist Platform calls it from
 * its binding mutations and profile lifecycle hooks. This file calls it on
 * main-blog term changes, on artist profile post-cache cleans, and on
 * binding or thumbnail meta writes, from any blog context.
 *
 * @package ExtraChillNetwork
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const EC_ARTIST_IDENTITY_CACHE_GROUP = 'ec_artist_identity';

/**
 * Register the artist identity cache group as network-global.
 *
 * Keys are not blog-scoped. Every blog reads the same record, and one
 * invalidation retires it for all of them.
 *
 * @return void
 */
function ec_artist_identity_register_cache_group() {
	if ( function_exists( 'wp_cache_add_global_groups' ) ) {
		wp_cache_add_global_groups( array( EC_ARTIST_IDENTITY_CACHE_GROUP ) );
	}
}
ec_artist_identity_register_cache_group();

/**
 * Return the current cache generation, read once per request.
 *
 * @param bool $reset True to drop the per-request value after a bump.
 * @return int Current generation (>= 1), or 0 when resetting.
 */
function ec_artist_identity_cache_generation( $reset = false ) {
	static $generation = null;

	if ( $reset ) {
		$generation = null;
		return 0;
	}
	if ( null !== $generation ) {
		return $generation;
	}

	$found = (int) wp_cache_get( 'generation', EC_ARTIST_IDENTITY_CACHE_GROUP );
	if ( $found >= 1 ) {
		$generation = $found;
		return $generation;
	}
	if ( wp_cache_add( 'generation', 1, EC_ARTIST_IDENTITY_CACHE_GROUP ) ) {
		$generation = 1;
		return $generation;
	}

	$found      = (int) wp_cache_get( 'generation', EC_ARTIST_IDENTITY_CACHE_GROUP );
	$generation = $found >= 1 ? $found : 1;
	return $generation;
}

/**
 * Build a generation-prefixed cache key.
 *
 * @param string     $kind  Lookup kind: profile, term or slug.
 * @param int|string $value Lookup value.
 * @return string
 */
function ec_artist_identity_cache_key( $kind, $value ) {
	return 'g' . ec_artist_identity_cache_generation() . ':' . $kind . ':' . $value;
}

/**
 * Parse a stored binding pointer.
 *
 * Only a positive integer or a string of digits counts. Anything else reads
 * as unbound.
 *
 * @param mixed $value Raw meta value.
 * @return int Positive ID or 0.
 */
function ec_artist_identity_parse_id( $value ) {
	if ( is_int( $value ) ) {
		return 0 < $value ? $value : 0;
	}
	if ( is_string( $value ) && '' !== $value && ctype_digit( $value ) ) {
		return (int) $value;
	}
	return 0;
}

/**
 * Normalize a lookup into its kind and value.
 *
 * @param array $by Exactly one of profile_id, term_id or slug.
 * @return array{0:string,1:int|string}|null Kind and value, or null when invalid.
 */
function ec_artist_identity_normalize_lookup( array $by ) {
	if ( 1 !== count( $by ) ) {
		return null;
	}

	$key   = (string) key( $by );
	$value = reset( $by );
	if ( 'profile_id' === $key || 'term_id' === $key ) {
		$id = ec_artist_identity_parse_id( $value );
		return 0 < $id ? array( 'profile_id' === $key ? 'profile' : 'term', $id ) : null;
	}
	if ( 'slug' === $key && is_string( $value ) ) {
		$slug = sanitize_title( $value );
		return '' !== $slug ? array( 'slug', $slug ) : null;
	}

	return null;
}

/**
 * Read an artist's canonical identity.
 *
 * Pass exactly one key:
 * - profile_id: an artist_profile post ID on the artist blog, in any status.
 * - term_id: an `artist` term ID on the main blog.
 * - slug: the post_name of a published artist_profile.
 *
 * See the file header for the binding rule and record fields.
 *
 * @param array $by Lookup with exactly one of profile_id, term_id or slug.
 * @return array{profile_id:int,term_id:int,slug:string,name:string,status:string,permalink:string,thumbnail_id:int,thumbnail_url:string,term_slug:string,term_name:string,binding_valid:bool}|null
 *         Identity, or null when the lookup is invalid or the entity does not exist.
 */
function ec_get_artist_identity( array $by ) {
	$lookup = ec_artist_identity_normalize_lookup( $by );
	if ( null === $lookup ) {
		return null;
	}

	list( $kind, $value ) = $lookup;
	$cache_key            = ec_artist_identity_cache_key( $kind, $value );
	$found                = false;
	$cached               = wp_cache_get( $cache_key, EC_ARTIST_IDENTITY_CACHE_GROUP, false, $found );
	if ( $found && is_array( $cached ) ) {
		/** @var array{profile_id:int,term_id:int,slug:string,name:string,status:string,permalink:string,thumbnail_id:int,thumbnail_url:string,term_slug:string,term_name:string,binding_valid:bool} $cached Records are only cached by this function. */
		return $cached;
	}
	if ( $found && 0 === $cached ) {
		return null;
	}

	$identity = ec_artist_identity_read( $kind, $value );
	if ( false === $identity ) {
		return null;
	}

	/**
	 * Filters how long an artist identity stays cached.
	 *
	 * Invalidation is explicit, so the TTL only bounds staleness after a
	 * write that bypasses the WordPress APIs, such as raw SQL.
	 *
	 * @param int $ttl Lifetime in seconds. Default 6 hours.
	 */
	$ttl = (int) apply_filters( 'ec_artist_identity_cache_ttl', 6 * HOUR_IN_SECONDS );
	wp_cache_set( $cache_key, null === $identity ? 0 : $identity, EC_ARTIST_IDENTITY_CACHE_GROUP, $ttl );

	return $identity;
}

/**
 * Forget cached artist identities after a change.
 *
 * Deletes the direct keys for the given IDs, then bumps the generation.
 * The bump retires records that cannot be listed, such as slug keys and
 * terms whose one-sided pointer names this profile. Records are rebuilt
 * on the next read.
 *
 * @param int $profile_id Artist profile post ID, or 0.
 * @param int $term_id    Main-blog artist term ID, or 0.
 * @return void
 */
function ec_forget_artist_identity( $profile_id = 0, $term_id = 0 ) {
	$profile_id = (int) $profile_id;
	$term_id    = (int) $term_id;
	if ( 0 < $profile_id ) {
		wp_cache_delete( ec_artist_identity_cache_key( 'profile', $profile_id ), EC_ARTIST_IDENTITY_CACHE_GROUP );
	}
	if ( 0 < $term_id ) {
		wp_cache_delete( ec_artist_identity_cache_key( 'term', $term_id ), EC_ARTIST_IDENTITY_CACHE_GROUP );
	}

	$generation = (int) wp_cache_get( 'generation', EC_ARTIST_IDENTITY_CACHE_GROUP );
	$next       = wp_cache_incr( 'generation', 1, EC_ARTIST_IDENTITY_CACHE_GROUP );
	if ( false === $next ) {
		wp_cache_set( 'generation', max( 1, $generation ) + 1, EC_ARTIST_IDENTITY_CACHE_GROUP );
	}
	ec_artist_identity_cache_generation( true );
}

/**
 * Read an identity from its owning blogs, bypassing the cache.
 *
 * This is the only place that switches blogs for an identity.
 *
 * @param string     $kind  Lookup kind: profile, term or slug.
 * @param int|string $value Lookup value.
 * @return array{profile_id:int,term_id:int,slug:string,name:string,status:string,permalink:string,thumbnail_id:int,thumbnail_url:string,term_slug:string,term_name:string,binding_valid:bool}|null|false
 *         Identity, null when absent, or false when it cannot be read here
 *         and must not be cached.
 */
function ec_artist_identity_read( $kind, $value ) {
	$artist_blog_id = (int) ec_get_blog_id( 'artist' );
	$main_blog_id   = (int) ec_get_blog_id( 'main' );
	if ( 0 >= $artist_blog_id || 0 >= $main_blog_id ) {
		return false;
	}

	if ( 'term' === $kind ) {
		$term = ec_artist_identity_read_term( (int) $value, $main_blog_id );
		if ( ! is_array( $term ) ) {
			return $term;
		}
		$profile = 0 < $term['profile_id'] ? ec_artist_identity_read_profile( $term['profile_id'], $artist_blog_id ) : null;
		return ec_artist_identity_compose( $profile, $term );
	}

	$profile_id = 'slug' === $kind
		? ec_artist_identity_find_profile_by_slug( (string) $value, $artist_blog_id )
		: (int) $value;
	if ( 0 >= $profile_id ) {
		return null;
	}

	$profile = ec_artist_identity_read_profile( $profile_id, $artist_blog_id );
	if ( null === $profile ) {
		return null;
	}
	$term = 0 < $profile['term_id'] ? ec_artist_identity_read_term( $profile['term_id'], $main_blog_id ) : null;
	if ( false === $term ) {
		return false;
	}

	return ec_artist_identity_compose( $profile, $term );
}

/**
 * Find the published artist profile that owns a slug.
 *
 * @param string $slug           Sanitized slug.
 * @param int    $artist_blog_id Artist blog ID.
 * @return int Profile ID or 0.
 */
function ec_artist_identity_find_profile_by_slug( $slug, $artist_blog_id ) {
	return (int) ec_with_blog(
		$artist_blog_id,
		static function () use ( $slug ) {
			$ids = get_posts(
				array(
					'post_type'      => 'artist_profile',
					'post_status'    => 'publish',
					'name'           => $slug,
					'posts_per_page' => 1,
					'fields'         => 'ids',
					'no_found_rows'  => true,
				)
			);
			return empty( $ids ) ? 0 : (int) reset( $ids );
		}
	);
}

/**
 * Read the profile side on the artist blog.
 *
 * @param int $profile_id     Artist profile post ID.
 * @param int $artist_blog_id Artist blog ID.
 * @return array{id:int,term_id:int,slug:string,name:string,status:string,permalink:string,thumbnail_id:int,thumbnail_url:string}|null
 */
function ec_artist_identity_read_profile( $profile_id, $artist_blog_id ) {
	return ec_with_blog(
		$artist_blog_id,
		static function () use ( $profile_id ) {
			$post = get_post( $profile_id );
			if ( ! $post instanceof WP_Post || 'artist_profile' !== $post->post_type ) {
				return null;
			}

			$thumbnail_id  = (int) get_post_thumbnail_id( $post );
			$thumbnail_url = 0 < $thumbnail_id ? wp_get_attachment_image_url( $thumbnail_id, 'thumbnail' ) : false;

			return array(
				'id'            => (int) $post->ID,
				'term_id'       => ec_artist_identity_parse_id( get_post_meta( (int) $post->ID, '_artist_term_id', true ) ),
				'slug'          => (string) $post->post_name,
				'name'          => (string) $post->post_title,
				'status'        => (string) $post->post_status,
				'permalink'     => ec_artist_identity_profile_permalink( $post ),
				'thumbnail_id'  => $thumbnail_id,
				'thumbnail_url' => is_string( $thumbnail_url ) ? $thumbnail_url : '',
			);
		}
	);
}

/**
 * Build a profile's permalink in artist-blog context.
 *
 * The artist_profile post type is not registered on other blogs, where
 * get_permalink() falls back to `?p=`. This reproduces core's
 * get_post_permalink() for the post type as Artist Platform registers it
 * (rewrite slug `artists`, no front, query var `artist_profile`), using the
 * artist blog's own permalink structure:
 * - Pretty permalinks, not draft or pending: `/artists/{slug}`, with the
 *   structure's trailing-slash rule.
 * - Plain permalinks, not draft or pending: `?artist_profile={slug}`.
 * - Draft, pending, auto-draft or future: `?post_type=artist_profile&p={id}`.
 * The `post_type_link` filter is not applied.
 *
 * @param WP_Post $post Artist profile post.
 * @return string Permalink.
 */
function ec_artist_identity_profile_permalink( WP_Post $post ) {
	$post_name        = (string) $post->post_name;
	$draft_or_pending = in_array( $post->post_status, array( 'draft', 'pending', 'auto-draft', 'future' ), true );
	if ( $draft_or_pending || '' === $post_name ) {
		return home_url( '?post_type=artist_profile&p=' . (int) $post->ID );
	}

	$structure = (string) get_option( 'permalink_structure' );
	if ( '' === $structure ) {
		return home_url( '?artist_profile=' . $post_name );
	}

	$post_type = get_post_type_object( 'artist_profile' );
	$rewrite   = $post_type && is_array( $post_type->rewrite ) && ! empty( $post_type->rewrite['slug'] ) ? trim( (string) $post_type->rewrite['slug'], '/' ) : 'artists';
	$permalink = home_url( $rewrite . '/' . $post_name );

	return '/' === substr( $structure, -1 ) ? trailingslashit( $permalink ) : untrailingslashit( $permalink );
}

/**
 * Read the term side on the main blog.
 *
 * @param int $term_id      Artist term ID.
 * @param int $main_blog_id Main blog ID.
 * @return array{id:int,profile_id:int,slug:string,name:string}|null|false Term,
 *         null when absent, or false when the taxonomy is unavailable here.
 */
function ec_artist_identity_read_term( $term_id, $main_blog_id ) {
	return ec_with_blog(
		$main_blog_id,
		static function () use ( $term_id ) {
			if ( ! taxonomy_exists( 'artist' ) ) {
				return false;
			}
			$term = get_term( $term_id, 'artist' );
			if ( ! $term instanceof WP_Term || 'artist' !== $term->taxonomy ) {
				return null;
			}

			return array(
				'id'         => (int) $term->term_id,
				'profile_id' => ec_artist_identity_parse_id( get_term_meta( (int) $term->term_id, '_artist_profile_id', true ) ),
				'slug'       => (string) $term->slug,
				'name'       => (string) $term->name,
			);
		}
	);
}

/**
 * Compose the public identity record from both sides.
 *
 * The looked-up side is always present. The other side is null when its
 * pointer is unset or names an entity that does not exist.
 *
 * @param array|null $profile Profile side, or null.
 * @param array|null $term    Term side, or null.
 * @return array{profile_id:int,term_id:int,slug:string,name:string,status:string,permalink:string,thumbnail_id:int,thumbnail_url:string,term_slug:string,term_name:string,binding_valid:bool}
 */
function ec_artist_identity_compose( $profile, $term ) {
	$profile_id = is_array( $profile ) ? $profile['id'] : ( is_array( $term ) ? $term['profile_id'] : 0 );
	$term_id    = is_array( $term ) ? $term['id'] : ( is_array( $profile ) ? $profile['term_id'] : 0 );

	return array(
		'profile_id'    => (int) $profile_id,
		'term_id'       => (int) $term_id,
		'slug'          => is_array( $profile ) ? $profile['slug'] : '',
		'name'          => is_array( $profile ) ? $profile['name'] : '',
		'status'        => is_array( $profile ) ? $profile['status'] : '',
		'permalink'     => is_array( $profile ) ? $profile['permalink'] : '',
		'thumbnail_id'  => is_array( $profile ) ? $profile['thumbnail_id'] : 0,
		'thumbnail_url' => is_array( $profile ) ? $profile['thumbnail_url'] : '',
		'term_slug'     => is_array( $term ) ? $term['slug'] : '',
		'term_name'     => is_array( $term ) ? $term['name'] : '',
		'binding_valid' => is_array( $profile ) && is_array( $term )
			&& $profile['term_id'] === $term['id']
			&& $term['profile_id'] === $profile['id'],
	);
}

/**
 * Forget identities when binding or thumbnail post meta changes on the artist blog.
 *
 * Runs in every request, so writes made after switching into the artist blog
 * from another blog are covered too.
 *
 * @param int|int[] $meta_ids  Meta ID(s). Unused.
 * @param int       $object_id Post ID.
 * @param string    $meta_key  Meta key.
 * @return void
 */
function ec_artist_identity_post_meta_changed( $meta_ids, $object_id, $meta_key ) {
	unset( $meta_ids );
	if ( '_artist_term_id' !== $meta_key && '_thumbnail_id' !== $meta_key ) {
		return;
	}
	if ( get_current_blog_id() !== (int) ec_get_blog_id( 'artist' ) ) {
		return;
	}
	if ( '_thumbnail_id' === $meta_key && 'artist_profile' !== get_post_type( (int) $object_id ) ) {
		return;
	}
	ec_forget_artist_identity( (int) $object_id, 0 );
}
add_action( 'added_post_meta', 'ec_artist_identity_post_meta_changed', 10, 3 );
add_action( 'updated_post_meta', 'ec_artist_identity_post_meta_changed', 10, 3 );
add_action( 'deleted_post_meta', 'ec_artist_identity_post_meta_changed', 10, 3 );

/**
 * Forget identities when an artist profile's post cache is cleaned.
 *
 * WordPress cleans the post cache on every insert, update, status change and
 * delete. Artist Platform forgets identities from its own lifecycle hooks,
 * but it is only loaded on the artist blog. This hook also covers profile
 * writes made after switching into the artist blog from another blog.
 *
 * @param int          $post_id Post ID.
 * @param WP_Post|null $post    Post object.
 * @return void
 */
function ec_artist_identity_post_cache_cleaned( $post_id, $post = null ) {
	if ( ! $post instanceof WP_Post || 'artist_profile' !== $post->post_type ) {
		return;
	}
	if ( get_current_blog_id() !== (int) ec_get_blog_id( 'artist' ) ) {
		return;
	}
	ec_forget_artist_identity( (int) $post_id, 0 );
}
add_action( 'clean_post_cache', 'ec_artist_identity_post_cache_cleaned', 10, 2 );

/**
 * Forget identities when the term-side binding pointer changes on the main blog.
 *
 * @param int|int[] $meta_ids  Meta ID(s). Unused.
 * @param int       $object_id Term ID.
 * @param string    $meta_key  Meta key.
 * @return void
 */
function ec_artist_identity_term_meta_changed( $meta_ids, $object_id, $meta_key ) {
	unset( $meta_ids );
	if ( '_artist_profile_id' !== $meta_key || get_current_blog_id() !== (int) ec_get_blog_id( 'main' ) ) {
		return;
	}
	ec_forget_artist_identity( 0, (int) $object_id );
}
add_action( 'added_term_meta', 'ec_artist_identity_term_meta_changed', 10, 3 );
add_action( 'updated_term_meta', 'ec_artist_identity_term_meta_changed', 10, 3 );
add_action( 'deleted_term_meta', 'ec_artist_identity_term_meta_changed', 10, 3 );

/**
 * Forget identities when a main-blog artist term is created, edited or deleted.
 *
 * Artist Platform is not loaded on the main blog, so the network owns this hook.
 *
 * @param int    $term_id  Term ID.
 * @param int    $tt_id    Term taxonomy ID. Unused.
 * @param string $taxonomy Taxonomy slug.
 * @return void
 */
function ec_artist_identity_term_changed( $term_id, $tt_id, $taxonomy ) {
	unset( $tt_id );
	if ( 'artist' !== $taxonomy || get_current_blog_id() !== (int) ec_get_blog_id( 'main' ) ) {
		return;
	}
	ec_forget_artist_identity( 0, (int) $term_id );
}
add_action( 'created_term', 'ec_artist_identity_term_changed', 10, 3 );
add_action( 'edited_term', 'ec_artist_identity_term_changed', 10, 3 );
add_action( 'delete_term', 'ec_artist_identity_term_changed', 10, 3 );
