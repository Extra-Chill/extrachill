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
			<label for="input"><?php esc_html_e( 'Your Name/Word:', 'extrachill-blog' ); ?></label>
			<input type="text" id="input" name="input" placeholder="<?php esc_attr_e( 'Enter your name or word', 'extrachill-blog' ); ?>" required>
		</div>
		<div class="form-group">
			<label for="genre"><?php esc_html_e( 'Genre:', 'extrachill-blog' ); ?></label>
			<select id="genre" name="genre">
				<option value="rock"><?php esc_html_e( 'Rock', 'extrachill-blog' ); ?></option>
				<option value="country"><?php esc_html_e( 'Country', 'extrachill-blog' ); ?></option>
				<option value="metal"><?php esc_html_e( 'Metal', 'extrachill-blog' ); ?></option>
				<option value="indie"><?php esc_html_e( 'Indie', 'extrachill-blog' ); ?></option>
				<option value="punk"><?php esc_html_e( 'Punk', 'extrachill-blog' ); ?></option>
				<option value="jam"><?php esc_html_e( 'Jam', 'extrachill-blog' ); ?></option>
				<option value="electronic"><?php esc_html_e( 'Electronic', 'extrachill-blog' ); ?></option>
				<option value="random"><?php esc_html_e( 'Random', 'extrachill-blog' ); ?></option>
			</select>
		</div>
		<div class="form-group">
			<label for="number_of_words"><?php esc_html_e( 'Number of Words:', 'extrachill-blog' ); ?></label>
			<select id="number_of_words" name="number_of_words">
				<option value="2"><?php esc_html_e( '2 Words', 'extrachill-blog' ); ?></option>
				<option value="3"><?php esc_html_e( '3 Words', 'extrachill-blog' ); ?></option>
				<option value="4"><?php esc_html_e( '4 Words', 'extrachill-blog' ); ?></option>
			</select>
		</div>
		<div class="form-group">
			<label>
				<input type="checkbox" name="first-the" value="true">
				<?php esc_html_e( 'Add "The" at the beginning', 'extrachill-blog' ); ?>
			</label>
		</div>
		<div class="form-group">
			<label>
				<input type="checkbox" name="and-the" value="true">
				<?php esc_html_e( 'Add "& The" in the middle', 'extrachill-blog' ); ?>
			</label>
		</div>
		<button type="submit" class="button-1 button-medium"><?php echo esc_html( $button_text ); ?></button>
	</form>
	<div class="extrachill-generator-message" style="display: none;"></div>
	<div class="extrachill-blocks-generator-result" style="display: none;">
		<div class="generated-name-wrap">
			<em><?php esc_html_e( 'Your band name will appear here', 'extrachill-blog' ); ?></em>
		</div>
	</div>
</div>
