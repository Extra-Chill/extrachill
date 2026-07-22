<?php
/**
 * Global ability lookup stub for persisted qualification integration tests.
 *
 * @package ExtraChillEvents\Tests\Integration
 */

function wp_get_ability( string $name ) {
	return $GLOBALS['ec_persisted_qualification_abilities'][ $name ] ?? null;
}
