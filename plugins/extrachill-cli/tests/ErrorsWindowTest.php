<?php
/** PHPUnit window parsing regression tests for #1655. */
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/ErrorsCommandTest.php';

final class ErrorsWindowTest extends TestCase {
	public function test_duration_parsing(): void {
		$command = new ExtraChill\CLI\Commands\Analytics\ErrorsCommand();
		$method = new ReflectionMethod( $command, 'parse_window' );
		foreach ( array( '15m' => 900, '1h' => 3600, '24h' => 86400, '7d' => 604800, '3' => 259200, 'all' => 0, '0' => 0, '0h' => 0, ' 15 M ' => 900, 'invalid' => 604800 ) as $window => $seconds ) {
			$this->assertSame( $seconds, $method->invoke( $command, $window ), (string) $window );
		}
	}

	public function test_counter_reports_applied_whole_hour_window(): void {
		global $errors_command_rest;
		WP_CLI::$messages = array();
		$command = new ExtraChill\CLI\Commands\Analytics\ErrorsCommand();
		$command( array(), array( 'source' => 'rest', 'since' => '15m' ) );
		$input = end( $errors_command_rest->inputs );
		$this->assertSame( 1, $input['hours'] );
		$this->assertStringContainsString( 'applied window: 1 hours', implode( "\n", WP_CLI::$messages ) );
	}

	public function test_cli_passes_exact_window_and_labels_empty_results(): void {
		global $errors_command_php;
		$errors_command_php->result = array( 'rows' => array(), 'window_note' => 'Daily counts cannot be split.' );
		WP_CLI::$messages = array();
		$command = new ExtraChill\CLI\Commands\Analytics\ErrorsCommand();
		$command( array(), array( 'source' => 'php', 'since' => '15m', 'severity' => 'fatal' ) );
		$input = end( $errors_command_php->inputs );
		$this->assertSame( 900, $input['seconds'] );
		$this->assertSame( 'fatal', $input['severity'] );
		$this->assertStringContainsString( '15 minutes', implode( "\n", WP_CLI::$messages ) );
		$this->assertStringContainsString( 'Daily counts cannot be split.', implode( "\n", WP_CLI::$messages ) );
	}
}
