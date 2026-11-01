<?php
/**
 * Platform Map Loader
 *
 * Parses runner-configs/platform-map.yml into the shared platform identity
 * array. Monorepo plugin keys are exposed as legacy OWNER/REPO identities
 * for existing publishing metadata and migration callers. The map identifies
 * each plugin's parent page on docs.extrachill.com:
 *
 *   - The ec_doc → page migration resolves destination parents from it.
 *   - The Docs Agent workflow reads each plugin's runner configuration.
 *
 * Entry shape:
 *   [
 *     'repo'         => 'Extra-Chill/extrachill-artist-platform',
 *     'parent_slug'  => 'artist-platform',
 *     'parent_title' => 'Artist Platform',
 *     'docs_subpath' => 'docs/user',
 *     'post_status'  => 'publish',
 *   ]
 *
 * @package ExtraChillDocs
 * @since   0.5.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Parse runner-configs/platform-map.yml into the platform-map array shape.
 *
 * Hand-written YAML parser scoped to the shape platform-map.yml uses.
 * Avoids adding a Symfony YAML dependency for one file we control.
 *
 * @since 0.5.0
 * @return array<int,array<string,string>>
 */
function extrachill_docs_load_platform_map(): array {
	$path = EXTRACHILL_DOCS_PLUGIN_DIR . 'runner-configs/platform-map.yml';
	if ( ! is_readable( $path ) ) {
		return array();
	}

	// Local plugin-bundled YAML read (not a remote URL); WP_Filesystem/wp_remote_get do not apply.
	$yaml = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	if ( false === $yaml ) {
		return array();
	}

	$lines = preg_split( '/\r\n|\r|\n/', $yaml );
	if ( false === $lines ) {
		return array();
	}

	$out          = array();
	$current_repo = null;
	$current      = array();
	$in_repos     = false;

	foreach ( $lines as $raw ) {
		// Drop comments and empty lines.
		$trimmed = trim( $raw );
		if ( '' === $trimmed || str_starts_with( $trimmed, '#' ) ) {
			continue;
		}

		// Top-level 'plugins:' marker.
		if ( ! $in_repos ) {
			if ( preg_match( '/^plugins:\s*$/', $trimmed ) ) {
				$in_repos = true;
			}
			continue;
		}

		// Two-space-indented monorepo key: 'plugins/plugin-name:'.
		if ( preg_match( '/^  ([^\s:][^:]*):\s*$/', $raw, $matches ) ) {
			extrachill_docs_flush_platform_map_entry( $out, $current_repo, $current );
			$plugin_path  = trim( $matches[1] );
			$current_repo = preg_match( '#^plugins/([a-z0-9-]+)$#', $plugin_path, $plugin_match )
				? 'Extra-Chill/' . $plugin_match[1]
				: null;
			$current      = array();
			continue;
		}

		// Only identity fields at four spaces; nested runner settings are ignored.
		if ( null !== $current_repo && preg_match( '/^    ([a-z_]+):\s*"?([^"\n]*)"?\s*$/', $raw, $matches ) ) {
			$key   = $matches[1];
			$value = trim( $matches[2], " \t\"'" );
			if ( '' !== $key && '' !== $value ) {
				$current[ $key ] = $value;
			}
		}
	}

	extrachill_docs_flush_platform_map_entry( $out, $current_repo, $current );

	return $out;
}

/**
 * Helper for the YAML parser: push a completed entry onto the output.
 *
 * @since 0.5.0
 *
 * @param array<int,array<string,string>> $out          Accumulator (by-ref).
 * @param string|null                     $current_repo Repo identifier collected so far.
 * @param array<string,string>            $current      Fields collected for the current repo.
 * @return void
 */
function extrachill_docs_flush_platform_map_entry( array &$out, ?string $current_repo, array $current ): void {
	if ( null === $current_repo || '' === $current_repo ) {
		return;
	}

	$parent_slug  = $current['parent_slug'] ?? '';
	$platform     = $current['platform_name'] ?? '';
	$docs_subpath = $current['docs_subpath'] ?? 'docs/user';
	$post_status  = isset( $current['post_status'] ) && 'private' === $current['post_status'] ? 'private' : 'publish';

	if ( '' === $parent_slug || '' === $platform ) {
		return;
	}

	$out[] = array(
		'repo'         => $current_repo,
		'parent_slug'  => $parent_slug,
		'parent_title' => $platform,
		'docs_subpath' => $docs_subpath,
		'post_status'  => $post_status,
	);
}
