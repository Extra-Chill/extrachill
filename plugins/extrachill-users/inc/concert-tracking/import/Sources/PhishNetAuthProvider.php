<?php
/**
 * phish.net Data Machine auth provider.
 *
 * Holds the encrypted platform-wide phish.net API key. Discoverable via
 * `wp datamachine auth status` and managed via
 * `wp datamachine auth config ec_concert_import_phish_net --api_key=...`.
 *
 * @package ExtraChill\Users\Concert_Import
 * @since 0.14.0
 */

namespace ExtraChill\Users\Concert_Import\Sources;

defined( 'ABSPATH' ) || exit;

final class PhishNetAuthProvider extends ConcertImportAuthProvider {

	public const PROVIDER_SLUG = 'ec_concert_import_phish_net';

	public function __construct() {
		parent::__construct( self::PROVIDER_SLUG );
	}
}
