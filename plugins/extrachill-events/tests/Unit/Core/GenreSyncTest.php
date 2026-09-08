<?php
/**
 * Tests for the event genre projection helpers.
 *
 * GenreSync's statics are pure (no WordPress runtime dependencies), so the
 * union/cap rules behind the event genre projection and the import lockout
 * are asserted without a WP test fixture.
 *
 * @package ExtraChillEvents\Tests\Unit\Core
 */

namespace ExtraChillEvents\Tests\Unit\Core;

use ExtraChillEvents\Core\GenreSync;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 3 ) . '/inc/Core/GenreSync.php';

class GenreSyncTest extends TestCase {

	// --- union_genres() ---

	public function test_union_preserves_first_seen_order_across_terms(): void {
		$union = GenreSync::union_genres(
			array(
				11 => array( 'indie', 'rock' ),
				22 => array( 'punk', 'indie' ),
			)
		);

		$this->assertSame( array( 'indie', 'rock', 'punk' ), $union );
	}

	public function test_union_caps_at_five_slugs(): void {
		$union = GenreSync::union_genres(
			array(
				11 => array( 'rock', 'indie', 'punk', 'metal', 'hip-hop', 'jazz' ),
			)
		);

		$this->assertCount( 5, $union );
		$this->assertSame( array( 'rock', 'indie', 'punk', 'metal', 'hip-hop' ), $union );
	}

	public function test_union_respects_custom_cap(): void {
		$union = GenreSync::union_genres(
			array(
				11 => array( 'rock', 'indie', 'punk' ),
				22 => array( 'metal' ),
			),
			3
		);

		$this->assertSame( array( 'rock', 'indie', 'punk' ), $union );
	}

	public function test_union_enforces_a_minimum_cap_of_one(): void {
		$union = GenreSync::union_genres(
			array(
				11 => array( 'rock', 'indie' ),
			),
			0
		);

		$this->assertSame( array( 'rock' ), $union );
	}

	public function test_union_skips_non_array_and_non_string_entries(): void {
		$union = GenreSync::union_genres(
			array(
				11 => 'rock',
				22 => array( 'indie', 42, null, array( 'punk' ), '' ),
				33 => null,
			)
		);

		$this->assertSame( array( 'indie' ), $union );
	}

	public function test_union_normalizes_and_dedupes_slug_forms(): void {
		$union = GenreSync::union_genres(
			array(
				11 => array( 'Hip-Hop', 'hip hop' ),
				22 => array( 'R&B' ),
			)
		);

		$this->assertSame( array( 'hip-hop', 'rb' ), $union );
	}

	public function test_union_returns_empty_for_no_usable_input(): void {
		$this->assertSame( array(), GenreSync::union_genres( array() ) );
		$this->assertSame( array(), GenreSync::union_genres( array( 11 => array(), 22 => 'nope' ) ) );
	}

	// --- decode_genre_slugs() ---

	public function test_decode_json_array_of_slugs(): void {
		$this->assertSame(
			array( 'hip-hop', 'soul-funk' ),
			GenreSync::decode_genre_slugs( '["hip-hop","soul-funk"]' )
		);
	}

	public function test_decode_plain_array_passes_through(): void {
		$this->assertSame(
			array( 'indie', 'rock' ),
			GenreSync::decode_genre_slugs( array( 'indie', 'rock' ) )
		);
	}

	public function test_decode_comma_separated_string(): void {
		$this->assertSame(
			array( 'hip-hop', 'rnb' ),
			GenreSync::decode_genre_slugs( 'hip-hop, rnb' )
		);
	}

	public function test_decode_garbage_returns_empty_list(): void {
		$this->assertSame( array(), GenreSync::decode_genre_slugs( null ) );
		$this->assertSame( array(), GenreSync::decode_genre_slugs( false ) );
		$this->assertSame( array(), GenreSync::decode_genre_slugs( 42 ) );
		$this->assertSame( array(), GenreSync::decode_genre_slugs( '' ) );
		$this->assertSame( array(), GenreSync::decode_genre_slugs( array() ) );
	}

	public function test_decode_plain_text_is_a_slug_candidate_vocabulary_applies_downstream(): void {
		// decode is purely syntactic; only resolvable vocabulary terms are ever assigned.
		$this->assertSame( array( 'not-json-at-all' ), GenreSync::decode_genre_slugs( 'not json at all' ) );
	}

	public function test_decode_dedupes_and_sanitizes(): void {
		$this->assertSame(
			array( 'punk', 'emo' ),
			GenreSync::decode_genre_slugs( array( 'Punk', 'punk', 'emo', 'Punk ' ) )
		);
	}

	public function test_decode_skips_non_string_entries(): void {
		$this->assertSame(
			array( 'jazz' ),
			GenreSync::decode_genre_slugs( array( 'jazz', 7, null, array( 'nope' ) ) )
		);
	}

	// --- is_import_locked_taxonomy() ---

	public function test_genre_on_events_post_type_is_locked(): void {
		$this->assertTrue( GenreSync::is_import_locked_taxonomy( 'genre', 'data_machine_events' ) );
	}

	public function test_genre_on_other_post_types_is_not_locked(): void {
		$this->assertFalse( GenreSync::is_import_locked_taxonomy( 'genre', 'post' ) );
		$this->assertFalse( GenreSync::is_import_locked_taxonomy( 'genre', null ) );
	}

	public function test_other_taxonomies_on_events_post_type_are_not_locked(): void {
		$this->assertFalse( GenreSync::is_import_locked_taxonomy( 'artist', 'data_machine_events' ) );
		$this->assertFalse( GenreSync::is_import_locked_taxonomy( 'location', 'data_machine_events' ) );
		$this->assertFalse( GenreSync::is_import_locked_taxonomy( '', 'data_machine_events' ) );
	}

	// --- strip_genre_tool_param() ---

	public function test_strip_removes_genre_property_and_required_entry(): void {
		$tools = array(
			'upsert_event' => array(
				'parameters' => array(
					'type'       => 'object',
					'properties' => array(
						'title'  => array( 'type' => 'string' ),
						'genre'  => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
						'artist' => array( 'type' => 'array' ),
					),
					'required'   => array( 'title', 'genre' ),
				),
			),
		);

		$stripped = GenreSync::strip_genre_tool_param( $tools );

		$this->assertArrayNotHasKey( 'genre', $stripped['upsert_event']['parameters']['properties'] );
		$this->assertSame( array( 'title' ), $stripped['upsert_event']['parameters']['required'] );
		$this->assertArrayHasKey( 'artist', $stripped['upsert_event']['parameters']['properties'] );
		$this->assertArrayHasKey( 'title', $stripped['upsert_event']['parameters']['properties'] );
	}

	public function test_strip_leaves_tools_without_upsert_event_untouched(): void {
		$tools = array(
			'fetch_url' => array( 'method' => 'handle_tool_call' ),
		);

		$this->assertSame( $tools, GenreSync::strip_genre_tool_param( $tools ) );
	}

	public function test_strip_handles_upsert_event_without_parameters(): void {
		$tools = array(
			'upsert_event' => array(
				'class'  => 'SomeClass',
				'method' => 'handle_tool_call',
			),
		);

		$this->assertSame( $tools, GenreSync::strip_genre_tool_param( $tools ) );
	}

	public function test_strip_survives_missing_required_key(): void {
		$tools = array(
			'upsert_event' => array(
				'parameters' => array(
					'type'       => 'object',
					'properties' => array(
						'genre' => array( 'type' => 'array' ),
					),
				),
			),
		);

		$stripped = GenreSync::strip_genre_tool_param( $tools );

		$this->assertArrayNotHasKey( 'genre', $stripped['upsert_event']['parameters']['properties'] );
		$this->assertArrayNotHasKey( 'required', $stripped['upsert_event']['parameters'] );
	}
}
