<?php
/**
 * PHPUnit bootstrap for source and generated-asset contracts plus
 * business-logic tests (run against minimal in-memory WordPress stubs).
 *
 * The photo-contest entry photo storage step is substituted through the
 * `extrachill_content_blocks_photo_contest_store_photo` short-circuit filter;
 * wp_stubs_reset() registers the in-memory fake on every test setUp.
 *
 * @package ExtraChillContentBlocks
 */

define( 'EXTRACHILL_CONTENT_BLOCKS_TEST_ROOT', dirname( __DIR__ ) );

require_once __DIR__ . '/phpunit/includes/wp-stubs.php';

wp_stubs_reset();

// Photo-contest domain module (mirrors the plugin bootstrap load order).
require_once EXTRACHILL_CONTENT_BLOCKS_TEST_ROOT . '/inc/photo-contest/entries.php';
require_once EXTRACHILL_CONTENT_BLOCKS_TEST_ROOT . '/inc/photo-contest/storage.php';
require_once EXTRACHILL_CONTENT_BLOCKS_TEST_ROOT . '/inc/photo-contest/submission.php';
require_once EXTRACHILL_CONTENT_BLOCKS_TEST_ROOT . '/inc/photo-contest/voting.php';
require_once EXTRACHILL_CONTENT_BLOCKS_TEST_ROOT . '/inc/photo-contest/moderation.php';
require_once EXTRACHILL_CONTENT_BLOCKS_TEST_ROOT . '/inc/photo-contest/newsletter.php';

require_once EXTRACHILL_CONTENT_BLOCKS_TEST_ROOT . '/src/blocks/image-voting/index.php';
require_once EXTRACHILL_CONTENT_BLOCKS_TEST_ROOT . '/src/blocks/photo-contest/index.php';

require_once EXTRACHILL_CONTENT_BLOCKS_TEST_ROOT . '/inc/abilities/photo-contest-submit.php';
require_once EXTRACHILL_CONTENT_BLOCKS_TEST_ROOT . '/inc/abilities/photo-contest-vote.php';
require_once EXTRACHILL_CONTENT_BLOCKS_TEST_ROOT . '/inc/abilities/photo-contest-hide.php';
require_once EXTRACHILL_CONTENT_BLOCKS_TEST_ROOT . '/inc/abilities/photo-contest-list.php';
require_once EXTRACHILL_CONTENT_BLOCKS_TEST_ROOT . '/inc/abilities/photo-contest-top.php';
