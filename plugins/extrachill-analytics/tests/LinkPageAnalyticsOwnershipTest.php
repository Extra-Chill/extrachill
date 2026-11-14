<?php
/**
 * Link-page analytics authorize against the Link Page's own owner reference.
 *
 * Since the Link Pages cutover the Link Page lives on the Link Pages storage
 * blog and the artist profile lives on the Artist blog. Their post ID
 * sequences are unrelated, so the ability must resolve the owning artist from
 * the Link Page's `_ec_link_page_owner_reference` on the storage blog, never
 * by treating the Link Page ID as an artist ID (Extra-Chill/extrachill#1311).
 *
 * @package ExtraChill\Analytics
 */

require_once __DIR__ . '/class-platform-contract-fixture.php';

/*
 * Guarded doubles for the Link Pages runtime and the Users permission
 * primitive. The managed harness loads only this plugin; when the real
 * plugins are loaded their implementations win.
 */
if ( ! function_exists( 'ec_with_link_page_storage_blog' ) ) {
	/**
	 * Run a callback on the fixture Link Page storage blog.
	 *
	 * @param callable $callback Callback receiving the storage blog ID.
	 * @return mixed
	 */
	function ec_with_link_page_storage_blog( $callback ) {
		$storage_blog_id = isset( $GLOBALS['extrachill_analytics_test_link_page_storage_blog_id'] ) ? (int) $GLOBALS['extrachill_analytics_test_link_page_storage_blog_id'] : get_current_blog_id();
		switch_to_blog( $storage_blog_id );
		try {
			return call_user_func( $callback, $storage_blog_id );
		} finally {
			restore_current_blog();
		}
	}
}

if ( ! function_exists( 'ec_get_link_page_owner' ) ) {
	/**
	 * Resolve the parsed owner reference stored on a Link Page.
	 *
	 * @param int $link_page_id Link Page ID on the storage blog.
	 * @return array|WP_Error
	 */
	function ec_get_link_page_owner( $link_page_id ) {
		return ec_with_link_page_storage_blog(
			static function () use ( $link_page_id ) {
				if ( ec_link_page_post_type() !== get_post_type( $link_page_id ) ) {
					return new WP_Error( 'invalid_link_page', 'The Link Page does not exist.' );
				}
				$reference = (string) get_post_meta( $link_page_id, '_ec_link_page_owner_reference', true );
				if ( '' === $reference ) {
					return new WP_Error( 'link_page_owner_not_found', 'The Link Page has no owner association.' );
				}
				if ( 1 !== preg_match( '/^(post|term):([1-9][0-9]*):([a-z0-9_-]+):([1-9][0-9]*)$/', $reference, $matches ) ) {
					return new WP_Error( 'invalid_link_page_owner_reference', 'The Link Page owner reference is malformed.' );
				}
				return array(
					'kind'      => $matches[1],
					'blog_id'   => (int) $matches[2],
					'subtype'   => $matches[3],
					'object_id' => (int) $matches[4],
					'reference' => $reference,
				);
			}
		);
	}
}

if ( ! function_exists( 'ec_can_manage_artist' ) ) {
	/**
	 * Whether a user is a member of a published artist profile on the Artist blog.
	 *
	 * @param int $user_id   User ID.
	 * @param int $artist_id Artist profile ID on the Artist blog.
	 * @return bool
	 */
	function ec_can_manage_artist( $user_id = null, $artist_id = null ) {
		$artist_blog_id = (int) ec_get_blog_id( 'artist' );
		if ( ! $user_id || ! $artist_id || ! $artist_blog_id ) {
			return false;
		}
		switch_to_blog( $artist_blog_id );
		try {
			$post = get_post( (int) $artist_id );
			if ( ! $post instanceof WP_Post || 'artist_profile' !== $post->post_type || 'publish' !== $post->post_status ) {
				return false;
			}
		} finally {
			restore_current_blog();
		}
		$artists = array_map( 'intval', (array) get_user_meta( (int) $user_id, '_artist_profile_ids', true ) );
		return in_array( (int) $artist_id, $artists, true );
	}
}

/** Owner-reference authorization for the get-link-page-analytics ability. */
final class LinkPageAnalyticsOwnershipTest extends WP_UnitTestCase {
	/** @var int */
	private $artist_blog_id;
	/** @var int */
	private $storage_blog_id;
	/** @var int Link Page ID on the storage blog. */
	private $link_page_id;
	/** @var int Real artist profile on the Artist blog. */
	private $artist_id;
	/** @var int Owner of the real artist profile. */
	private $owner_id;
	/** @var int Owner of the artist profile whose ID equals the Link Page ID. */
	private $intruder_id;
	/** @var callable */
	private $provider;
	/** @var callable */
	private $identity_artist_filter;

	public function set_up(): void {
		parent::set_up();
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Requires multisite.' );
		}

		// The main site stands in for the Link Pages storage blog and a fresh
		// site for the Artist blog. (The SQLite harness cannot create two
		// sites inside one test: the second create reuses the first ID.)
		$this->storage_blog_id = get_current_blog_id();
		$this->artist_blog_id  = (int) self::factory()->blog->create();
		$this->assertNotSame( $this->artist_blog_id, $this->storage_blog_id, 'Fixture requires distinct Artist and Link Pages blogs.' );
		$GLOBALS['extrachill_analytics_test_blog_slugs']                  = array(
			$this->storage_blog_id => 'link',
			$this->artist_blog_id  => 'artist',
		);
		$GLOBALS['extrachill_analytics_test_link_page_storage_blog_id'] = $this->storage_blog_id;

		$this->owner_id    = (int) self::factory()->user->create();
		$this->intruder_id = (int) self::factory()->user->create();

		// Link Page on the storage blog, with an ID that collides with an
		// unrelated artist profile on the Artist blog.
		switch_to_blog( $this->storage_blog_id );
		$this->link_page_id = (int) wp_insert_post(
			array(
				'post_type'   => ec_link_page_post_type(),
				'post_status' => 'publish',
				'post_title'  => 'AC',
			)
		);
		restore_current_blog();

		// An unrelated artist profile on the Artist blog whose ID equals the
		// Link Page ID. Written with an explicit primary key so the collision
		// does not depend on either blog's auto-increment position.
		$colliding_artist = $this->insert_artist_profile_with_id( $this->link_page_id, 'Gavin Hamilton' );

		switch_to_blog( $this->artist_blog_id );
		$this->artist_id = (int) wp_insert_post(
			array(
				'post_type'   => 'artist_profile',
				'post_status' => 'publish',
				'post_title'  => 'AC',
			)
		);
		restore_current_blog();

		$this->assertSame( $this->link_page_id, $colliding_artist, 'Fixture requires colliding IDs.' );
		$this->assertNotSame( $this->link_page_id, $this->artist_id, 'Fixture requires the real artist ID to differ from the Link Page ID.' );

		update_user_meta( $this->owner_id, '_artist_profile_ids', array( $this->artist_id ) );
		update_user_meta( $this->intruder_id, '_artist_profile_ids', array( $colliding_artist ) );

		$this->set_owner_reference( 'post:' . $this->artist_blog_id . ':artist_profile:' . $this->artist_id );

		// Simulate the old resolution path: on the Link Pages blog the
		// ec_get_artist_id filter is unregistered and returns its input.
		$this->identity_artist_filter = static function ( $value ) {
			return $value;
		};
		add_filter( 'ec_get_artist_id', $this->identity_artist_filter );

		$this->provider = static function ( $result, $link_page_id ) {
			unset( $result );
			return array(
				'link_page_id' => (int) $link_page_id,
				'summary'      => array(
					'total_views'  => 10,
					'total_clicks' => 1,
				),
			);
		};
		add_filter( 'extrachill_get_link_page_analytics', $this->provider, 99, 2 );
	}

	public function tear_down(): void {
		remove_filter( 'ec_get_artist_id', $this->identity_artist_filter );
		remove_filter( 'extrachill_get_link_page_analytics', $this->provider, 99 );
		unset( $GLOBALS['extrachill_analytics_test_blog_slugs'], $GLOBALS['extrachill_analytics_test_link_page_storage_blog_id'] );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/** (a) The owner can read, from both the Artist blog and the Link Pages blog. */
	public function test_owner_can_read_from_artist_and_link_pages_blogs(): void {
		wp_set_current_user( $this->owner_id );

		foreach ( array( $this->artist_blog_id, $this->storage_blog_id ) as $blog_id ) {
			$result = $this->run_on_blog( $blog_id );
			$this->assertIsArray( $result, 'Owner must read analytics on blog ' . $blog_id );
			$this->assertSame( $this->link_page_id, $result['link_page_id'] );
			$this->assertSame( 10, $result['summary']['total_views'] );
		}
	}

	/** (b) A non-owner whose artist ID equals the Link Page ID is denied on both blogs. */
	public function test_colliding_artist_id_owner_is_denied(): void {
		wp_set_current_user( $this->intruder_id );

		foreach ( array( $this->artist_blog_id, $this->storage_blog_id ) as $blog_id ) {
			$result = $this->run_on_blog( $blog_id );
			$this->assertWPError( $result );
			$this->assertSame( 'permission_denied', $result->get_error_code(), 'Blog ' . $blog_id );
		}
	}

	/** The shared resolver returns the referenced artist from any blog. */
	public function test_resolver_reads_owner_reference_on_storage_blog(): void {
		foreach ( array( $this->artist_blog_id, $this->storage_blog_id ) as $blog_id ) {
			$this->flush_post_cache();
			switch_to_blog( $blog_id );
			try {
				$this->assertSame( $this->artist_id, extrachill_analytics_resolve_link_page_artist_id( $this->link_page_id ) );
			} finally {
				restore_current_blog();
			}
		}
	}

	/** The owner reference is the only binding: legacy association meta alone never resolves. */
	public function test_resolver_ignores_legacy_association_without_reference(): void {
		$this->set_owner_reference( '' );
		switch_to_blog( $this->storage_blog_id );
		update_post_meta( $this->link_page_id, '_associated_artist_profile_id', $this->artist_id );
		restore_current_blog();

		wp_set_current_user( $this->owner_id );
		$this->flush_post_cache();
		$result = extrachill_analytics_resolve_link_page_artist_id( $this->link_page_id );
		$this->assertWPError( $result );
		$this->assertSame( 'artist_not_found', $result->get_error_code() );
	}

	/** Non-artist or foreign-blog owners never resolve to an artist. */
	public function test_non_artist_owner_is_not_found(): void {
		wp_set_current_user( $this->owner_id );

		$this->set_owner_reference( 'term:' . $this->storage_blog_id . ':venue:' . $this->artist_id );
		$result = $this->run_on_blog( $this->storage_blog_id );
		$this->assertWPError( $result );
		$this->assertSame( 'artist_not_found', $result->get_error_code() );

		$this->set_owner_reference( 'post:' . $this->storage_blog_id . ':artist_profile:' . $this->artist_id );
		$result = $this->run_on_blog( $this->storage_blog_id );
		$this->assertWPError( $result );
		$this->assertSame( 'artist_not_found', $result->get_error_code() );
	}

	/** The ability source no longer resolves ownership through ec_get_artist_id. */
	public function test_ability_does_not_use_ec_get_artist_id(): void {
		$source = file_get_contents( dirname( __DIR__ ) . '/inc/core/abilities/get-link-page-analytics.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local source fixture.
		$this->assertStringNotContainsString( "apply_filters( 'ec_get_artist_id'", $source );
		$this->assertStringContainsString( 'extrachill_analytics_resolve_link_page_artist_id( $link_page_id )', $source );
	}

	/**
	 * Drop cached posts between cross-blog reads.
	 *
	 * The fixture deliberately gives a Link Pages post and an Artist post the
	 * same ID. The harness object cache does not key the posts group by blog
	 * across switch_to_blog(), so a cached row from one blog would shadow the
	 * other. Production's persistent cache is blog-aware.
	 */
	private function flush_post_cache(): void {
		wp_cache_flush();
	}

	/**
	 * Insert a published artist profile with an explicit ID on the Artist blog.
	 *
	 * @param int    $id    Post ID.
	 * @param string $title Post title.
	 * @return int Inserted ID, or 0 on failure.
	 */
	private function insert_artist_profile_with_id( int $id, string $title ): int {
		global $wpdb;
		switch_to_blog( $this->artist_blog_id );
		try {
			// Free the ID if a default site post already occupies it.
			if ( get_post( $id ) ) {
				wp_delete_post( $id, true );
			}
			$now = current_time( 'mysql', true );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Test fixture needs an explicit primary key.
			$inserted = $wpdb->insert(
				$wpdb->posts,
				array(
					'ID'                => $id,
					'post_author'       => 0,
					'post_date'         => $now,
					'post_date_gmt'     => $now,
					'post_content'      => '',
					'post_title'        => $title,
					'post_excerpt'      => '',
					'post_status'       => 'publish',
					'post_name'         => sanitize_title( $title ),
					'to_ping'           => '',
					'pinged'            => '',
					'post_modified'     => $now,
					'post_modified_gmt' => $now,
					'post_content_filtered' => '',
					'post_type'         => 'artist_profile',
				)
			);
			clean_post_cache( $id );
			return $inserted ? $id : 0;
		} finally {
			restore_current_blog();
		}
	}

	/**
	 * Store (or clear) the canonical owner reference on the Link Page.
	 *
	 * @param string $reference Owner reference, or '' to delete.
	 */
	private function set_owner_reference( string $reference ): void {
		switch_to_blog( $this->storage_blog_id );
		if ( '' === $reference ) {
			delete_post_meta( $this->link_page_id, '_ec_link_page_owner_reference' );
		} else {
			update_post_meta( $this->link_page_id, '_ec_link_page_owner_reference', $reference );
		}
		restore_current_blog();
	}

	/**
	 * Execute the ability while serving the request from a given blog.
	 *
	 * @param int $blog_id Serving blog.
	 * @return array|WP_Error
	 */
	private function run_on_blog( int $blog_id ) {
		$this->flush_post_cache();
		switch_to_blog( $blog_id );
		try {
			return extrachill_analytics_ability_get_link_page_analytics( array( 'link_page_id' => $this->link_page_id ) );
		} finally {
			restore_current_blog();
		}
	}
}
