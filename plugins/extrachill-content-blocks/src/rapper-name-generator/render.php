<?php
/**
 * Rapper Name Generator block render callback
 */

if (!defined('ABSPATH')) {
    exit;
}

$title = isset($attributes['title']) ? $attributes['title'] : 'Rapper Name Generator';
$button_text = isset($attributes['buttonText']) ? $attributes['buttonText'] : 'Generate Rapper Name';

$wrapper_attributes = get_block_wrapper_attributes([
    'class' => 'extrachill-blocks-rapper-name-generator'
]);

$message_container = '<div class="extrachill-generator-message" style="display: none;"></div>';

ob_start();
?>
<div <?php echo $wrapper_attributes; ?>>
    <h3><?php echo esc_html($title); ?></h3>
    <form class="extrachill-blocks-generator-form" data-generator-type="rapper">
        <div class="form-group">
            <label for="input"><?php _e('Your Name:', 'extrachill-blocks'); ?></label>
            <input type="text" id="input" name="input" placeholder="<?php _e('Enter your name', 'extrachill-blocks'); ?>" required>
        </div>
        <div class="form-group">
            <label for="gender"><?php _e('Gender:', 'extrachill-blocks'); ?></label>
            <select id="gender" name="gender">
                <option value="non-binary"><?php _e('Non-binary', 'extrachill-blocks'); ?></option>
                <option value="male"><?php _e('Male', 'extrachill-blocks'); ?></option>
                <option value="female"><?php _e('Female', 'extrachill-blocks'); ?></option>
            </select>
        </div>
        <div class="form-group">
            <label for="style"><?php _e('Style:', 'extrachill-blocks'); ?></label>
            <select id="style" name="style">
                <option value="random"><?php _e('Random', 'extrachill-blocks'); ?></option>
                <option value="old school"><?php _e('Old School', 'extrachill-blocks'); ?></option>
                <option value="trap"><?php _e('Trap', 'extrachill-blocks'); ?></option>
                <option value="grime"><?php _e('Grime', 'extrachill-blocks'); ?></option>
                <option value="conscious"><?php _e('Conscious', 'extrachill-blocks'); ?></option>
            </select>
        </div>
        <div class="form-group">
            <label for="number_of_words"><?php _e('Number of Words:', 'extrachill-blocks'); ?></label>
            <select id="number_of_words" name="number_of_words">
                <option value="2"><?php _e('2 Words', 'extrachill-blocks'); ?></option>
                <option value="3"><?php _e('3 Words', 'extrachill-blocks'); ?></option>
            </select>
        </div>
        <button type="submit" class="button-1 button-medium"><?php echo esc_html($button_text); ?></button>
    </form>
    <?php echo $message_container; ?>
    <div class="extrachill-blocks-generator-result" style="display: none;">
        <div class="generated-name-wrap">
            <em><?php _e('Your rapper name will appear here', 'extrachill-blocks'); ?></em>
        </div>
    </div>
</div>
<?php
return ob_get_clean();