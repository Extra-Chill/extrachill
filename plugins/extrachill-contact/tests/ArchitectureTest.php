<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ArchitectureTest extends TestCase {
	public function test_contact_plugin_reuses_owner_primitives(): void {
		$root   = dirname( __DIR__ );
		$email  = file_get_contents( $root . '/includes/email-functions.php' );
		$submit = file_get_contents( $root . '/inc/abilities/contact-submit.php' );
		$source = $email . $submit;

		self::assertStringContainsString( 'ec_send_email( $args )', $email );
		self::assertStringContainsString( "extrachill_network_subscribe( \$email, 'contact', \$source_url, \$name )", $email );
		self::assertStringNotContainsString( 'wp_mail(', $source );
		self::assertStringNotContainsString( 'register_rest_route', $source );
		self::assertStringNotContainsString( 'as_enqueue_', $source );
		self::assertStringNotContainsString( 'as_schedule_', $source );
		self::assertStringNotContainsString( 'CREATE TABLE', strtoupper( $source ) );
	}

	public function test_plugin_smoke_loads_the_canonical_ability(): void {
		self::assertTrue( function_exists( 'extrachill_contact_ability_submit' ) );
		self::assertTrue( function_exists( 'ec_contact_send_admin_email' ) );
	}
}
