<?php
/**
 * Regression coverage for optional object input defaults (issue #79).
 *
 * @package ExtraChill\SEO\Tests
 */

namespace ExtraChill\SEO\Abilities {
	/** Capture registrations without executing callbacks or requiring WordPress. */
	function wp_register_ability( $name, $args ) {
		\ExtraChill\SEO\Tests\Unit\Abilities\OptionalInputSchemaTest::$abilities[ $name ] = $args;
	}

	/** Translation stub for registration metadata. */
	function __( $text, $domain ) {
		return $text;
	}
}

namespace ExtraChill\SEO\Tests\Unit\Abilities {
	use ExtraChill\SEO\Abilities\SEO_Abilities;
	use PHPUnit\Framework\TestCase;

	final class OptionalInputSchemaTest extends TestCase {
		public static array $abilities = array();

		public function test_every_optional_object_input_has_an_empty_array_default(): void {
			require_once __DIR__ . '/../../../inc/abilities/class-seo-abilities.php';
			require_once __DIR__ . '/../../../inc/abilities/redirect-abilities.php';
			require_once __DIR__ . '/../../../inc/abilities/redirect-conversion-abilities.php';
			require_once __DIR__ . '/../../../inc/abilities/redirect-opportunities.php';

			self::$abilities = array();
			( new SEO_Abilities() )->register_abilities();
			$this->assertNotEmpty( self::$abilities );

			$optional = array();
			foreach ( self::$abilities as $name => $args ) {
				$schema = $args['input_schema'] ?? array();
				if ( 'object' !== ( $schema['type'] ?? null ) || ! empty( $schema['required'] ) ) {
					continue;
				}

				$optional[] = $name;
				// Core normalizes omitted/null input only when the schema has a default.
				$this->assertArrayHasKey( 'default', $schema, $name );
				$this->assertSame( array(), $schema['default'], $name );
			}

			$this->assertCount( 9, $optional );
			$this->assertSame( array( 'url' ), self::$abilities['extrachill/analyze-url']['input_schema']['required'] );
			$this->assertSame( array( 'from_url', 'to_url' ), self::$abilities['extrachill-seo/add-redirect']['input_schema']['required'] );
			$this->assertSame( array( 'id' ), self::$abilities['extrachill-seo/delete-redirect']['input_schema']['required'] );
		}
	}
}
