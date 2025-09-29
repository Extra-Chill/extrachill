<?php
/**
 * Render callback for the trivia block
 *
 * @param array $attributes Block attributes
 * @param string $content Block content
 * @param WP_Block $block Block instance
 * @return string Block HTML output
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Extract block attributes with defaults
$question = isset($attributes['question']) ? $attributes['question'] : '';
$options = isset($attributes['options']) ? $attributes['options'] : array('', '');
$correct_answer = isset($attributes['correctAnswer']) ? $attributes['correctAnswer'] : 0;
$answer_justification = isset($attributes['answerJustification']) ? $attributes['answerJustification'] : '';
$block_id = isset($attributes['blockId']) ? $attributes['blockId'] : uniqid('trivia_');
$result_messages = isset($attributes['resultMessages']) ? $attributes['resultMessages'] : array(
    'excellent' => '🏆 Trivia Master!',
    'good' => '🎉 Great Job!',
    'okay' => '👍 Not Bad!',
    'poor' => '🤔 Keep Trying!'
);
$score_ranges = isset($attributes['scoreRanges']) ? $attributes['scoreRanges'] : array(
    'excellent' => 90,
    'good' => 70,
    'okay' => 50
);

// Sanitize data
$question = wp_kses_post($question);
$options = array_map('sanitize_text_field', $options);
$correct_answer = intval($correct_answer);
$answer_justification = wp_kses_post($answer_justification);

// Don't render if question is empty
if (empty($question) || empty(array_filter($options))) {
    return '';
}

// Get block wrapper attributes
$wrapper_attributes = get_block_wrapper_attributes([
    'class' => 'extrachill-blocks-trivia-block',
    'data-block-id' => esc_attr($block_id),
    'data-correct-answer' => esc_attr($correct_answer),
    'data-answer-justification' => esc_attr($answer_justification),
    'data-result-messages' => esc_attr(wp_json_encode($result_messages)),
    'data-score-ranges' => esc_attr(wp_json_encode($score_ranges))
]);

// Build the HTML
ob_start();
?>
<div <?php echo $wrapper_attributes; ?>>
    <div class="extrachill-blocks-trivia-block__question">
        <h3><?php echo $question; ?></h3>
    </div>
    <div class="extrachill-blocks-trivia-block__options">
        <?php foreach ($options as $index => $option) : ?>
            <?php if (!empty($option)) : ?>
                <button 
                    class="extrachill-blocks-trivia-block__option" 
                    data-option-index="<?php echo esc_attr($index); ?>"
                    type="button"
                >
                    <?php echo esc_html($option); ?>
                </button>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>
    <div class="extrachill-blocks-trivia-block__feedback" style="display: none;"></div>
    <?php if (!empty($answer_justification)) : ?>
        <div class="extrachill-blocks-trivia-block__justification" style="display: none;">
            <div class="extrachill-blocks-trivia-block__justification-content">
                <?php echo $answer_justification; ?>
            </div>
        </div>
    <?php endif; ?>
</div>
<?php
return ob_get_clean();