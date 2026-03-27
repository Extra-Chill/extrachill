<?php
/**
 * Rapper Name Generator block render template.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$title       = isset( $attributes['title'] ) ? $attributes['title'] : 'Rapper Name Generator';
$button_text = isset( $attributes['buttonText'] ) ? $attributes['buttonText'] : 'Generate Rapper Name';

$wrapper_attributes = get_block_wrapper_attributes(
	array(
		'class' => 'extrachill-blocks-rapper-name-generator',
	)
);
?>
<div <?php echo $wrapper_attributes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<h3><?php echo esc_html( $title ); ?></h3>
	<form class="extrachill-blocks-generator-form" data-generator-type="rapper">
		<div class="form-group">
			<label for="input"><?php esc_html_e( 'Your Name:', 'extrachill-content-blocks' ); ?></label>
			<input type="text" id="input" name="input" placeholder="<?php esc_attr_e( 'Enter your name', 'extrachill-content-blocks' ); ?>" required>
		</div>
		<div class="form-group">
			<label for="gender"><?php esc_html_e( 'Gender:', 'extrachill-content-blocks' ); ?></label>
			<select id="gender" name="gender">
				<option value="non-binary"><?php esc_html_e( 'Non-binary', 'extrachill-content-blocks' ); ?></option>
				<option value="male"><?php esc_html_e( 'Male', 'extrachill-content-blocks' ); ?></option>
				<option value="female"><?php esc_html_e( 'Female', 'extrachill-content-blocks' ); ?></option>
			</select>
		</div>
		<div class="form-group">
			<label for="style"><?php esc_html_e( 'Style:', 'extrachill-content-blocks' ); ?></label>
			<select id="style" name="style">
				<option value="random"><?php esc_html_e( 'Random', 'extrachill-content-blocks' ); ?></option>
				<option value="old school"><?php esc_html_e( 'Old School', 'extrachill-content-blocks' ); ?></option>
				<option value="trap"><?php esc_html_e( 'Trap', 'extrachill-content-blocks' ); ?></option>
				<option value="grime"><?php esc_html_e( 'Grime', 'extrachill-content-blocks' ); ?></option>
				<option value="conscious"><?php esc_html_e( 'Conscious', 'extrachill-content-blocks' ); ?></option>
			</select>
		</div>
		<div class="form-group">
			<label for="number_of_words"><?php esc_html_e( 'Number of Words:', 'extrachill-content-blocks' ); ?></label>
			<select id="number_of_words" name="number_of_words">
				<option value="2"><?php esc_html_e( '2 Words', 'extrachill-content-blocks' ); ?></option>
				<option value="3"><?php esc_html_e( '3 Words', 'extrachill-content-blocks' ); ?></option>
			</select>
		</div>
		<button type="submit" class="button-1 button-medium"><?php echo esc_html( $button_text ); ?></button>
	</form>
	<div class="extrachill-generator-message" style="display: none;"></div>
	<div class="extrachill-blocks-generator-result" style="display: none;">
		<div class="generated-name-wrap">
			<em><?php esc_html_e( 'Your rapper name will appear here', 'extrachill-content-blocks' ); ?></em>
		</div>
	</div>
</div>
