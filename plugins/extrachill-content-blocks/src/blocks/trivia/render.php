<?php
/**
 * Trivia block render template.
 *
 * Each block renders its own `.trivia-block` wrapper in place (preserving the
 * editor's anchor id, custom classes, and DOM position) and emits a minimal
 * JSON-island config inside it. The React view (view.tsx) mounts every block
 * in place and shares a single running score across all blocks on the page via
 * a small store, injecting the score display at the same positions the prior
 * vanilla implementation used (before the first block / after the last block).
 * No server-rendered interactive markup is hydrated by reading data-*
 * attributes.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$question             = isset( $attributes['question'] ) ? $attributes['question'] : '';
$options              = isset( $attributes['options'] ) ? $attributes['options'] : array( '', '' );
$correct_answer       = isset( $attributes['correctAnswer'] ) ? (int) $attributes['correctAnswer'] : 0;
$answer_justification = isset( $attributes['answerJustification'] ) ? $attributes['answerJustification'] : '';
$block_id             = isset( $attributes['blockId'] ) && $attributes['blockId'] ? $attributes['blockId'] : uniqid( 'trivia_', false );
$result_messages      = isset( $attributes['resultMessages'] ) ? $attributes['resultMessages'] : array(
	'excellent' => '🏆 Trivia Master!',
	'good'      => '🎉 Great Job!',
	'okay'      => '👍 Not Bad!',
	'poor'      => '🤔 Keep Trying!',
);
$score_ranges         = isset( $attributes['scoreRanges'] ) ? $attributes['scoreRanges'] : array(
	'excellent' => 90,
	'good'      => 70,
	'okay'      => 50,
);

if ( empty( $question ) || empty( array_filter( $options ) ) ) {
	return;
}

// The React view renders question and justification via dangerouslySetInnerHTML
// to preserve the basic inline HTML the original template allowed, so sanitize
// them here with wp_kses_post (matching the original render-time sanitization)
// before they enter the JSON island. Options render as React text nodes and are
// escaped by React, so they pass through as-is.
$config = array(
	'question'            => wp_kses_post( $question ),
	'options'            => array_values( (array) $options ),
	'correctAnswer'      => $correct_answer,
	'answerJustification' => wp_kses_post( $answer_justification ),
	'blockId'            => $block_id,
	'resultMessages'     => $result_messages,
	'scoreRanges'        => $score_ranges,
);

$wrapper_attributes = get_block_wrapper_attributes(
	array(
		'class' => 'trivia-block',
	)
);
?>
<div <?php echo $wrapper_attributes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<script type="application/json" class="extrachill-blocks-trivia-config">
		<?php echo wp_json_encode( $config ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
	</script>
</div>
