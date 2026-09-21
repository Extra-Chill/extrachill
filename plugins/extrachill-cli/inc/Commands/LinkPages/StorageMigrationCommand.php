<?php
/**
 * Link Pages storage migration operator adapter.
 *
 * @package ExtraChill\CLI\Commands\LinkPages
 */

namespace ExtraChill\CLI\Commands\LinkPages;

use WP_CLI;
use function WP_CLI\Utils\format_items;

defined( 'ABSPATH' ) || exit;

/** Thin command adapter over the Link Pages migration ability. */
class StorageMigrationCommand {
	/** Caller-required participant contracts for the platform migration. */
	private const REQUIRED_PARTICIPANTS = array(
		'analytics' => '1',
	);

	/**
	 * Plan, apply, validate, or roll back the feature-owned storage migration.
	 *
	 * ## OPTIONS
	 *
	 * [--source=<site>]
	 * : Source blog ID or registered logical site key.
	 *
	 * [--destination=<site>]
	 * : Destination blog ID or registered logical site key.
	 *
	 * [--apply]
	 * : Apply a prior plan. Requires --expect.
	 *
	 * [--expect=<fingerprint>]
	 * : Exact source fingerprint returned by a prior plan.
	 *
	 * [--validate=<journal-id>]
	 * : Validate an applied journal.
	 *
	 * [--rollback=<journal-id>]
	 * : Roll back only mutations owned by a journal.
	 *
	 * [--format=<format>]
	 * : Output format: table or json. Default: table.
	 *
	 * ## EXAMPLES
	 *
	 *     wp --url=https://artist.extrachill.com --user=<network-admin> extrachill link-pages migrate-storage --source=4 --destination=13
	 *     wp --url=https://artist.extrachill.com --user=<network-admin> extrachill link-pages migrate-storage --source=4 --destination=13 --apply --expect=<fingerprint>
	 *     wp --url=https://artist.extrachill.com --user=<network-admin> extrachill link-pages migrate-storage --validate=<journal-id> --format=json
	 *     wp --url=https://artist.extrachill.com --user=<network-admin> extrachill link-pages migrate-storage --rollback=<journal-id>
	 *
	 * @when after_wp_load
	 *
	 * @param array $args Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function __invoke( $args, $assoc_args ) {
		$format  = $assoc_args['format'] ?? 'table';
		$allowed = array( 'source', 'destination', 'apply', 'expect', 'validate', 'rollback', 'format' );
		$unknown = array_diff( array_keys( $assoc_args ), $allowed );
		if ( $args ) {
			$this->fail( 'invalid_positional_arguments', 'This command does not accept positional arguments.', array( 'arguments' => array_values( $args ) ), $format ); }
		if ( $unknown ) {
			$this->fail( 'unknown_arguments', 'Unknown argument(s): --' . implode( ', --', $unknown ) . '.', array( 'arguments' => array_values( $unknown ) ), $format ); }
		if ( ! in_array( $format, array( 'table', 'json' ), true ) ) {
			$this->fail( 'invalid_format', '--format must be table or json.', array( 'format' => $format ), 'table' ); }
		$modes = (int) ! empty( $assoc_args['apply'] ) + (int) isset( $assoc_args['validate'] ) + (int) isset( $assoc_args['rollback'] );
		if ( $modes > 1 ) {
			$this->fail( 'conflicting_modes', '--apply, --validate, and --rollback are mutually exclusive.', array(), $format ); }
		if ( isset( $assoc_args['expect'] ) && empty( $assoc_args['apply'] ) ) {
			$this->fail( 'invalid_expectation_mode', '--expect may only be used with --apply.', array(), $format ); }
		if ( ! empty( $assoc_args['apply'] ) && empty( $assoc_args['expect'] ) ) {
			$this->fail( 'missing_fingerprint', '--apply requires --expect=<fingerprint> from a prior plan.', array(), $format ); }
		if ( ( isset( $assoc_args['validate'] ) || isset( $assoc_args['rollback'] ) ) && ( isset( $assoc_args['source'] ) || isset( $assoc_args['destination'] ) || isset( $assoc_args['expect'] ) ) ) {
			$this->fail( 'conflicting_journal_arguments', 'Journal modes cannot be combined with source, destination, or expectation arguments.', array(), $format ); }
		if ( isset( $assoc_args['validate'] ) && '' === trim( (string) $assoc_args['validate'] ) ) {
			$this->fail( 'missing_journal_id', '--validate requires a non-empty journal ID.', array(), $format ); }
		if ( isset( $assoc_args['rollback'] ) && '' === trim( (string) $assoc_args['rollback'] ) ) {
			$this->fail( 'missing_journal_id', '--rollback requires a non-empty journal ID.', array(), $format ); }
		if ( ! function_exists( 'wp_get_ability' ) ) {
			$this->fail( 'abilities_api_unavailable', 'Abilities API not available (requires WordPress 6.9+).', array(), $format ); }
		$ability = wp_get_ability( 'extrachill/migrate-link-page-storage' );
		if ( ! $ability ) {
			$this->fail( 'migration_ability_unavailable', 'extrachill/migrate-link-page-storage ability not available. Ensure Extra Chill Link Pages is network-active and up to date.', array(), $format ); }
		if ( isset( $assoc_args['validate'] ) ) {
			$input = array(
				'mode'       => 'validate',
				'journal_id' => (string) $assoc_args['validate'],
			); } elseif ( isset( $assoc_args['rollback'] ) ) {
			$input = array(
				'mode'       => 'rollback',
				'journal_id' => (string) $assoc_args['rollback'],
			); } else {
				if ( ! isset( $assoc_args['source'], $assoc_args['destination'] ) ) {
					$this->fail( 'missing_sites', 'Plan and apply require --source and --destination.', array(), $format ); }
				$source      = $this->resolve_blog_id( $assoc_args['source'] );
				$destination = $this->resolve_blog_id( $assoc_args['destination'] );
				if ( ! $source || ! $destination || $source === $destination || ! get_site( $source ) || ! get_site( $destination ) ) {
					$this->fail( 'invalid_sites', 'Source and destination must resolve to distinct existing positive blog IDs.', array(), $format ); }
				if ( get_current_blog_id() !== $source ) {
					$this->fail( 'wrong_source_site', sprintf( 'Run this command on source blog %d so all required owner participants are active. Use --url=<source-url> and global --user=<network-admin>.', $source ), array( 'source_blog_id' => $source ), $format ); }
				$participants = function_exists( 'ec_link_page_migration_participant_registry' ) ? ec_link_page_migration_participant_registry()->snapshot() : array();
				$registered   = array();
				foreach ( $participants as $participant ) {
					$registered[ $participant['name'] ?? '' ] = (string) ( $participant['contract_version'] ?? '' ); }
				foreach ( self::REQUIRED_PARTICIPANTS as $participant => $contract_version ) {
					if ( ( $registered[ $participant ] ?? '' ) !== $contract_version ) {
						$this->fail(
							'required_participant_unavailable',
							'A caller-required migration participant or exact contract version is unavailable.',
							array(
								'participant'      => $participant,
								'contract_version' => $contract_version,
							),
							$format
						); }
				}
				$input = array(
					'mode'                  => ! empty( $assoc_args['apply'] ) ? 'apply' : 'plan',
					'source_blog_id'        => $source,
					'destination_blog_id'   => $destination,
					'required_participants' => array_keys( self::REQUIRED_PARTICIPANTS ),
				);
				if ( ! empty( $assoc_args['apply'] ) ) {
					$input['expected_fingerprint'] = (string) $assoc_args['expect']; }
			}
			$result = $ability->execute( $input );
			if ( is_wp_error( $result ) ) {
				$data          = $result->get_error_data();
				$code          = (string) $result->get_error_code();
				$error_message = $result->get_error_message();
				$message       = '[' . $code . '] ' . $error_message;
				if ( false !== stripos( $code, 'permission' ) || false !== stripos( $code, 'forbidden' ) ) {
					$guidance       = ' Execute with global --user=<network-admin>; authorization is enforced by the ability.';
					$error_message .= $guidance;
					$message       .= $guidance; }
				$journal_status = is_array( $data ) ? ( $data['journal_status'] ?? $data['status'] ?? '' ) : '';
				if ( is_array( $data ) && ! empty( $data['journal_id'] ) && in_array( $journal_status, array( 'applying', 'applied', 'failed' ), true ) ) {
					$message .= ' Journal: ' . $data['journal_id'] . '. Roll back with: wp extrachill link-pages migrate-storage --rollback=' . $data['journal_id']; }
				if ( 'json' === $format ) {
					$this->fail( $code, $error_message, $data, $format ); }
				if ( null !== $data ) {
					$message .= ' Diagnostics: ' . $this->encode_json( $data, JSON_UNESCAPED_SLASHES ); }
				WP_CLI::error( $message );
			}
			if ( 'json' === $format ) {
				WP_CLI::line( $this->encode_json( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
				return; }
			$rows = array();
			foreach ( $result as $key => $value ) {
				$rows[] = array(
					'field' => $key,
					'value' => is_scalar( $value ) || null === $value ? ( is_bool( $value ) ? ( $value ? 'true' : 'false' ) : (string) $value ) : $this->encode_json( $value, JSON_UNESCAPED_SLASHES ),
				);
			}
			format_items( 'table', $rows, array( 'field', 'value' ) );
			if ( 'plan' === ( $result['mode'] ?? '' ) ) {
				WP_CLI::log( 'Dry run only. No options, database rows, files, routing, or source data were changed.' ); }
			if ( ! empty( $result['rollback'] ) ) {
				WP_CLI::log( 'Rollback: ' . $result['rollback'] ); }
	}

	/**
	 * Resolve numeric IDs or existing logical site keys.
	 *
	 * @param string $value Site ID or key.
	 */
	private function resolve_blog_id( $value ) {
		$value = trim( (string) $value );
		if ( ctype_digit( $value ) ) {
			return (int) $value; }
		return function_exists( 'ec_get_blog_id' ) ? (int) ec_get_blog_id( $value ) : 0;
	}

	/** Emit one format-correct command failure. */
	private function fail( $code, $message, $data, $format ) {
		if ( 'json' === $format ) {
			WP_CLI::line(
				$this->encode_json(
					array(
						'success' => false,
						'code'    => (string) $code,
						'message' => (string) $message,
						'data'    => $data,
					),
					JSON_UNESCAPED_SLASHES
				)
			);
			WP_CLI::halt( 1 );
		}
		WP_CLI::error( $message );
	}

	/** Encode a value without passing false to WP-CLI output methods. */
	private function encode_json( $value, $flags ) {
		$encoded = wp_json_encode( $value, $flags );
		return false === $encoded ? '{"success":false,"code":"json_encoding_failed","message":"Could not encode command output.","data":null}' : $encoded;
	}
}
