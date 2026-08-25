<?php
/**
 * Minimal Agents API execution principal fixture.
 *
 * @package ExtraChillEvents\Tests
 */


namespace AgentsAPI\AI {
	/** Exposes an immutable effective user for coordinated authorization tests. */
	final class WP_Agent_Execution_Principal {
		public const REQUEST_CONTEXT_REST = 'rest';

		/** @var int */
		public $acting_user_id;

		/** @var array|null */
		public $capability_ceiling;

		/** Construct one fixture principal. */
		public function __construct( int $acting_user_id ) {
			$this->acting_user_id     = $acting_user_id;
			$this->capability_ceiling = $GLOBALS['promoter_link_page_fixture']['principal_capabilities'] ?? null;
		}

		/** Resolve only when the fixture declares an active principal. */
		public static function resolve( array $context = array() ): ?self {
			$GLOBALS['promoter_link_page_fixture']['principal_context'] = $context;
			return array_key_exists( 'principal_user_id', $GLOBALS['promoter_link_page_fixture'] ) ? new self( (int) $GLOBALS['promoter_link_page_fixture']['principal_user_id'] ) : null;
		}
	}
}

namespace {
	if ( ! class_exists( 'WP_Agent_WordPress_Authorization_Policy' ) ) {
		/** Minimal principal-ceiling policy fixture. */
		final class WP_Agent_WordPress_Authorization_Policy {
			/** Compose a configured ceiling with the represented user's capability. */
			public function can( $principal, string $capability, array $context = array() ): bool {
				unset( $context );
				$ceiling = $principal->capability_ceiling;
				return ( null === $ceiling || in_array( $capability, $ceiling, true ) ) && user_can( (int) $principal->acting_user_id, $capability );
			}
		}
	}
}
