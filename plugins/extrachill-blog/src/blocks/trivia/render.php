<?php
if (!defined('ABSPATH')) {
    exit;
}

$question = isset($attributes['question']) ? $attributes['question'] : '';
$options = isset($attributes['options']) ? $attributes['options'] : array('', '');
$correct_answer = isset($attributes['correctAnswer']) ? intval($attributes['correctAnswer']) : 0;
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

if (empty($question) || empty(array_filter($options))) {
    return '';
}

$wrapper_attributes = get_block_wrapper_attributes([
    'class' => 'trivia-block',
    'data-block-id' => esc_attr($block_id),
    'data-correct-answer' => esc_attr($correct_answer),
    'data-answer-justification' => esc_attr($answer_justification),
    'data-result-messages' => esc_attr(wp_json_encode($result_messages)),
    'data-score-ranges' => esc_attr(wp_json_encode($score_ranges))
]);

ob_start();
?>
<div <?php echo $wrapper_attributes; ?>>
    <div class="trivia-block__question">
        <h3><?php echo wp_kses_post($question); ?></h3>
    </div>
    <div class="trivia-block__options">
        <?php foreach ($options as $index => $option) : ?>
            <?php if (!empty($option)) : ?>
                <button
                    class="trivia-block__option"
                    data-option-index="<?php echo esc_attr($index); ?>"
                    type="button"
                >
                    <?php echo esc_html($option); ?>
                </button>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>
    <div class="trivia-block__feedback" style="display: none;"></div>
    <?php if (!empty($answer_justification)) : ?>
        <div class="trivia-block__justification" style="display: none;">
            <div class="trivia-block__justification-content">
                <?php echo wp_kses_post($answer_justification); ?>
            </div>
        </div>
    <?php endif; ?>
</div>
<?php
return ob_get_clean();