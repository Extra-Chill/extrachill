<?php
/**
 * Tests for the pure content-rewrite / attachment-id remap logic used by the
 * cross-site content migration primitive.
 *
 * Covers the two pure (WP-free) functions in
 * inc/core/cross-site-content-migration.php:
 *   - ec_migrate_extract_referenced_ids() — pulls attachment IDs out of block
 *     markup and inline HTML.
 *   - ec_migrate_rewrite_post_content()   — remaps old attachment IDs and URLs
 *     to their new dest equivalents.
 *
 * These are the load-bearing correctness surface of the migration: if the
 * remap is wrong, a migrated post silently points at the source blog's media.
 * Both functions are pure strings-in / strings-out, so this suite runs without
 * any DB writes, switch_to_blog(), or file I/O.
 *
 * @package ExtraChill\Network
 */

declare( strict_types=1 );

/**
 * Content-rewrite / id-remap tests.
 *
 * @group cross-site-migration
 */
class CrossSiteContentMigrationRewriteTest extends WP_UnitTestCase {

	/**
	 * Ensure the pure functions are loaded (the file loads unconditionally in
	 * production; require it here so the suite is self-contained).
	 */
	public static function set_up_before_class() {
		parent::set_up_before_class();

		$file = dirname( __DIR__ ) . '/inc/core/cross-site-content-migration.php';
		if ( file_exists( $file ) ) {
			require_once $file;
		}
	}

	/**
	 * Image block `"id":N` is extracted.
	 */
	public function test_extract_image_block_id(): void {
		$content = '<!-- wp:image {"id":42,"sizeSlug":"large"} --><figure class="wp-block-image size-large"><img src="x.jpg" class="wp-image-42"/></figure><!-- /wp:image -->';
		$ids     = ec_migrate_extract_referenced_ids( $content );

		$this->assertContains( 42, $ids );
	}

	/**
	 * Gallery `"ids":[...]` arrays are extracted individually.
	 */
	public function test_extract_gallery_ids_array(): void {
		$content = '<!-- wp:gallery {"ids":[10,11,12]} -->';
		$ids     = ec_migrate_extract_referenced_ids( $content );

		$this->assertContains( 10, $ids );
		$this->assertContains( 11, $ids );
		$this->assertContains( 12, $ids );
	}

	/**
	 * The wp-image-N classes and data-id="N" wrappers are extracted.
	 */
	public function test_extract_wp_image_class_and_data_id(): void {
		$content = '<img class="wp-image-77" /><li data-id="88"></li>';
		$ids     = ec_migrate_extract_referenced_ids( $content );

		$this->assertContains( 77, $ids );
		$this->assertContains( 88, $ids );
	}

	/**
	 * Empty content yields no ids.
	 */
	public function test_extract_empty_content(): void {
		$this->assertSame( array(), ec_migrate_extract_referenced_ids( '' ) );
	}

	/**
	 * Image block id + wp-image class both remap to the new id.
	 */
	public function test_rewrite_image_block_and_class(): void {
		$content = '<!-- wp:image {"id":42,"sizeSlug":"large"} --><figure class="wp-block-image size-large"><img src="x.jpg" class="wp-image-42"/></figure><!-- /wp:image -->';
		$out     = ec_migrate_rewrite_post_content( $content, array( 42 => 500 ), array() );

		$this->assertStringContainsString( '"id":500', $out );
		$this->assertStringContainsString( 'wp-image-500', $out );
		$this->assertStringNotContainsString( '"id":42', $out );
		$this->assertStringNotContainsString( 'wp-image-42', $out );
	}

	/**
	 * Gallery ids array remaps every member.
	 */
	public function test_rewrite_gallery_ids_array(): void {
		$content = '<!-- wp:gallery {"ids":[10,11,12]} -->';
		$out     = ec_migrate_rewrite_post_content(
			$content,
			array(
				10 => 110,
				11 => 111,
				12 => 112,
			),
			array()
		);

		$this->assertStringContainsString( '110', $out );
		$this->assertStringContainsString( '111', $out );
		$this->assertStringContainsString( '112', $out );
		$this->assertStringNotContainsString( '[10,11,12]', $out );
	}

	/**
	 * Raw upload URLs (including the multisite `sites/<n>/` segment) are remapped.
	 */
	public function test_rewrite_raw_sites_url(): void {
		$old_url = 'https://studio.extrachill.com/wp-content/uploads/sites/12/2026/01/photo.jpg';
		$new_url = 'https://extrachill.com/wp-content/uploads/2026/01/photo.jpg';
		$content = '<img src="' . $old_url . '" class="wp-image-42" />';

		$out = ec_migrate_rewrite_post_content(
			$content,
			array( 42 => 500 ),
			array( $old_url => $new_url )
		);

		$this->assertStringContainsString( $new_url, $out );
		$this->assertStringNotContainsString( $old_url, $out );
		$this->assertStringContainsString( 'wp-image-500', $out );
	}

	/**
	 * The prefix swap rewrites sized/dedupe variants AND the multisite
	 * `sites/<n>/` prefix that an exact-URL map can never match.
	 *
	 * This is the regression case for issue #86: the ID tokens were remapped
	 * correctly but every `<img src>` still pointed at the source blog because
	 * the exact-URL map was keyed on the base full-size URL and never matched
	 * `-1024x683.jpg` / `-1.jpg` variants or the `sites/12/` prefix.
	 */
	public function test_rewrite_prefix_swap_sized_variants_and_sites_prefix(): void {
		$source_baseurl = 'https://studio.extrachill.com/wp-content/uploads/sites/12';
		$dest_baseurl   = 'https://extrachill.com/wp-content/uploads';

		// Mixed real-world markup: full-size, a -1024x683 sized variant, and a
		// -1.jpg dedupe-suffixed file — none of which an exact-URL map keyed on
		// the base full URL would have caught.
		$content = ''
			. '<!-- wp:image {"id":840,"sizeSlug":"large"} -->'
			. '<figure class="wp-block-image size-large">'
			. '<img src="https://studio.extrachill.com/wp-content/uploads/sites/12/2026/07/0L8A8809-1.jpg" class="wp-image-840"/>'
			. '</figure><!-- /wp:image -->'
			. '<img src="https://studio.extrachill.com/wp-content/uploads/sites/12/2026/07/0L8A8809-1-1024x683.jpg" />'
			. '<img srcset="https://studio.extrachill.com/wp-content/uploads/sites/12/2026/07/0L8A8809-1-768x512.jpg 768w" />';

		$out = ec_migrate_rewrite_post_content(
			$content,
			array( 840 => 110840 ),
			array(),
			$source_baseurl,
			$dest_baseurl
		);

		// Zero references to the source blog upload path/host remain.
		$this->assertStringNotContainsString( $source_baseurl, $out );
		$this->assertStringNotContainsString( 'studio.extrachill.com', $out );
		$this->assertStringNotContainsString( '/sites/12/', $out );

		// Every variant now points at the dest baseurl, preserving the subpath.
		$this->assertStringContainsString( $dest_baseurl . '/2026/07/0L8A8809-1.jpg', $out );
		$this->assertStringContainsString( $dest_baseurl . '/2026/07/0L8A8809-1-1024x683.jpg', $out );
		$this->assertStringContainsString( $dest_baseurl . '/2026/07/0L8A8809-1-768x512.jpg', $out );

		// The ID token was still remapped (orthogonal to the URL swap).
		$this->assertStringContainsString( '"id":110840', $out );
		$this->assertStringContainsString( 'wp-image-110840', $out );
		$this->assertStringNotContainsString( 'wp-image-840"', $out );
	}

	/**
	 * The prefix swap also covers the single-site (non-subdir) case where the
	 * source baseurl has NO `sites/<n>/` segment.
	 */
	public function test_rewrite_prefix_swap_single_site_non_subdir(): void {
		$source_baseurl = 'https://blog.extrachill.com/wp-content/uploads';
		$dest_baseurl   = 'https://extrachill.com/wp-content/uploads';

		$content = '<img src="https://blog.extrachill.com/wp-content/uploads/2025/12/pic-300x200.jpg" class="wp-image-9" />';

		$out = ec_migrate_rewrite_post_content(
			$content,
			array( 9 => 42 ),
			array(),
			$source_baseurl,
			$dest_baseurl
		);

		$this->assertStringContainsString( 'https://extrachill.com/wp-content/uploads/2025/12/pic-300x200.jpg', $out );
		$this->assertStringNotContainsString( 'blog.extrachill.com', $out );
		$this->assertStringContainsString( 'wp-image-42', $out );
	}

	/**
	 * An empty source baseurl disables the prefix swap (no accidental
	 * whole-content mangling when a baseurl can't be resolved).
	 */
	public function test_rewrite_prefix_swap_disabled_when_baseurl_empty(): void {
		$content = '<img src="https://studio.extrachill.com/wp-content/uploads/sites/12/2026/07/x.jpg" />';
		$out     = ec_migrate_rewrite_post_content( $content, array(), array(), '', '' );

		$this->assertSame( $content, $out );
	}

	/**
	 * A short id must NOT clobber a longer id that contains it (12 vs 123).
	 */
	public function test_rewrite_id_boundaries_no_substring_clobber(): void {
		$content = '<!-- wp:image {"id":12} --><img class="wp-image-123" />';
		$out     = ec_migrate_rewrite_post_content(
			$content,
			array(
				12  => 900,
				123 => 901,
			),
			array()
		);

		$this->assertStringContainsString( '"id":900', $out );
		$this->assertStringContainsString( 'wp-image-901', $out );
		// The 12 remap must not have turned 123 into 9003 or similar.
		$this->assertStringNotContainsString( 'wp-image-9003', $out );
		$this->assertStringNotContainsString( 'wp-image-12', $out );
	}

	/**
	 * A remap where the new id equals another old id must not chain-remap.
	 * old 5 -> new 6, old 6 -> new 7. The block with id 5 must land on 6,
	 * NOT get re-caught by the 6->7 rule and become 7.
	 */
	public function test_rewrite_no_chained_remap(): void {
		$content = '<!-- wp:image {"id":5} --><!-- wp:image {"id":6} -->';
		$out     = ec_migrate_rewrite_post_content(
			$content,
			array(
				5 => 6,
				6 => 7,
			),
			array()
		);

		$this->assertStringContainsString( '"id":6', $out );
		$this->assertStringContainsString( '"id":7', $out );
		// Exactly one occurrence each — the 5->6 result was not re-caught by 6->7.
		$this->assertSame( 1, substr_count( $out, '"id":6' ) );
		$this->assertSame( 1, substr_count( $out, '"id":7' ) );
	}

	/**
	 * Empty maps are a no-op.
	 */
	public function test_rewrite_empty_maps_noop(): void {
		$content = '<!-- wp:image {"id":42} -->';
		$this->assertSame( $content, ec_migrate_rewrite_post_content( $content, array(), array() ) );
	}

	/**
	 * The prefix swap is host-agnostic: a source baseurl that resolved onto
	 * the request host (WP-CLI `--url=<other site>`) still rewrites content
	 * stored under the source site's OWN host.
	 *
	 * Regression case for issue #286: migrating community (blog 2) -> main
	 * (blog 1) under `--url=https://extrachill.com` resolved the source
	 * baseurl to `https://extrachill.com/wp-content/uploads/sites/2` while
	 * content stored `https://community.extrachill.com/wp-content/uploads/sites/2/...`,
	 * so the literal baseurl swap matched nothing and every sized `<img src>`
	 * stayed on the source host.
	 */
	public function test_rewrite_prefix_swap_host_agnostic_wrong_host_baseurl(): void {
		// What wp_get_upload_dir() resolves under CLI with --url=extrachill.com:
		// right path, wrong (request) host.
		$source_baseurl = 'https://extrachill.com/wp-content/uploads/sites/2';
		$dest_baseurl   = 'https://extrachill.com/wp-content/uploads';

		$content = ''
			. '<!-- wp:image {"id":14324,"sizeSlug":"large"} -->'
			. '<figure class="wp-block-image size-large">'
			. '<img src="https://community.extrachill.com/wp-content/uploads/sites/2/2026/09/2O3A2105-1024x683.jpg" class="wp-image-14324"/>'
			. '</figure><!-- /wp:image -->';

		$out = ec_migrate_rewrite_post_content( $content, array( 14324 => 111061 ), array(), $source_baseurl, $dest_baseurl );

		$this->assertStringNotContainsString( 'community.extrachill.com', $out );
		$this->assertStringNotContainsString( '/sites/2/', $out );
		$this->assertStringContainsString( 'https://extrachill.com/wp-content/uploads/2026/09/2O3A2105-1024x683.jpg', $out );
		$this->assertStringContainsString( 'wp-image-111061', $out );
	}

	/**
	 * A `-1` dedupe-suffixed filename survives the host-agnostic prefix swap
	 * intact — the swap only replaces the baseurl prefix, never the file
	 * name, so `photo-1.jpg` (and its `-1-1024x683.jpg` sized variant) land
	 * under the dest baseurl unchanged.
	 */
	public function test_rewrite_prefix_swap_preserves_dedupe_suffixed_filename(): void {
		$source_baseurl = 'https://community.extrachill.com/wp-content/uploads/sites/2';
		$dest_baseurl   = 'https://extrachill.com/wp-content/uploads';

		$content = '<img src="https://community.extrachill.com/wp-content/uploads/sites/2/2026/07/0L8A8809-1.jpg" />'
			. '<img srcset="https://community.extrachill.com/wp-content/uploads/sites/2/2026/07/0L8A8809-1-1024x683.jpg 1024w" />';

		$out = ec_migrate_rewrite_post_content( $content, array(), array(), $source_baseurl, $dest_baseurl );

		$this->assertStringContainsString( $dest_baseurl . '/2026/07/0L8A8809-1.jpg', $out );
		$this->assertStringContainsString( $dest_baseurl . '/2026/07/0L8A8809-1-1024x683.jpg 1024w', $out );
		$this->assertStringNotContainsString( 'sites/2', $out );
	}

	/**
	 * The host-agnostic swap only touches URLs under the source upload path:
	 * foreign non-uploads URLs, a different site's `sites/<n>/` path, a path
	 * merely EMBEDDED deeper in a foreign URL, and a `2.jpg` file segment
	 * (site 2 != file `2.jpg`) must all survive untouched.
	 */
	public function test_rewrite_prefix_swap_non_uploads_urls_untouched(): void {
		$source_baseurl = 'https://community.extrachill.com/wp-content/uploads/sites/2';
		$dest_baseurl   = 'https://extrachill.com/wp-content/uploads';

		$content = ''
			. '<a href="https://www.youtube.com/watch?v=abc123">video</a>'
			. '<img src="https://community.extrachill.com/wp-content/uploads/sites/23/2026/09/other-site.jpg" />'
			. '<img src="https://example.com/blog/wp-content/uploads/sites/2/2026/09/embedded.jpg" />'
			. '<img src="https://community.extrachill.com/wp-content/uploads/sites/2.jpg/not-a-site.jpg" />';

		$out = ec_migrate_rewrite_post_content( $content, array(), array(), $source_baseurl, $dest_baseurl );

		$this->assertStringContainsString( 'https://www.youtube.com/watch?v=abc123', $out );
		$this->assertStringContainsString( 'https://community.extrachill.com/wp-content/uploads/sites/23/2026/09/other-site.jpg', $out );
		$this->assertStringContainsString( 'https://example.com/blog/wp-content/uploads/sites/2/2026/09/embedded.jpg', $out );
		$this->assertStringContainsString( 'https://community.extrachill.com/wp-content/uploads/sites/2.jpg/not-a-site.jpg', $out );
	}

	/**
	 * Protocol-relative (`//host/...`) and root-relative (`/wp-content/...`)
	 * URL forms are rewritten to the full dest baseurl too.
	 */
	public function test_rewrite_prefix_swap_protocol_relative_and_root_relative(): void {
		$source_baseurl = 'https://community.extrachill.com/wp-content/uploads/sites/2';
		$dest_baseurl   = 'https://extrachill.com/wp-content/uploads';

		$content = '<img src="//community.extrachill.com/wp-content/uploads/sites/2/2026/01/proto.jpg" />'
			. '<img src="/wp-content/uploads/sites/2/2026/01/root.jpg" />';

		$out = ec_migrate_rewrite_post_content( $content, array(), array(), $source_baseurl, $dest_baseurl );

		$this->assertStringContainsString( $dest_baseurl . '/2026/01/proto.jpg', $out );
		$this->assertStringContainsString( $dest_baseurl . '/2026/01/root.jpg', $out );
		$this->assertStringNotContainsString( 'sites/2', $out );
	}

	/**
	 * A main-site (non-subdir) source prefix must never swallow a subsite's
	 * `/sites/<n>/` path: `/wp-content/uploads` is a strict prefix of every
	 * subsite upload URL, and those belong to other blogs.
	 */
	public function test_rewrite_prefix_swap_main_site_source_does_not_swallow_subsite_paths(): void {
		$source_baseurl = 'https://extrachill.com/wp-content/uploads';
		$dest_baseurl   = 'https://studio.extrachill.com/wp-content/uploads/sites/12';

		$content = '<img src="https://community.extrachill.com/wp-content/uploads/sites/2/2026/09/subsite.jpg" />'
			. '<img src="https://extrachill.com/wp-content/uploads/2026/09/main.jpg" />';

		$out = ec_migrate_rewrite_post_content( $content, array(), array(), $source_baseurl, $dest_baseurl );

		$this->assertStringContainsString( 'https://community.extrachill.com/wp-content/uploads/sites/2/2026/09/subsite.jpg', $out );
		$this->assertStringContainsString( $dest_baseurl . '/2026/09/main.jpg', $out );
	}

	/**
	 * Post-migration verification: leftover source upload URLs in dest
	 * content are reported in full (host + path + filename), distinctly.
	 */
	public function test_find_source_upload_urls_detects_leftovers(): void {
		$baseurl = 'https://extrachill.com/wp-content/uploads/sites/2'; // Wrong-host resolution — path is what matters.

		$content = 'Rewritten: <img src="https://extrachill.com/wp-content/uploads/2026/09/good-1024x683.jpg" />'
			. ' Leftover: <img src="https://community.extrachill.com/wp-content/uploads/sites/2/2026/09/bad-1024x683.jpg" />'
			. ' Other site: <img src="https://community.extrachill.com/wp-content/uploads/sites/23/not-ours.jpg" />';

		$found = ec_migrate_find_source_upload_urls( $content, $baseurl );

		$this->assertSame(
			array( 'https://community.extrachill.com/wp-content/uploads/sites/2/2026/09/bad-1024x683.jpg' ),
			$found
		);
	}

	/**
	 * Post-migration verification: clean dest content (fully rewritten, other
	 * sites' URLs, or a main-site source that must not flag `/sites/<n>/`
	 * paths) yields no false positives.
	 */
	public function test_find_source_upload_urls_no_false_positives(): void {
		$clean = '<img src="https://extrachill.com/wp-content/uploads/2026/09/good.jpg" />'
			. '<img src="https://community.extrachill.com/wp-content/uploads/sites/23/other-site.jpg" />';
		$this->assertSame(
			array(),
			ec_migrate_find_source_upload_urls( $clean, 'https://community.extrachill.com/wp-content/uploads/sites/2' )
		);

		// A main-site source path (/wp-content/uploads) must not flag dest
		// content that legitimately lives under /wp-content/uploads/sites/<n>/.
		$subsite = '<img src="https://extrachill.com/wp-content/uploads/sites/12/2026/09/dest-own.jpg" />';
		$this->assertSame(
			array(),
			ec_migrate_find_source_upload_urls( $subsite, 'https://extrachill.com/wp-content/uploads' )
		);

		$this->assertSame( array(), ec_migrate_find_source_upload_urls( '', 'https://extrachill.com/wp-content/uploads' ) );
	}
}
