<?php
/** Source-level guard: optional object ability inputs must normalize null to array(). */

$root    = dirname( __DIR__ );
$files   = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/inc' ) );
$checked = 0;
$schemas = 0;

foreach ( $files as $file ) {
	if ( 'php' !== $file->getExtension() ) {
		continue;
	}

	// Strip comments and whitespace without confusing strings with PHP syntax.
	$tokens = array_values(
		array_filter(
			token_get_all( file_get_contents( $file->getPathname() ) ),
			static function ( $token ) {
				return ! is_array( $token ) || ! in_array( $token[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true );
			}
		)
	);

	foreach ( $tokens as $index => $token ) {
		if ( ! is_array( $token ) || T_CONSTANT_ENCAPSED_STRING !== $token[0] || 'input_schema' !== trim( $token[1], "'\"" ) ) {
			continue;
		}

		++$schemas;
		$start = $index + 3;
		if ( ! isset( $tokens[ $start ] ) || '(' !== $tokens[ $start ] ) {
			fwrite( STDERR, "FAIL: Expected a literal input schema array in {$file->getPathname()}.\n" );
			exit( 1 );
		}

		// Split only at the schema's own commas, not nested property arrays.
		$depth   = 1;
		$entries = array();
		$entry   = '';
		for ( $i = $start + 1; $i < count( $tokens ); ++$i ) {
			$part = is_array( $tokens[ $i ] ) ? $tokens[ $i ][1] : $tokens[ $i ];
			if ( ! is_array( $tokens[ $i ] ) ) {
				if ( '(' === $part || '[' === $part ) {
					++$depth;
				} elseif ( ')' === $part || ']' === $part ) {
					--$depth;
				}
			}
			if ( 0 === $depth || ( 1 === $depth && ',' === $part ) ) {
				if ( preg_match( '/^[\'\"]([^\'\"]+)[\'\"]\s*=>(.*)$/s', $entry, $match ) ) {
					$entries[ $match[1] ] = $match[2];
				}
				$entry = '';
				if ( 0 === $depth ) {
					break;
				}
			} else {
				$entry .= $part;
			}
		}

		if ( ! in_array( $entries['type'] ?? '', array( "'object'", '"object"' ), true ) || ! in_array( $entries['required'] ?? 'array()', array( 'array()', '[]' ), true ) ) {
			continue;
		}

		++$checked;
		if ( 'array()' !== ( $entries['default'] ?? null ) ) {
			fwrite( STDERR, "FAIL: Optional object input schema at {$file->getPathname()}:{$token[2]} must declare default => array().\n" );
			exit( 1 );
		}
	}
}

if ( $checked < 6 || $schemas <= $checked ) {
	fwrite( STDERR, "FAIL: Expected coverage of optional and required ability schemas.\n" );
	exit( 1 );
}

echo "PASS: All {$checked} optional object inputs declare empty-array defaults ({$schemas} ability schemas inspected).\n";
