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

// Artist-domain ability handlers (issue #27).
require_once __DIR__ . '/handlers/artists-list.php';
require_once __DIR__ . '/handlers/artist-get.php';
require_once __DIR__ . '/handlers/artist-get-links.php';
require_once __DIR__ . '/handlers/artist-update-links.php';
require_once __DIR__ . '/handlers/artist-get-permissions.php';
require_once __DIR__ . '/handlers/artist-get-roster.php';
require_once __DIR__ . '/handlers/artist-list-socials.php';
require_once __DIR__ . '/handlers/artist-create-social.php';
require_once __DIR__ . '/handlers/artist-update-social.php';
require_once __DIR__ . '/handlers/artist-delete-social.php';
require_once __DIR__ . '/handlers/artist-subscribe.php';
require_once __DIR__ . '/handlers/artist-list-subscribers.php';
require_once __DIR__ . '/handlers/artist-export-subscribers.php';
require_once __DIR__ . '/handlers/artist-get-analytics.php';
