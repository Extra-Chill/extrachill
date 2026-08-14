<?php
/**
 * Verified, compensating repair for one qualified root location.
 *
 * @package ExtraChillEvents\Core
 */

namespace ExtraChillEvents\Core;

defined( 'ABSPATH' ) || exit;

/** Executes a verified repair with compensation before term deletion. */
final class RootLocationRepair {

	/**
	 * WordPress operation adapters.
	 *
	 * @var array<string,callable>
	 */
	private array $operations;

	/**
	 * Store WordPress operation adapters.
	 *
	 * @param array<string,callable> $operations WordPress operation adapters.
	 */
	public function __construct( array $operations ) {
		$this->operations = $operations;
	}

	/**
	 * Move relationships, verify a redirect, then delete the duplicate.
	 *
	 * @param object $duplicate Duplicate root location term.
	 * @param object $canonical Canonical hierarchy location term.
	 * @return array{status:string,reason:string}
	 */
	public function repair( object $duplicate, object $canonical ): array {
		$redirect = $this->call( 'prepare_redirect', $duplicate, $canonical );
		if ( is_wp_error( $redirect ) ) {
			return $this->result( 'blocked', $redirect->get_error_code() );
		}

		$objects = $this->call( 'get_objects', (int) $duplicate->term_id );
		if ( is_wp_error( $objects ) ) {
			return $this->result( 'failed', 'relationship_lookup_failed' );
		}

		$original = array();
		foreach ( $objects as $object_id ) {
			$term_ids = $this->call( 'get_object_terms', (int) $object_id );
			if ( is_wp_error( $term_ids ) ) {
				return $this->result( 'failed', 'relationship_snapshot_failed' );
			}
			$original[ (int) $object_id ] = array_map( 'intval', $term_ids );
		}

		foreach ( $objects as $object_id ) {
			$result = $this->call( 'add_relationship', (int) $object_id, (int) $canonical->term_id );
			if ( is_wp_error( $result ) || false === $result ) {
				return $this->rollback( $original, $duplicate, $canonical, 0, 'relationship_add_failed' );
			}
		}

		if ( ! $this->verify_relationships( $original, (int) $duplicate->term_id, (int) $canonical->term_id, true ) ) {
			return $this->rollback( $original, $duplicate, $canonical, 0, 'relationship_add_verification_failed' );
		}

		foreach ( $objects as $object_id ) {
			$result = $this->call( 'remove_relationship', (int) $object_id, (int) $duplicate->term_id );
			if ( is_wp_error( $result ) || false === $result ) {
				return $this->rollback( $original, $duplicate, $canonical, 0, 'relationship_remove_failed' );
			}
		}

		if ( ! $this->verify_relationships( $original, (int) $duplicate->term_id, (int) $canonical->term_id, false ) ) {
			return $this->rollback( $original, $duplicate, $canonical, 0, 'relationship_remove_verification_failed' );
		}

		$remaining = $this->call( 'get_objects', (int) $duplicate->term_id );
		if ( is_wp_error( $remaining ) || ! empty( $remaining ) ) {
			return $this->rollback( $original, $duplicate, $canonical, 0, 'duplicate_relationships_remain' );
		}

		$redirect_id = 0;
		if ( empty( $redirect['existing'] ) ) {
			$created = $this->call( 'create_redirect', $redirect );
			if ( is_wp_error( $created ) || (int) $created <= 0 ) {
				return $this->rollback( $original, $duplicate, $canonical, 0, 'redirect_creation_failed' );
			}
			$redirect_id = (int) $created;
		}

		if ( true !== $this->call( 'verify_redirect', $redirect ) ) {
			return $this->rollback( $original, $duplicate, $canonical, $redirect_id, 'redirect_verification_failed' );
		}

		$deleted = $this->call( 'delete_term', (int) $duplicate->term_id );
		if ( true !== $deleted || true === $this->call( 'term_exists', (int) $duplicate->term_id ) ) {
			return $this->rollback( $original, $duplicate, $canonical, $redirect_id, 'term_deletion_failed' );
		}

		return $this->result( 'reconciled', 'relationships_redirect_and_deletion_verified' );
	}

	/**
	 * Verify every affected object is at the expected migration boundary.
	 *
	 * @param array $original           Original relationships by object ID.
	 * @param int   $duplicate_id       Duplicate term ID.
	 * @param int   $canonical_id       Canonical term ID.
	 * @param bool  $duplicate_expected Whether the duplicate should remain attached.
	 */
	private function verify_relationships( array $original, int $duplicate_id, int $canonical_id, bool $duplicate_expected ): bool {
		foreach ( array_keys( $original ) as $object_id ) {
			$terms = $this->call( 'get_object_terms', $object_id );
			if ( is_wp_error( $terms ) ) {
				return false;
			}
			$terms = array_map( 'intval', $terms );
			if ( ! in_array( $canonical_id, $terms, true ) || in_array( $duplicate_id, $terms, true ) !== $duplicate_expected ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Restore relationships and remove a redirect created by this run.
	 *
	 * @param array  $original    Original relationships by object ID.
	 * @param object $duplicate   Duplicate root location term.
	 * @param object $canonical   Canonical hierarchy location term.
	 * @param int    $redirect_id Redirect created by this run, if any.
	 * @param string $reason      Failure reason.
	 */
	private function rollback( array $original, object $duplicate, object $canonical, int $redirect_id, string $reason ): array {
		$ok = true;
		foreach ( $original as $object_id => $term_ids ) {
			if ( in_array( (int) $duplicate->term_id, $term_ids, true ) ) {
				$result = $this->call( 'add_relationship', $object_id, (int) $duplicate->term_id );
				$ok     = $ok && ! is_wp_error( $result ) && false !== $result;
			}
			if ( ! in_array( (int) $canonical->term_id, $term_ids, true ) ) {
				$result = $this->call( 'remove_relationship', $object_id, (int) $canonical->term_id );
				$ok     = $ok && ! is_wp_error( $result ) && false !== $result;
			}
		}

		if ( $redirect_id > 0 ) {
			$ok = true === $this->call( 'delete_redirect', $redirect_id ) && $ok;
		}

		return $this->result( 'failed', $reason . ( $ok ? '_rolled_back' : '_rollback_failed' ) );
	}

	/**
	 * Invoke one injected operation.
	 *
	 * @param string $operation Operation name.
	 * @param mixed  ...$args   Operation arguments.
	 */
	private function call( string $operation, ...$args ) {
		return ( $this->operations[ $operation ] )( ...$args );
	}

	/**
	 * Build one repair result.
	 *
	 * @param string $status Repair status.
	 * @param string $reason Machine-readable reason.
	 */
	private function result( string $status, string $reason ): array {
		return array(
			'status' => $status,
			'reason' => $reason,
		);
	}
}
