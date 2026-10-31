<?php
/**
 * Community Forum Homepage Content
 *
 * Feed-first homepage for community.extrachill.com — the network's living room.
 * Hooked via extrachill_homepage_content action.
 *
 * Render order (all hook-based so plugins/phases can inject):
 *   1. Breadcrumbs
 *   2. extrachill_community_home_header      → "Start a conversation" CTA
 *   3. extrachill_community_home_top         → "What's Happening" activity feed (the hero)
 *   4. extrachill_community_home_after_feed  → "Browse rooms" chip row (demoted nav)
 *   5. extrachill_community_home_after_forums → artist-platform buttons
 *
 * The legacy [bbp-forum-index] directory table was retired here in Phase 2
 * (#65): a forum-index table advertises emptiness for a low-volume community,
 * while a feed leads with people and conversations.
 *
 * @package ExtraChillCommunity
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

extrachill_breadcrumbs();

do_action( 'extrachill_community_home_header' );

do_action( 'extrachill_community_home_top' );

do_action( 'extrachill_community_home_after_feed' );

do_action( 'extrachill_community_home_after_forums' );
