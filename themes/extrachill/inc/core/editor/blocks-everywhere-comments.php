<?php
/**
 * Blocks Everywhere: Block Editor in Comment Forms
 *
 * The theme owns the comment UI on every network site, so it also owns the
 * switch that replaces the comment textarea with the Blocks Everywhere block
 * editor (#1632). Everything here is inert unless the Blocks Everywhere plugin
 * is active on the current site: activation is a per-site setting.
 *
 * Comment content safety stays with Blocks Everywhere: its comments context
 * strips disallowed blocks before save and extends the `pre_comment_content`
 * kses allowlist only with the tags the allowed blocks need. Users without
 * `unfiltered_html` still go through kses.
 *
 * The community forum keeps its own bbPress allowlist in extrachill-community
 * (editor type `bbpress`). That list derives from bbPress's allowed tags, so
 * comments use an explicit list here rather than sharing that filter.
 *
 * @package ExtraChill
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Whether the Blocks Everywhere plugin is loaded on this site.
 *
 * Checks the plugin's main class rather than is_plugin_active() so the theme
 * does not need wp-admin includes on the front end.
 *
 * @return bool
 */
function extrachill_blocks_everywhere_available() {
	return class_exists( '\Automattic\Blocks_Everywhere\Blocks_Everywhere' );
}

/**
 * Blocks allowed in the comment editor.
 *
 * Matches the forum's set minus headings: paragraphs (with inline links),
 * lists, quotes, images and embeds. No code blocks or headings.
 *
 * @return string[]
 */
function extrachill_comment_allowed_blocks() {
	$blocks = array(
		'core/paragraph',
		'core/list',
		'core/list-item',
		'core/quote',
		'core/image',
		'core/embed',
	);

	/**
	 * Filter the blocks allowed in the comment editor.
	 *
	 * @param string[] $blocks Block names.
	 */
	return array_values( array_unique( (array) apply_filters( 'extrachill_comment_allowed_blocks', $blocks ) ) );
}

/**
 * Enable the Blocks Everywhere comments context when the plugin is active.
 *
 * @hook blocks_everywhere_comments
 * @param bool $enabled Whether the comments context is enabled.
 * @return bool
 */
function extrachill_enable_blocks_everywhere_comments( $enabled ) {
	if ( ! extrachill_blocks_everywhere_available() ) {
		return $enabled;
	}

	return true;
}
add_filter( 'blocks_everywhere_comments', 'extrachill_enable_blocks_everywhere_comments' );

/**
 * Set the comment editor's allowed blocks.
 *
 * Blocks Everywhere resolves the editor type as `comments` for the editor,
 * pre-save block removal and rendering. Its `pre_comment_content` kses
 * callback runs outside that context, where the type falls back to `core`, so
 * the comment list also applies to `core` while a comment is being filtered.
 * Without that, image and embed markup would pass block removal but lose its
 * tags in kses.
 *
 * @hook blocks_everywhere_allowed_blocks
 * @param array  $allowed_blocks Allowed block names.
 * @param string $editor_type    Blocks Everywhere editor type.
 * @return array
 */
function extrachill_blocks_everywhere_comment_allowed_blocks( $allowed_blocks, $editor_type = 'core' ) {
	if ( ! extrachill_blocks_everywhere_available() ) {
		return $allowed_blocks;
	}

	$is_comment_editor = 'comments' === $editor_type;
	$is_comment_kses   = 'core' === $editor_type && doing_filter( 'pre_comment_content' );

	if ( ! $is_comment_editor && ! $is_comment_kses ) {
		return $allowed_blocks;
	}

	return extrachill_comment_allowed_blocks();
}
add_filter( 'blocks_everywhere_allowed_blocks', 'extrachill_blocks_everywhere_comment_allowed_blocks', 10, 2 );
