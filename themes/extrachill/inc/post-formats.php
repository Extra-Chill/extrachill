<?php if ( has_post_format( 'gallery' ) ) : ?>
	<?php if (get_post_gallery()) : ?>
		<div class="gallery-post-format">
			<?php
			$output = '';
			$galleries = get_post_gallery( $post, false );
			$attachment_ids = explode( ",", $galleries['ids'] );
			$output = '<ul class="gallery-images">';
			foreach( $attachment_ids as $attachment_id ) {
				// displaying the attached image of gallery
				$link = wp_get_attachment_image( $attachment_id, 'colormag-featured-image' );
				$output .= '<li>' . $link . '</li>';
			}
			$output .= '</ul>';
			echo $output;
			?>
		</div>
	<?php endif; ?>
<?php endif; ?>
<?php if ( has_post_format( 'video' ) ) : ?>
	<?php $video_post_url = get_post_meta($post->ID, 'video_url', true); ?>
	<?php if ( !empty($video_post_url) ) : ?>
		<div class="fitvids-video">
			<?php
			$embed_code = wp_oembed_get( $video_post_url );
			echo $embed_code;
			?>
		</div>
	<?php endif; ?>
<?php endif; ?>
