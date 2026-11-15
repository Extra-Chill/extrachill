<?php
/**
 * WP_Block_Type_Registry stand-in for the standalone test harness.
 *
 * @package ExtraChillLinkPages
 */

/**
 * Records block registrations so tests can assert which source claimed a block.
 */
class WP_Block_Type_Registry {
	private static $instance;
	private $registered = array();
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}
	public function is_registered( $name ) {
		return isset( $this->registered[ $name ] ); }
	public function register( $name, $source ) {
		$this->registered[ $name ] = $source; }
	public function source( $name ) {
		return $this->registered[ $name ] ?? ''; }
	public function reset() {
		$this->registered = array(); }
}
