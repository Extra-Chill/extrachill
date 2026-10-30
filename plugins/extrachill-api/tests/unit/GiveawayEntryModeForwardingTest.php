<?php
/**
 * Giveaway routes must forward entry_mode to extrachill/run-giveaway.
 *
 * The ability owns how entries are counted (per_comment by default, or
 * per_user). Both giveaway routes accept the arg and forward it only when
 * the client sent it, so the ability default stays authoritative.
 *
 * @package ExtraChillAPI
 */

use PHPUnit\Framework\TestCase;

class GiveawayEntryModeForwardingTest extends TestCase {

	public function test_both_routes_accept_entry_mode_with_the_ability_enum() {
		$source = $this->giveaway_route_source();

		$this->assertSame(
			2,
			substr_count( $source, "'entry_mode'   => array(" ),
			'Both /giveaway/run and /giveaway/schedule must declare entry_mode.'
		);
		$this->assertSame(
			2,
			substr_count( $source, "'enum'        => array( 'per_comment', 'per_user' )," ),
			'entry_mode must be limited to the ability enum.'
		);
	}

	public function test_entry_mode_is_forwarded_only_when_sent() {
		$source = $this->giveaway_route_source();

		$this->assertStringContainsString(
			"if ( null !== \$entry_mode ) {\n\t\t\$input['entry_mode'] = \$entry_mode;",
			$source,
			'entry_mode must only be forwarded when present, so the ability owns the default.'
		);
		$this->assertStringContainsString(
			'$ability->execute( extrachill_api_giveaway_ability_input( $request ) )',
			$source,
			'The run route must build ability input through the shared mapper.'
		);
		$this->assertStringContainsString(
			'$params = extrachill_api_giveaway_ability_input( $request );',
			$source,
			'The schedule route must build task params through the shared mapper.'
		);
	}

	private function giveaway_route_source(): string {
		return (string) file_get_contents( dirname( __DIR__, 2 ) . '/inc/routes/giveaway/giveaway.php' );
	}
}
