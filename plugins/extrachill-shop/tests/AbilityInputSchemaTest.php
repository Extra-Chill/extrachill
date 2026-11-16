<?php
/**
 * Every Shop-owned ability declares a closed input schema.
 *
 * Loads every plugin file that calls `wp_register_ability()` (in a separate
 * process, so the real definitions never collide with other tests' stubs),
 * fires `wp_abilities_api_init`, and checks each registration.
 *
 * @package ExtraChillShop
 */

use PHPUnit\Framework\TestCase;

/** Contract: Shop ability input schemas are objects with additionalProperties false. */
final class AbilityInputSchemaTest extends TestCase {

	/**
	 * Register every Shop ability and return the captured registrations.
	 *
	 * @return array<string, array> Ability name => registration args.
	 */
	private static function registered_abilities(): array {
		// The bootstrap already loaded some ability files, so their
		// `wp_abilities_api_init` callbacks stay queued alongside the new ones.
		$GLOBALS['test_abilities'] = array();

		$plugin = dirname( __DIR__ );
		$files  = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $plugin . '/inc', FilesystemIterator::SKIP_DOTS ) );
		$owners = array( $plugin . '/inc/abilities/register.php' );
		foreach ( $files as $file ) {
			if ( 'php' === $file->getExtension() && str_contains( (string) file_get_contents( $file->getPathname() ), 'wp_register_ability(' ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local plugin source.
				$owners[] = $file->getPathname();
			}
		}
		foreach ( $owners as $owner ) {
			require_once $owner;
		}

		foreach ( $GLOBALS['test_actions']['wp_abilities_api_init'] ?? array() as $action ) {
			call_user_func( $action['callback'] );
		}

		return $GLOBALS['test_abilities'];
	}

	/**
	 * Every registration is an object schema with additionalProperties false.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_every_shop_ability_input_schema_is_closed(): void {
		$abilities = self::registered_abilities();

		$this->assertGreaterThanOrEqual( 29, count( $abilities ) );
		foreach ( array( 'extrachill/shop-create-product', 'extrachill/shop-select-seller', 'extrachill/shop-replay-webhook', 'extrachill/start-ticket-checkout' ) as $known ) {
			$this->assertArrayHasKey( $known, $abilities );
		}

		foreach ( $abilities as $name => $args ) {
			$schema = $args['input_schema'] ?? null;
			$this->assertIsArray( $schema, $name . ' declares an input schema.' );
			$this->assertSame( 'object', $schema['type'] ?? null, $name . ' input schema is an object.' );
			$this->assertArrayHasKey( 'additionalProperties', $schema, $name . ' declares additionalProperties.' );
			$this->assertFalse( $schema['additionalProperties'], $name . ' input schema is closed.' );
			foreach ( $schema['required'] ?? array() as $required ) {
				$this->assertArrayHasKey( $required, $schema['properties'] ?? array(), $name . ' declares required property ' . $required . '.' );
			}
		}
	}

	/**
	 * The shop manager creates products with `status: 'draft'`; the closed
	 * create schema must keep accepting it.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_create_product_accepts_the_draft_status_the_shop_manager_sends(): void {
		$abilities = self::registered_abilities();
		$status    = $abilities['extrachill/shop-create-product']['input_schema']['properties']['status'] ?? null;

		$this->assertIsArray( $status );
		$this->assertSame( array( 'draft' ), $status['enum'] );
	}
}
