<?php
/** Site-owned recap photos. Safety approval is required: composition cannot be inferred from dimensions. */
namespace ExtraChillEvents\Templates;

class RoundupPhotos {
	public static function credit( string $content, array $meta, string $author ): array {
		$text   = html_entity_decode( wp_strip_all_tags( (string) preg_replace( '/<\/(?:p|div)>|<br\s*\/?\s*>/i', "\n", $content ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$name   = '';
		$handle = '';
		if ( preg_match( '/photos?\s+by\s+([^\n(@]+?)(?:\s*\(@([\w.]+)\)|\s+@([\w.]+)|[\n]|$)/iu', $text, $m ) ) {
			$name   = trim( $m[1], " .\t\r" );
			$handle = $m[2] ?? ( $m[3] ?? '' );
		}
		if ( '' === $name ) {
			$name = trim( (string) ( ! empty( $meta['copyright'] ) ? $meta['copyright'] : ( $meta['credit'] ?? '' ) ) );
		}
		return array(
			'photographer_name'      => '' !== $name ? $name : $author,
			'photographer_instagram' => $handle,
		);
	}

	public static function select( $background, int $index, string $location, array $used ): array {
		if ( empty( $background ) ) {
			return array();
		}
		$refs = is_array( $background ) ? $background : explode( ',', (string) $background );
		$ref  = $refs[ $index ] ?? ( count( $refs ) === 1 ? $refs[0] : '' );
		if ( '' === $ref ) {
			return array();
		}
		switch_to_blog( 1 );
		try {
			$history  = (array) get_option( 'extrachill_roundup_photo_history', array() );
			$week     = gmdate( 'o-W', strtotime( 'monday last week' ) );
			$previous = (array) ( $history[ $location ][ $week ] ?? array() );
			$tax      = array(
				array(
					'taxonomy' => 'category',
					'field'    => 'term_id',
					'terms'    => array( 2669 ),
				),
			);
			if ( '' !== $location ) {
				$tax[] = array(
					'taxonomy' => 'location',
					'field'    => is_numeric( $location ) ? 'term_id' : 'slug',
					'terms'    => array( $location ),
				);
			}
			$posts      = get_posts(
				array(
					'post_type'      => 'post',
					'post_status'    => 'publish',
					'posts_per_page' => 40,
					'tax_query'      => $tax,
				)
			);
			$candidates = array();
			foreach ( $posts as $post ) {
				$ids = array_keys( get_attached_media( 'image', $post->ID ) );
				preg_match_all( '/(?:wp-image-|"id"\s*:\s*)(\d+)/', $post->post_content, $matches );
				$ids = array_unique( array_merge( $ids, array_map( 'intval', $matches[1] ), array( (int) get_post_thumbnail_id( $post->ID ) ) ) );
				foreach ( $ids as $id ) {
					if ( ! $id || in_array( $id, array_merge( $used, $previous ), true ) || ( 'auto' !== $ref && (int) $ref !== $id ) ) {
						continue;
					}
					// Explicit editorial approval prevents attendee portraits being selected automatically.
					$composition = get_post_meta( $id, '_extrachill_roundup_composition', true );
					if ( ! in_array( $composition, array( 'room', 'performer' ), true ) ) {
						continue;
					}
					$meta   = (array) wp_get_attachment_metadata( $id );
					$credit = self::credit( $post->post_content, (array) ( $meta['image_meta'] ?? array() ), (string) get_the_author_meta( 'display_name', (int) $post->post_author ) );
					$path   = get_attached_file( $id );
					if ( '' === $credit['photographer_name'] || ! $path || ! is_readable( $path ) ) {
						continue;
					}
					$candidates[] = array_merge(
						$credit,
						array(
							'attachment_id'  => $id,
							'source_post_id' => $post->ID,
							'path'           => $path,
							'score'          => ( 'room' === $composition ? 100 : 0 ) + ( ( $meta['width'] ?? 0 ) > ( $meta['height'] ?? 0 ) ? 10 : 0 ),
						)
					);
				}
			}
			usort( $candidates, static fn( $a, $b ) => $b['score'] <=> $a['score'] );
			$photo = $candidates[0] ?? array();
			if ( $photo ) {
				$current                            = gmdate( 'o-W' );
				$history[ $location ]               = array_intersect_key( $history[ $location ] ?? array(), array_flip( array( $week, $current ) ) );
				$history[ $location ][ $current ][] = $photo['attachment_id'];
				update_option( 'extrachill_roundup_photo_history', $history, false );
			}
			return $photo;
		} finally {
			restore_current_blog();
		}
	}
}
