<?php
/**
 * In-memory wpdb stand-in for the standalone test harness.
 *
 * @package ExtraChillLinkPages
 */

/**
 * Fakes the subset of wpdb queries the Link Pages runtime issues, backed by $GLOBALS['ec_test'].
 */
class EcTestWpdb {
	public $postmeta           = 'wp_postmeta';
	public $posts              = 'wp_posts';
	public $comments           = 'wp_comments';
	public $term_relationships = 'wp_term_relationships';
	public $last_error         = '';
	public $insert_id          = 0;
	private $locks             = array();
	public function prepare( $query, ...$args ) {
		return vsprintf( str_replace( '%s', "'%s'", $query ), $args ); }
	public function get_blog_prefix( $blog_id ) {
		return 'wp_' . (int) $blog_id . '_'; }
	public function get_results( $query, $output = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- stub mirrors the wpdb signature.
		$blog_id = get_current_blog_id();
		if ( preg_match( "/SELECT \* FROM wp_posts WHERE post_type = '([^']+)'/", $query, $type_match ) ) {
			$posts = array_values(
				array_filter(
					$GLOBALS['ec_test']['blogs'][ $blog_id ]['posts'],
					static function ( $post ) use ( $type_match ) {
						return $type_match[1] === $post->post_type;
					}
				)
			);
			usort(
				$posts,
				static function ( $left, $right ) {
					return $left->ID <=> $right->ID;
				}
			);
			return $posts;
		}
		if ( preg_match( '/SELECT post_id, meta_key, meta_value FROM wp_postmeta WHERE post_id IN \(([^)]+)\)/', $query, $matches ) ) {
			$ids  = array_map( 'intval', explode( ',', $matches[1] ) );
			$rows = array();
			foreach ( $GLOBALS['ec_test']['meta_rows'] ?? array() as $meta_id => $row ) {
				if ( $blog_id === (int) $row['blog_id'] && in_array( (int) $row['post_id'], $ids, true ) ) {
					$rows[ $meta_id ] = array_intersect_key( $row, array_flip( array( 'post_id', 'meta_key', 'meta_value' ) ) );
				}
			}
			ksort( $rows, SORT_NUMERIC );
			return array_values( $rows );
		}
		return array();
	}
	public function get_col( $query ) {
		$posts = $GLOBALS['ec_test']['blogs'][ get_current_blog_id() ]['posts'];
		$ids   = array();
		if ( preg_match( "/post_type = 'attachment' AND post_parent = (\d+)/", $query, $matches ) ) {
			foreach ( $posts as $id => $post ) {
				if ( 'attachment' === $post->post_type && (int) $post->post_parent === (int) $matches[1] ) {
					$ids[] = (int) $id; }
			}
		} elseif ( preg_match( "/post_name = '([^']+)'/", $query, $matches ) ) {
			foreach ( $posts as $id => $post ) {
				if ( $matches[1] === $post->post_name ) {
					$ids[] = (int) $id; }
			}
		}
		sort( $ids, SORT_NUMERIC );
		return $ids;
	}
	public function get_row( $query ) {
		if ( preg_match( '/FROM wp_postmeta WHERE meta_id = (\d+)/', $query, $matches ) ) {
			$row = $GLOBALS['ec_test']['meta_rows'][ (int) $matches[1] ] ?? null;
			return $row && get_current_blog_id() === (int) $row['blog_id'] ? array_intersect_key( $row, array_flip( array( 'post_id', 'meta_key', 'meta_value' ) ) ) : null;
		}
		if ( preg_match( '/FROM wp_(\d+)_posts WHERE ID = (\d+)/', $query, $matches ) ) {
			$post = $GLOBALS['ec_test']['blogs'][ (int) $matches[1] ]['posts'][ (int) $matches[2] ] ?? null;
			return $post ? (object) array(
				'ID'          => $post->ID,
				'post_type'   => $post->post_type,
				'post_status' => $post->post_status ?? 'publish',
			) : null;
		}
		if ( preg_match( "/FROM wp_(\d+)_term_taxonomy WHERE term_id = (\d+) AND taxonomy = '([^']+)'/", $query, $matches ) ) {
			$term = $GLOBALS['ec_test']['blogs'][ (int) $matches[1] ]['terms'][ (int) $matches[2] ] ?? null;
			return $term && $term->taxonomy === $matches[3] ? (object) array(
				'term_id'  => $term->term_id,
				'taxonomy' => $term->taxonomy,
			) : null;
		}
		if ( preg_match( '/FROM wp_(\d+)_term_taxonomy WHERE term_id = (\d+)/', $query, $matches ) ) {
			$term = $GLOBALS['ec_test']['blogs'][ (int) $matches[1] ]['terms'][ (int) $matches[2] ] ?? null;
			return $term ? (object) array(
				'term_id'  => $term->term_id,
				'taxonomy' => $term->taxonomy,
			) : null;
		}
		return null;
	}
	public function get_var( $query ) {
		if ( false !== strpos( $query, 'GET_LOCK' ) ) {
			++$GLOBALS['ec_test']['lock_acquires'];
			preg_match( "/GET_LOCK\('([^']+)'/", $query, $matches );
			$lock_name = $matches[1] ?? $query;
			if ( ! empty( $GLOBALS['ec_test']['fail_advisory_lock'] ) || isset( $this->locks[ $lock_name ] ) ) {
				return '0'; }
			$this->locks[ $lock_name ]                = true;
			$GLOBALS['ec_test']['advisory_lock_held'] = true;
			return '1';
		}
		if ( false !== strpos( $query, 'RELEASE_LOCK' ) ) {
			++$GLOBALS['ec_test']['lock_releases'];
			preg_match( "/RELEASE_LOCK\('([^']+)'/", $query, $matches );
			unset( $this->locks[ $matches[1] ?? $query ] );
			$GLOBALS['ec_test']['advisory_lock_held'] = ! empty( $this->locks );
			return '1';
		}
		if ( false !== strpos( $query, 'COUNT(*)' ) ) {
			return '0'; }
		return '1';
	}
	public function insert( $table, $data, $formats = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- stub mirrors the wpdb signature.
		if ( $this->postmeta !== $table || ! empty( $GLOBALS['ec_test']['fail_raw_meta_insert'] ) ) {
			return false; }
		$meta_id                                     = ++$GLOBALS['ec_test']['next_meta_id'];
		$this->insert_id                             = $meta_id;
		$GLOBALS['ec_test']['meta_rows'][ $meta_id ] = array_merge( array( 'blog_id' => get_current_blog_id() ), $data );
		$GLOBALS['ec_test']['blogs'][ get_current_blog_id() ]['post_meta'][ $data['post_id'] ][ $data['meta_key'] ][] = maybe_unserialize( $data['meta_value'] );
		if ( isset( $GLOBALS['ec_test']['after_raw_meta_insert'] ) ) {
			$callback = $GLOBALS['ec_test']['after_raw_meta_insert'];
			unset( $GLOBALS['ec_test']['after_raw_meta_insert'] );
			$callback( $meta_id );
		}
		return 1;
	}
	public function update( $table, $data, $where, $formats = null, $where_formats = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- stub mirrors the wpdb signature.
		if ( $this->posts !== $table || ! isset( $where['ID'] ) || ! get_post( $where['ID'] ) ) {
			return false; }
		foreach ( $data as $key => $value ) {
			$GLOBALS['ec_test']['blogs'][ get_current_blog_id() ]['posts'][ $where['ID'] ]->{$key} = $value; }
		return 1;
	}
}
