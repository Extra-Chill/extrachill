<?php
/**
 * setlist.fm Data Machine auth provider.
 *
 * Holds the encrypted platform-wide setlist.fm API key. Discoverable via
 * `wp datamachine auth status` and managed via
 * `wp datamachine auth config ec_concert_import_setlist_fm --api_key=...`.
 *
 * @package ExtraChill\Users\Concert_Import
 * @since 0.14.0
 */

namespace ExtraChill\Users\Concert_Import\Sources;

defined( 'ABSPATH' ) || exit;

final class SetlistFmAuthProvider extends ConcertImportAuthProvider {

	public const PROVIDER_SLUG = 'ec_concert_import_setlist_fm';

	public function __construct() {
		parent::__construct( self::PROVIDER_SLUG );
	}
}
