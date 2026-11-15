<?php
/** Standalone registration metadata regression test for extrachill#1506. */

// Tokenize rather than load command classes: no WordPress or WP-CLI required,
// and public helper methods without command docblocks are not CLI commands.
$commands = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( dirname( __DIR__ ) . '/inc/Commands' ) );
$checked  = array();

foreach ( $commands as $file ) {
	if ( 'php' !== $file->getExtension() ) {
		continue;
	}

	$doc = null;
	$public = false;
	$static = false;
	$tokens = token_get_all( file_get_contents( $file->getPathname() ) );
	foreach ( $tokens as $index => $token ) {
		if ( ! is_array( $token ) ) {
			continue;
		}
		if ( T_DOC_COMMENT === $token[0] ) {
			$doc = $token[1];
		} elseif ( T_PUBLIC === $token[0] ) {
			$public = true;
		} elseif ( T_STATIC === $token[0] ) {
			$static = true;
		} elseif ( T_PRIVATE === $token[0] || T_PROTECTED === $token[0] ) {
			$public = false;
		} elseif ( T_FUNCTION === $token[0] ) {
			$name = $index + 1;
			while ( isset( $tokens[ $name ] ) && is_array( $tokens[ $name ] ) && T_WHITESPACE === $tokens[ $name ][0] ) {
				++$name;
			}
			$method = is_array( $tokens[ $name ] ) && T_STRING === $tokens[ $name ][0] ? $tokens[ $name ][1] : '';
			if ( $public && ! $static && $doc && false !== strpos( $method, '_' ) && 0 !== strpos( $method, '__' ) ) {
				$expected = str_replace( '_', '-', rtrim( $method, '_' ) );
				if ( ! preg_match( '/@subcommand\s+([^\s*]+)/', $doc, $matches ) || ! preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $matches[1] ) ) {
					throw new RuntimeException( $file->getFilename() . '::' . $method . ' needs @subcommand ' . $expected );
				}
				$checked[ $file->getFilename() . '::' . $method ] = $matches[1];
			}
			$doc = null;
			$public = false;
			$static = false;
		}
	}
}

foreach ( array(
	'MigratePostCommand.php::migrate_post' => 'migrate-post',
	'NewsletterCommand.php::push_campaign' => 'push-campaign',
	'NewsletterCommand.php::send_campaign' => 'send-campaign',
	'NewsletterCommand.php::delete_campaign' => 'delete-campaign',
	'NewsletterCommand.php::subscriber_status' => 'subscriber-status',
) as $method => $expected ) {
	if ( ( $checked[ $method ] ?? null ) !== $expected ) {
		throw new RuntimeException( 'Missing command coverage: ' . $method );
	}
}

fwrite( STDOUT, 'Subcommand annotation tests passed (' . count( $checked ) . " methods).\n" );
