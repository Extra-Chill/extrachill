<?php
/**
 * In-memory `agents/dispatch-message` stand-in for the fatal alarm tests.
 *
 * Records every dispatch input and answers per channel: a channel listed in
 * `$sent_channels` reports `sent: true`, one listed in `$error_channels`
 * returns a WP_Error, anything else reports `sent: false`. Used by
 * FatalRateAlarmDeliveryTest through the evaluator's dispatcher parameter.
 *
 * @package ExtraChill\Analytics
 */

/**
 * Fake dispatch-message ability with per-channel outcomes.
 */
final class Extrachill_Analytics_Fake_Dispatch_Message_Ability {

	/**
	 * Every input passed to execute(), in order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public $requests = array();

	/**
	 * Channels that report a successful send.
	 *
	 * @var string[]
	 */
	public $sent_channels = array();

	/**
	 * Channels that fail with a WP_Error.
	 *
	 * @var string[]
	 */
	public $error_channels = array();

	/**
	 * Optional per-signature override: signatures listed here never send.
	 *
	 * @var string[]
	 */
	public $undeliverable_signatures = array();

	/**
	 * Record a dispatch and answer according to the configured channel outcome.
	 *
	 * @param array $input Canonical dispatch-message input.
	 * @return array|WP_Error Dispatch response.
	 */
	public function execute( $input ) {
		$this->requests[] = $input;

		if ( in_array( $input['channel'], $this->error_channels, true ) ) {
			return new WP_Error( 'dispatch_failed', 'Channel unavailable.' );
		}

		$signature = $input['metadata']['signature'] ?? '';
		if ( in_array( $signature, $this->undeliverable_signatures, true ) ) {
			return array( 'sent' => false );
		}

		return array( 'sent' => in_array( $input['channel'], $this->sent_channels, true ) );
	}

	/**
	 * Channels used, in order.
	 *
	 * @return string[]
	 */
	public function channels() {
		return array_column( $this->requests, 'channel' );
	}
}
