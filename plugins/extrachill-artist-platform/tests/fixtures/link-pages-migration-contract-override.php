<?php
/** Deterministic fixture for the pending Link Pages participant API. */
function ec_link_page_migration_participant_registry() {
	static $registry = null;
	if ( null === $registry ) {
		$registry = new class() {
			private $participants = array();

			public function register( $name, $contract_version, $callbacks, $priority ) {
				foreach ( array( 'claim_owner', 'plan', 'apply', 'validate', 'rollback' ) as $operation ) {
					if ( empty( $callbacks[ $operation ] ) || ! is_callable( $callbacks[ $operation ] ) ) {
						return new WP_Error( 'invalid_link_page_migration_participant', 'Every migration participant callback is required.' );
					}
				}
				$this->participants[ $name ] = compact( 'name', 'contract_version', 'callbacks', 'priority' );
				return true;
			}

			public function snapshot() {
				return array_values( $this->participants );
			}
		};
	}
	return $registry;
}

/** Match the current Link Pages registration signature. */
function ec_register_link_page_migration_participant( $name, $contract_version, $callbacks, $priority = 10 ) {
	return ec_link_page_migration_participant_registry()->register( $name, $contract_version, $callbacks, $priority );
}
