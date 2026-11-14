<?php
namespace Automattic\WooCommerce\Blocks\Payments\Integrations;

/** Minimal mirror of the WooCommerce Blocks payment method base class. */
abstract class AbstractPaymentMethodType {
	protected $name     = '';
	protected $settings = array();

	abstract public function initialize();

	protected function get_setting( $name, $default = '' ) {
		return isset( $this->settings[ $name ] ) ? $this->settings[ $name ] : $default;
	}

	public function get_name() {
		return $this->name;
	}

	public function is_active() {
		return true;
	}

	public function get_payment_method_script_handles() {
		return array();
	}

	public function get_supported_features() {
		return array( 'products' );
	}

	public function get_payment_method_data() {
		return array();
	}
}
