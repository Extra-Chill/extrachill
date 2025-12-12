<?php
/**
 * Extra Chill Docs Uploader
 * Uploads user-facing markdown documentation to docs.extrachill.com.
 * Usage: php upload.php <docs_directory> [--force]
 */

require_once __DIR__ . '/WordPressClient.php';
require_once __DIR__ . '/FileFinder.php';

// Check arguments
if ( $argc < 2 ) {
	die( "Usage: php upload.php <docs_directory> [--force]\n" );
}

$docs_dir = rtrim( $argv[1], '/' );
$force    = isset( $argv[2] ) && $argv[2] === '--force';

if ( ! is_dir( $docs_dir ) ) {
	die( "Error: Directory not found: $docs_dir\n" );
}

try {
	$client = new WordPressClient();
} catch ( Exception $e ) {
	die( "Initialization Error: " . $e->getMessage() . "\n" );
}

echo "Starting sync from: $docs_dir\n";

// Iterate through platform subdirectories
$platforms = glob( $docs_dir . '/*', GLOB_ONLYDIR );

foreach ( $platforms as $platform_dir ) {
	$platform_slug = basename( $platform_dir );
	echo "\nProcessing Platform: $platform_slug\n";

	$files = FileFinder::find( $platform_dir, 'md' );

	foreach ( $files as $file_path ) {
		$relative_path = substr( $file_path, strlen( $docs_dir ) + 1 );
		$content       = file_get_contents( $file_path );
		$filesize      = filesize( $file_path );
		$timestamp     = filemtime( $file_path );
		$slug          = basename( $file_path, '.md' );

		// Title: H1 header > filename slug
		$title = ucfirst( str_replace( '-', ' ', $slug ) );

		// Extract H1 header as title and strip from content
		if ( preg_match( '/^#\s+(.+)$/m', $content, $h1_match ) ) {
			$title   = trim( $h1_match[1] );
			$content = trim( preg_replace( '/^#\s+.+$/m', '', $content, 1 ) );
		}

		echo "  Syncing: $relative_path... ";

		try {
			$response = $client->request( 'POST', '/wp-json/extrachill/v1/sync/doc', [
				'slug'          => $slug,
				'title'         => $title,
				'content'       => $content,
				'platform_slug' => $platform_slug,
				'filesize'      => $filesize,
				'timestamp'     => date( 'c', $timestamp ),
				'force'         => $force,
				'source_file'   => $relative_path,
			] );

			if ( isset( $response['success'] ) && $response['success'] ) {
				$action = $response['action'] ?? 'updated';
				echo "OK ($action)\n";
			} else {
				echo "FAILED\n";
			}
		} catch ( Exception $e ) {
			echo "ERROR: " . $e->getMessage() . "\n";
		}
	}
}

echo "\nSync Complete.\n";
