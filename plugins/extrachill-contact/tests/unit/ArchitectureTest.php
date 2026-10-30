<?php

declare(strict_types=1);

class Test_Contact_Architecture extends WP_UnitTestCase {
	public function test_contact_plugin_reuses_owner_primitives(): void {
		$root   = dirname( __DIR__, 2 );
		$email  = file_get_contents( $root . '/includes/email-functions.php' );
		$submit = file_get_contents( $root . '/inc/abilities/contact-submit.php' );
		$source = $email . $submit;

		$this->assertStringContainsString( 'ec_send_email( $args )', $email );
		$this->assertStringContainsString( "extrachill_network_subscribe( \$email, 'contact', \$source_url, \$name )", $email );
		$this->assertStringNotContainsString( 'wp_mail(', $source );
		$this->assertStringNotContainsString( 'register_rest_route', $source );
		$this->assertStringNotContainsString( 'as_enqueue_', $source );
		$this->assertStringNotContainsString( 'as_schedule_', $source );
		$this->assertStringNotContainsString( 'CREATE TABLE', strtoupper( $source ) );
	}

	public function test_plugin_smoke_loads_the_canonical_ability(): void {
		$this->assertTrue( function_exists( 'extrachill_contact_ability_submit' ) );
		$this->assertTrue( function_exists( 'ec_contact_send_admin_email' ) );
	}
}
