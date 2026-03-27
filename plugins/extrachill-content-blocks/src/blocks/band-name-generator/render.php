<?php
/**
 * Band Name Generator block render template.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$title       = isset( $attributes['title'] ) ? $attributes['title'] : 'Band Name Generator';
$button_text = isset( $attributes['buttonText'] ) ? $attributes['buttonText'] : 'Generate Band Name';

$wrapper_attributes = get_block_wrapper_attributes(
	array(
		'class' => 'extrachill-blocks-band-name-generator',
	)
);
?>
<div <?php echo $wrapper_attributes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<h3><?php echo esc_html( $title ); ?></h3>
	<form class="extrachill-blocks-generator-form" data-generator-type="band">
		<div class="form-group">
			<label for="input"><?php esc_html_e( 'Your Name/Word:', 'extrachill-content-blocks' ); ?></label>
			<input type="text" id="input" name="input" placeholder="<?php esc_attr_e( 'Enter your name or word', 'extrachill-content-blocks' ); ?>" required>
		</div>
		<div class="form-group">
			<label for="genre"><?php esc_html_e( 'Genre:', 'extrachill-content-blocks' ); ?></label>
			<select id="genre" name="genre">
				<option value="rock"><?php esc_html_e( 'Rock', 'extrachill-content-blocks' ); ?></option>
				<option value="country"><?php esc_html_e( 'Country', 'extrachill-content-blocks' ); ?></option>
				<option value="metal"><?php esc_html_e( 'Metal', 'extrachill-content-blocks' ); ?></option>
				<option value="indie"><?php esc_html_e( 'Indie', 'extrachill-content-blocks' ); ?></option>
				<option value="punk"><?php esc_html_e( 'Punk', 'extrachill-content-blocks' ); ?></option>
				<option value="jam"><?php esc_html_e( 'Jam', 'extrachill-content-blocks' ); ?></option>
				<option value="electronic"><?php esc_html_e( 'Electronic', 'extrachill-content-blocks' ); ?></option>
				<option value="random"><?php esc_html_e( 'Random', 'extrachill-content-blocks' ); ?></option>
			</select>
		</div>
		<div class="form-group">
			<label for="number_of_words"><?php esc_html_e( 'Number of Words:', 'extrachill-content-blocks' ); ?></label>
			<select id="number_of_words" name="number_of_words">
				<option value="2"><?php esc_html_e( '2 Words', 'extrachill-content-blocks' ); ?></option>
				<option value="3"><?php esc_html_e( '3 Words', 'extrachill-content-blocks' ); ?></option>
				<option value="4"><?php esc_html_e( '4 Words', 'extrachill-content-blocks' ); ?></option>
			</select>
		</div>
		<div class="form-group">
			<label>
				<input type="checkbox" name="first-the" value="true">
				<?php esc_html_e( 'Add "The" at the beginning', 'extrachill-content-blocks' ); ?>
			</label>
		</div>
		<div class="form-group">
			<label>
				<input type="checkbox" name="and-the" value="true">
				<?php esc_html_e( 'Add "& The" in the middle', 'extrachill-content-blocks' ); ?>
			</label>
		</div>
		<button type="submit" class="button-1 button-medium"><?php echo esc_html( $button_text ); ?></button>
	</form>
	<div class="extrachill-generator-message" style="display: none;"></div>
	<div class="extrachill-blocks-generator-result" style="display: none;">
		<div class="generated-name-wrap">
			<em><?php esc_html_e( 'Your band name will appear here', 'extrachill-content-blocks' ); ?></em>
		</div>
	</div>
</div>
