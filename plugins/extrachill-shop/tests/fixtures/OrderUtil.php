<?php
namespace Automattic\WooCommerce\Utilities;

/** Minimal switch for exercising both WooCommerce order storage paths. */
class OrderUtil {
	public static $hpos = false;

	public static function custom_orders_table_usage_is_enabled() {
		return self::$hpos;
	}
}
