<?php
/**
 * Image Voting block render template.
 *
 * Emits an empty mount root plus minimal JSON-island config. The React view
 * (view.tsx) renders the image, vote badges, and voting form and owns all
 * interaction state. No server-rendered markup is hydrated by reading data-*
 * attributes.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$title      = isset( $attributes['blockTitle'] ) ? $attributes['blockTitle'] : 'Vote for this image';
$vote_count = isset( $attributes['voteCount'] ) ? (int) $attributes['voteCount'] : 0;
$media_id   = isset( $attributes['mediaID'] ) ? (int) $attributes['mediaID'] : 0;
$media_url  = isset( $attributes['mediaURL'] ) ? $attributes['mediaURL'] : '';

if ( $media_id && empty( $media_url ) ) {
	$media_url = wp_get_attachment_url( $media_id );
}

$post_id = get_the_ID();
if ( ! $post_id && is_admin() ) {
	global $post;
	$post_id = isset( $post->ID ) ? $post->ID : 0;
}

$block_instance_id = isset( $attributes['uniqueBlockId'] ) ? $attributes['uniqueBlockId'] : '';
$voters            = isset( $attributes['voters'] ) ? $attributes['voters'] : array();

$config = array(
	'title'         => $title,
	'voteCount'     => $vote_count,
	'mediaUrl'      => $media_url,
	'postId'        => (int) $post_id,
	'blockInstance' => $block_instance_id,
	'voters'        => array_values( (array) $voters ),
	'isAdmin'       => is_admin(),
);

$wrapper_attributes = get_block_wrapper_attributes(
	array(
		'class' => 'extrachill-blocks-image-voting-container',
	)
);
?>
<div <?php echo $wrapper_attributes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<script type="application/json" class="extrachill-blocks-image-voting-config">
		<?php echo wp_json_encode( $config ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
	</script>
</div>
