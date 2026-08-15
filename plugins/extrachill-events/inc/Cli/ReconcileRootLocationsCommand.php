<?php
/**
 * Dry-run-first repair for qualified root location terms.
 *
 * @package ExtraChillEvents\Cli
 */

namespace ExtraChillEvents\Cli;

use ExtraChillEvents\Core\QualifiedRootLocation;
use ExtraChillEvents\Core\RootLocationRepair;

defined( 'ABSPATH' ) || exit;

/** Reports and optionally repairs exact qualified root location duplicates. */
final class ReconcileRootLocationsCommand {

	/**
	 * Report or reconcile safe "City, State/Province" root terms.
	 *
	 * ## OPTIONS
	 *
	 * [--apply]
	 * : Move relationships, create and verify redirects, and delete safe duplicates. Requires an administrator user context. Default is dry-run.
	 *
	 * [--format=<format>]
	 * : Output format: table, json, or csv. Default: table.
	 *
	 * ## EXAMPLES
	 *
	 *     wp extrachill events locations reconcile-roots --url=events.extrachill.com
	 *     wp extrachill events locations reconcile-roots --url=events.extrachill.com --user=<administrator-login-or-id> --apply
	 *
	 * @param array $args       Positional arguments (unused).
	 * @param array $assoc_args Named arguments.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		unset( $args );
		if ( ! taxonomy_exists( 'location' ) ) {
			\WP_CLI::error( 'The location taxonomy is not registered. Use --url=events.extrachill.com.' );
		}

		$apply = ! empty( $assoc_args['apply'] );
		if ( $apply ) {
			$this->assert_apply_ready();
		}

		$terms = get_terms(
			array(
				'taxonomy'   => 'location',
				'hide_empty' => false,
				'number'     => 0,
			)
		);
		if ( is_wp_error( $terms ) ) {
			\WP_CLI::error( $terms->get_error_message() );
		}

		$repair = $apply ? $this->repair_service() : null;
		$rows   = array();

		foreach ( $terms as $term ) {
			$match = QualifiedRootLocation::match( $term, $terms );
			if ( 'not_candidate' === $match['status'] ) {
				continue;
			}

			$status = $match['status'];
			$reason = $match['reason'];
			if ( 'safe_match' === $status ) {
				if ( $apply ) {
					$result = $repair->repair( $term, $match['canonical'] );
					$status = $result['status'];
					$reason = $result['reason'];
				} else {
					$status = 'would_reconcile';
				}
			}

			$rows[] = array(
				'candidate_id'  => (int) $term->term_id,
				'candidate'     => $term->name,
				'canonical_id'  => $match['canonical'] ? (int) $match['canonical']->term_id : '',
				'canonical'     => $match['canonical'] ? $match['canonical']->name : '',
				'relationships' => (int) $term->count,
				'status'        => $status,
				'reason'        => $reason,
			);
		}

		\WP_CLI\Utils\format_items(
			(string) ( $assoc_args['format'] ?? 'table' ),
			$rows,
			array( 'candidate_id', 'candidate', 'canonical_id', 'canonical', 'relationships', 'status', 'reason' )
		);

		if ( ! $apply ) {
			\WP_CLI::log( 'Dry run: no redirects, relationships, or terms changed. Re-run with --apply only after reviewing every row.' );
		}
	}

	/** Ensure apply mode can use every redirect ability before inspecting candidates. */
	private function assert_apply_ready(): void {
		$ability_names = array(
			'extrachill-seo/list-redirects',
			'extrachill-seo/add-redirect',
			'extrachill-seo/delete-redirect',
		);

		foreach ( $ability_names as $ability_name ) {
			$ability = wp_get_ability( $ability_name );
			if ( ! $ability ) {
				\WP_CLI::error( sprintf( 'Apply requires the %s ability, but it is unavailable.', $ability_name ) );
			}

			if ( true !== $ability->check_permissions() ) {
				\WP_CLI::error( 'Apply requires an authorized WordPress administrator context for redirect management. Re-run with --user=<administrator-login-or-id>.' );
			}
		}
	}

	/** Build the repair service with WordPress and SEO ability adapters. */
	private function repair_service(): RootLocationRepair {
		return new RootLocationRepair(
			array(
				'prepare_redirect'    => array( $this, 'prepare_redirect' ),
				'get_objects'         => static fn( int $term_id ) => get_objects_in_term( $term_id, 'location' ),
				'get_object_terms'    => static fn( int $object_id ) => wp_get_object_terms( $object_id, 'location', array( 'fields' => 'ids' ) ),
				'add_relationship'    => static fn( int $object_id, int $term_id ) => wp_set_object_terms( $object_id, array( $term_id ), 'location', true ),
				'remove_relationship' => static fn( int $object_id, int $term_id ) => wp_remove_object_terms( $object_id, array( $term_id ), 'location' ),
				'create_redirect'     => array( $this, 'create_redirect' ),
				'verify_redirect'     => array( $this, 'verify_redirect' ),
				'delete_redirect'     => array( $this, 'delete_redirect' ),
				'delete_term'         => static fn( int $term_id ) => wp_delete_term( $term_id, 'location' ),
				'term_exists'         => static function ( int $term_id ): bool {
					$term = get_term( $term_id, 'location' );
					return $term && ! is_wp_error( $term );
				},
			)
		);
	}

	/**
	 * Preflight redirect URLs, abilities, and existing-rule conflicts.
	 *
	 * @param object $duplicate Duplicate root location term.
	 * @param object $canonical Canonical hierarchy location term.
	 */
	public function prepare_redirect( object $duplicate, object $canonical ) {
		$from_link = get_term_link( $duplicate );
		$to_link   = get_term_link( $canonical );
		if ( is_wp_error( $from_link ) || is_wp_error( $to_link ) ) {
			return new \WP_Error( 'invalid_term_url', 'Could not resolve both term archive URLs.' );
		}

		$from = wp_parse_url( $from_link, PHP_URL_PATH );
		$to   = wp_parse_url( $to_link, PHP_URL_PATH );
		if ( ! is_string( $from ) || ! is_string( $to ) || '' === $from || '' === $to ) {
			return new \WP_Error( 'invalid_term_url', 'Could not resolve both term archive paths.' );
		}

		$context = array(
			'from'     => untrailingslashit( '/' . ltrim( $from, '/' ) ),
			'to'       => untrailingslashit( '/' . ltrim( $to, '/' ) ),
			'existing' => false,
		);
		$rules   = $this->redirect_rules( $context['from'] );
		if ( is_wp_error( $rules ) ) {
			return $rules;
		}

		foreach ( $rules as $rule ) {
			$rule_from = untrailingslashit( '/' . ltrim( (string) $this->rule_field( $rule, 'from_url' ), '/' ) );
			if ( $rule_from !== $context['from'] ) {
				continue;
			}
			$rule_to = untrailingslashit( '/' . ltrim( (string) $this->rule_field( $rule, 'to_url' ), '/' ) );
			if ( $rule_to !== $context['to'] || 301 !== (int) $this->rule_field( $rule, 'status_code' ) ) {
				return new \WP_Error( 'conflicting_redirect_exists', 'The legacy path already has a different active redirect.' );
			}
			$context['existing'] = true;
			return $context;
		}

		if ( ! wp_get_ability( 'extrachill-seo/add-redirect' ) || ! wp_get_ability( 'extrachill-seo/delete-redirect' ) ) {
			return new \WP_Error( 'redirect_abilities_unavailable', 'Redirect create/delete abilities are required before relationship mutation.' );
		}

		return $context;
	}

	/**
	 * Create the preflighted redirect and return its ID.
	 *
	 * @param array $context Redirect paths and preflight state.
	 */
	public function create_redirect( array $context ) {
		$ability = wp_get_ability( 'extrachill-seo/add-redirect' );
		$result  = $ability ? $ability->execute(
			array(
				'from_url'    => $context['from'],
				'to_url'      => $context['to'],
				'status_code' => 301,
				'note'        => 'Qualified root location reconciliation',
			)
		) : null;

		return is_array( $result ) && ! empty( $result['success'] ) ? (int) $result['id'] : new \WP_Error( 'redirect_creation_failed', 'The redirect ability did not create a rule.' );
	}

	/**
	 * Verify the exact active redirect after creation.
	 *
	 * @param array $context Redirect paths and preflight state.
	 */
	public function verify_redirect( array $context ): bool {
		$rules = $this->redirect_rules( $context['from'] );
		if ( is_wp_error( $rules ) ) {
			return false;
		}

		foreach ( $rules as $rule ) {
			$from = untrailingslashit( '/' . ltrim( (string) $this->rule_field( $rule, 'from_url' ), '/' ) );
			$to   = untrailingslashit( '/' . ltrim( (string) $this->rule_field( $rule, 'to_url' ), '/' ) );
			if ( $from === $context['from'] && $to === $context['to'] && 301 === (int) $this->rule_field( $rule, 'status_code' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Remove a redirect created by this run during compensation.
	 *
	 * @param int $redirect_id Redirect rule ID.
	 */
	public function delete_redirect( int $redirect_id ): bool {
		$ability = wp_get_ability( 'extrachill-seo/delete-redirect' );
		$result  = $ability ? $ability->execute( array( 'id' => $redirect_id ) ) : null;
		return is_array( $result ) && ! empty( $result['success'] );
	}

	/**
	 * Retrieve active redirect rules through the read-only SEO ability.
	 *
	 * @param string $from Legacy source path.
	 */
	private function redirect_rules( string $from ) {
		$ability = wp_get_ability( 'extrachill-seo/list-redirects' );
		if ( ! $ability ) {
			return new \WP_Error( 'redirect_lookup_unavailable', 'The redirect lookup ability is required before relationship mutation.' );
		}
		$result = $ability->execute(
			array(
				'search' => $from,
				'active' => 1,
				'limit'  => 100,
			)
		);
		return is_wp_error( $result ) || ! is_array( $result ) ? new \WP_Error( 'redirect_lookup_failed', 'Could not verify redirects.' ) : $result;
	}

	/**
	 * Read a redirect field from ability object/array output.
	 *
	 * @param object|array $rule  Redirect ability row.
	 * @param string       $field Field name.
	 */
	private function rule_field( $rule, string $field ) {
		return is_object( $rule ) ? ( $rule->{$field} ?? null ) : ( $rule[ $field ] ?? null );
	}
}
