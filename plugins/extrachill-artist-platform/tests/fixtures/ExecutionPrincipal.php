<?php

namespace {
	final class WP_Agent_Capability_Ceiling {
		public int $user_id;
		public ?array $allowed_capabilities;

		public function __construct( int $user_id, ?array $allowed_capabilities = null ) {
			$this->user_id              = $user_id;
			$this->allowed_capabilities = $allowed_capabilities;
		}

		public function allows_capability( string $capability ): bool {
			return null === $this->allowed_capabilities || in_array( $capability, $this->allowed_capabilities, true );
		}
	}
}

namespace AgentsAPI\AI {
	final class WP_Agent_Execution_Principal {
		public int $acting_user_id;
		public string $auth_source;
		public string $request_context;
		public ?\WP_Agent_Capability_Ceiling $capability_ceiling;

		public function __construct( int $acting_user_id, string $auth_source = 'user', string $request_context = 'rest', ?\WP_Agent_Capability_Ceiling $capability_ceiling = null ) {
			$this->acting_user_id    = $acting_user_id;
			$this->auth_source       = $auth_source;
			$this->request_context   = $request_context;
			$this->capability_ceiling = $capability_ceiling;
		}

		public static function resolve( array $request_context = array() ): ?self {
			$principal = \apply_filters( 'agents_api_execution_principal', null, $request_context );

			return $principal instanceof self ? $principal : null;
		}
	}
}
