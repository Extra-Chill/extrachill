<?php
/**
 * Band Name Generator block render template.
 *
 * Emits an empty mount root plus minimal JSON-island config. The React view
 * (view.tsx) renders the entire form and owns all interaction state. No
 * server-rendered markup is hydrated by reading data-* attributes.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$title       = isset( $attributes['title'] ) ? $attributes['title'] : 'Band Name Generator';
$button_text = isset( $attributes['buttonText'] ) ? $attributes['buttonText'] : 'Generate Band Name';

$config = array(
	'title'      => $title,
	'buttonText' => $button_text,
);

$wrapper_attributes = get_block_wrapper_attributes(
	array(
		'class' => 'extrachill-blocks-band-name-generator',
	)
);
?>
<div <?php echo $wrapper_attributes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<script type="application/json" class="extrachill-blocks-generator-config">
		<?php echo wp_json_encode( $config ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
	</script>
</div>
