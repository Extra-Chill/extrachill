<?php
/**
 * Abilities API bootstrap.
 *
 * @package ExtraChillArtistPlatform
 * @since 1.7.0
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/category.php';
require_once __DIR__ . '/registry.php';

require_once __DIR__ . '/handlers/get-artist-data.php';
require_once __DIR__ . '/handlers/get-link-page-data.php';
require_once __DIR__ . '/handlers/create-artist.php';
require_once __DIR__ . '/handlers/update-artist.php';
require_once __DIR__ . '/handlers/save-link-page-links.php';
require_once __DIR__ . '/handlers/save-link-page-styles.php';
require_once __DIR__ . '/handlers/save-link-page-settings.php';
require_once __DIR__ . '/handlers/save-social-links.php';
