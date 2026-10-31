<?php
/**
 * Replies Loop - Single Reply
 *
 * bbPress threaded reply walkers render `loop-single-reply.php`.
 * This shim keeps our reply-card UI for both flat and threaded modes.
 *
 * @package bbPress
 * @subpackage Theme
 */

defined( 'ABSPATH' ) || exit;

bbp_get_template_part( 'loop', 'single-reply-card' );
