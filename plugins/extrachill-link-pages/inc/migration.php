<?php
/**
 * Reversible multisite storage migration for Link Pages.
 *
 * @package ExtraChillLinkPages
 */

defined( 'ABSPATH' ) || exit;

define( 'EC_LINK_PAGE_MIGRATION_JOURNAL_INDEX_OPTION', 'ec_link_page_storage_migration_journal_index_v1' );
define( 'EC_LINK_PAGE_MIGRATION_JOURNAL_PREFIX', 'ec_link_page_storage_migration_journal_v1_' );
define( 'EC_LINK_PAGE_MIGRATION_PLAN_SUFFIX', '_plan' );
define( 'EC_LINK_PAGE_MIGRATION_SCHEMA_VERSION', 1 );
define( 'EC_LINK_PAGE_MIGRATION_JOURNAL_LIMIT', 25 );

/** Return the append-only migration participant registry. */
function ec_link_page_migration_participant_registry() {
	static $registry = null;
	if ( null === $registry ) {
		$registry = new class() {
			private $participants = array();

			public function register( $name, $contract_version, $callbacks, $priority ) {
				if ( ! is_string( $name ) || 1 !== preg_match( '/^[a-z0-9][a-z0-9_-]*$/', $name ) || ! is_string( $contract_version ) || '' === $contract_version || ! is_int( $priority ) || ! is_array( $callbacks ) ) {
					return new WP_Error( 'invalid_link_page_migration_participant', 'The migration participant registration is invalid.' );
				}
				foreach ( array( 'claim_owner', 'plan', 'apply', 'validate', 'rollback' ) as $operation ) {
					if ( empty( $callbacks[ $operation ] ) || ! is_callable( $callbacks[ $operation ] ) ) {
						return new WP_Error( 'invalid_link_page_migration_participant', 'Every migration participant callback is required.' );
					}
				}
				if ( isset( $this->participants[ $name ] ) ) {
					return new WP_Error( 'duplicate_link_page_migration_participant', 'The migration participant is already registered.' );
				}
				$this->participants[ $name ] = compact( 'name', 'contract_version', 'callbacks', 'priority' );
				return true;
			}

			public function snapshot() {
				$participants = array_values( $this->participants );
				usort(
					$participants,
					static function ( $a, $b ) {
						$order = $a['priority'] <=> $b['priority'];
						return 0 !== $order ? $order : strcmp( $a['name'], $b['name'] );
					}
				);
				return $participants;
			}
		};
	}
	return $registry;
}

/** Register one named owner of migration side effects. */
function ec_register_link_page_migration_participant( $name, $contract_version, $callbacks, $priority = 10 ) {
	return ec_link_page_migration_participant_registry()->register( $name, $contract_version, $callbacks, $priority );
}

/** Execute in one blog while exactly restoring a nested multisite context. */
function ec_link_page_migration_restore_context( $blog_id, $stack, $switched ) {
	$current_stack = isset( $GLOBALS['_wp_switched_stack'] ) && is_array( $GLOBALS['_wp_switched_stack'] ) ? $GLOBALS['_wp_switched_stack'] : array();
	$valid         = count( $current_stack ) >= count( $stack ) && array_slice( $current_stack, 0, count( $stack ) ) === $stack;
	$restored      = ec_restore_link_pages_site_context( $blog_id, $stack, $switched );
	return $valid && $restored;
}

/** Execute in one blog while exactly restoring a nested multisite context. */
function ec_link_page_migration_in_blog( $blog_id, $callback ) {
	$blog_id = absint( $blog_id );
	if ( ! $blog_id || ! get_site( $blog_id ) || ! is_callable( $callback ) ) {
		return new WP_Error( 'invalid_link_page_migration_blog', 'The migration blog context is invalid.' );
	}
	$entry_blog   = get_current_blog_id();
	$entry_stack  = isset( $GLOBALS['_wp_switched_stack'] ) && is_array( $GLOBALS['_wp_switched_stack'] ) ? $GLOBALS['_wp_switched_stack'] : array();
	$entry_switch = ! empty( $GLOBALS['switched'] );
	$switched     = $entry_blog !== $blog_id;
	if ( $switched && ! switch_to_blog( $blog_id ) ) {
		return new WP_Error( 'link_page_migration_context_failed', 'The migration blog context could not be entered.' );
	}
	$result = null;
	try {
		$result = call_user_func( $callback );
	} catch ( Throwable $throwable ) {
		$result = new WP_Error( 'link_page_migration_exception', $throwable->getMessage() );
	} finally {
		$restored = ec_link_page_migration_restore_context( $entry_blog, $entry_stack, $entry_switch );
	}
	return $restored ? $result : new WP_Error( 'link_page_migration_context_restore_failed', 'The exact multisite context could not be restored.' );
}

/** Canonically normalize an array before hashing. */
function ec_link_page_migration_canonicalize( $value ) {
	if ( ! is_array( $value ) ) {
		return $value;
	}
	if ( array_keys( $value ) !== range( 0, count( $value ) - 1 ) ) {
		ksort( $value, SORT_STRING );
	}
	foreach ( $value as $key => $item ) {
		$value[ $key ] = ec_link_page_migration_canonicalize( $item );
	}
	return $value;
}

/** Produce a stable SHA-256 fingerprint. */
function ec_link_page_migration_hash( $value ) {
	return hash( 'sha256', wp_json_encode( ec_link_page_migration_canonicalize( $value ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
}

/** Return the exact persisted post fields used by core insertion. */
function ec_link_page_migration_post_fields( $post ) {
	$fields = array( 'post_author', 'post_date', 'post_date_gmt', 'post_content', 'post_title', 'post_excerpt', 'post_status', 'comment_status', 'ping_status', 'post_password', 'post_name', 'to_ping', 'pinged', 'post_modified', 'post_modified_gmt', 'post_content_filtered', 'post_parent', 'guid', 'menu_order', 'post_type', 'post_mime_type' );
	$data   = array( 'ID' => (int) $post->ID );
	foreach ( $fields as $field ) {
		$value          = isset( $post->{$field} ) ? $post->{$field} : '';
		$data[ $field ] = in_array( $field, array( 'post_parent', 'menu_order' ), true ) ? (int) $value : $value;
	}
	return $data;
}

/** Read raw metadata rows without collapsing duplicates. */
function ec_link_page_migration_meta_rows( $post_ids ) {
	global $wpdb;
	if ( empty( $post_ids ) ) {
		return array();
	}
	$ids  = implode( ',', array_map( 'absint', $post_ids ) );
	$rows = $wpdb->get_results( "SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id IN ({$ids}) ORDER BY post_id, meta_id", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact duplicate rows require direct ordered reads.
	return is_array( $rows ) ? array_map(
		static function ( $row ) {
			return array(
				'post_id'    => (int) $row['post_id'],
				'meta_key'   => (string) $row['meta_key'], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Descriptor field, not a query argument.
				'meta_value' => (string) $row['meta_value'], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Descriptor field, not a query argument.
			);
		},
		$rows
	) : array();
}

/** Invalidate metadata caches and prove runtime reads expose every copied row. */
function ec_link_page_migration_verify_runtime_meta( $rows ) {
	$expected = array();
	foreach ( $rows as $row ) {
		$expected[ (int) $row['post_id'] ][ (string) $row['meta_key'] ][] = maybe_unserialize( $row['meta_value'] );
	}
	foreach ( $expected as $post_id => $keys ) {
		clean_post_cache( $post_id );
		wp_cache_delete( $post_id, 'post_meta' );
		foreach ( $keys as $key => $values ) {
			if ( get_post_meta( $post_id, $key, false ) !== $values ) {
				return new WP_Error(
					'link_page_migration_meta_runtime_mismatch',
					'Runtime metadata reads do not expose the copied raw rows.',
					array(
						'post_id'  => $post_id,
						'meta_key' => $key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Error descriptor, not a query.
					)
				);
			}
		}
	}
	return true;
}

/** Resolve an existing path and prove it remains inside a real uploads root. */
function ec_link_page_migration_realpath( $base, $path ) {
	$real_base = realpath( $base );
	$real_path = realpath( $path );
	if ( false === $real_base || false === $real_path ) {
		return false; }
	$real_base = trailingslashit( wp_normalize_path( $real_base ) );
	$real_path = wp_normalize_path( $real_path );
	return 0 === strpos( $real_path . ( is_dir( $real_path ) ? '/' : '' ), $real_base ) ? $real_path : false;
}

/** Prove the nearest existing destination ancestor resolves inside uploads. */
function ec_link_page_migration_destination_path_safe( $base, $path ) {
	$ancestor = dirname( $path );
	while ( ! file_exists( $ancestor ) && dirname( $ancestor ) !== $ancestor ) {
		$ancestor = dirname( $ancestor ); }
	return false !== ec_link_page_migration_realpath( $base, $ancestor );
}

/** Return every safe local file represented by attachment metadata. */
function ec_link_page_migration_attachment_files( $attachment_id ) {
	$relative = (string) get_post_meta( $attachment_id, '_wp_attached_file', true );
	if ( '' === $relative ) {
		return new WP_Error( 'missing_link_page_attached_file', 'An attachment has no _wp_attached_file value.', array( 'attachment_id' => $attachment_id ) ); }
	$meta   = wp_get_attachment_metadata( $attachment_id );
	$backup = get_post_meta( $attachment_id, '_wp_attachment_backup_sizes', true );
	$paths  = array( $relative );
	$dir    = dirname( $relative );
	foreach ( is_array( $meta['sizes'] ?? null ) ? $meta['sizes'] : array() as $size ) {
		if ( ! empty( $size['file'] ) ) {
			$paths[] = ( '.' === $dir ? '' : $dir . '/' ) . $size['file'];
		}
	}
	if ( ! empty( $meta['original_image'] ) ) {
		$paths[] = ( '.' === $dir ? '' : $dir . '/' ) . $meta['original_image'];
	}
	foreach ( array( 'thumb', 'source_image', 'animated_video', 'animated_video_poster' ) as $companion_key ) {
		if ( ! empty( $meta[ $companion_key ] ) && is_string( $meta[ $companion_key ] ) ) {
			$paths[] = ( '.' === $dir ? '' : $dir . '/' ) . $meta[ $companion_key ]; }
	}
	foreach ( is_array( $backup ) ? $backup : array() as $size ) {
		if ( ! empty( $size['file'] ) ) {
			$paths[] = ( '.' === $dir ? '' : $dir . '/' ) . $size['file'];
		}
	}
	$uploads = wp_upload_dir();
	$result  = array();
	foreach ( array_values( array_unique( array_filter( $paths ) ) ) as $path ) {
		$path = wp_normalize_path( $path );
		if ( '' === $path || 0 === strpos( $path, '/' ) || preg_match( '#(?:^|/)\.\.(?:/|$)#', $path ) ) {
			return new WP_Error(
				'unsafe_link_page_attachment_path',
				'An attachment path escapes the uploads directory.',
				array(
					'attachment_id' => $attachment_id,
					'path'          => $path,
				)
			);
		}
		$absolute = ec_link_page_migration_realpath( $uploads['basedir'], trailingslashit( $uploads['basedir'] ) . $path );
		if ( false === $absolute || ! is_file( $absolute ) || ! is_readable( $absolute ) ) {
			return new WP_Error(
				'missing_link_page_attachment_file',
				'A required attachment file is missing.',
				array(
					'attachment_id' => $attachment_id,
					'path'          => $path,
				)
			);
		}
		$result[] = array(
			'path'   => $path,
			'sha256' => hash_file( 'sha256', $absolute ),
			'bytes'  => filesize( $absolute ),
		);
	}
	usort(
		$result,
		static function ( $a, $b ) {
			return strcmp( $a['path'], $b['path'] );
		}
	);
	return $result;
}

/** Invoke one participant without leaking a switched blog context. */
function ec_link_page_migration_invoke_participant( $participant, $operation, $context ) {
	$blog_id  = get_current_blog_id();
	$stack    = isset( $GLOBALS['_wp_switched_stack'] ) && is_array( $GLOBALS['_wp_switched_stack'] ) ? $GLOBALS['_wp_switched_stack'] : array();
	$switched = ! empty( $GLOBALS['switched'] );
	try {
		$result = call_user_func( $participant['callbacks'][ $operation ], $context );
	} catch ( Throwable $throwable ) {
		$result = new WP_Error( 'link_page_migration_participant_exception', $throwable->getMessage(), array( 'participant' => $participant['name'] ) );
	} finally {
		$restored = ec_link_page_migration_restore_context( $blog_id, $stack, $switched );
	}
	return $restored ? $result : new WP_Error( 'link_page_migration_participant_context_corrupt', 'A migration participant corrupted the multisite context.', array( 'participant' => $participant['name'] ) );
}

/** Build the complete read-only migration inventory. */
function ec_plan_link_page_storage_migration( $source_blog_id, $destination_blog_id, $include_readiness = true, $required_participant_ids = array() ) {
	global $wpdb;
	$source_blog_id      = absint( $source_blog_id );
	$destination_blog_id = absint( $destination_blog_id );
	$source_site         = get_site( $source_blog_id );
	$destination_site    = get_site( $destination_blog_id );
	$network_id          = get_current_network_id();
	$source_network      = (int) ( $source_site->network_id ?? $source_site->site_id ?? 0 );
	$destination_network = (int) ( $destination_site->network_id ?? $destination_site->site_id ?? 0 );
	if ( ! is_multisite() || ! $source_blog_id || ! $destination_blog_id || $source_blog_id === $destination_blog_id || ! $source_site || ! $destination_site || $network_id !== $source_network || $network_id !== $destination_network ) {
		return new WP_Error( 'invalid_link_page_migration_sites', 'Distinct existing multisite source and destination blogs are required.' );
	}
	$plan   = array(
		'schema_version'      => EC_LINK_PAGE_MIGRATION_SCHEMA_VERSION,
		'network_id'          => $network_id,
		'mode'                => 'plan',
		'source_blog_id'      => $source_blog_id,
		'destination_blog_id' => $destination_blog_id,
		'posts'               => array(),
		'meta'                => array(),
		'attachments'         => array(),
		'participants'        => array(),
		'owner_claims'        => array(),
		'collisions'          => array(),
		'missing'             => array(),
		'unsupported'         => array(),
	);
	$source = ec_link_page_migration_in_blog(
		$source_blog_id,
		static function () use ( &$plan, $include_readiness ) {
			global $wpdb;
			$posts = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->posts} WHERE post_type = %s ORDER BY ID ASC", EC_LINK_PAGE_POST_TYPE ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact all-status inventory.
			if ( null === $posts || '' !== $wpdb->last_error ) {
				return new WP_Error( 'link_page_migration_inventory_failed', 'The exact Link Page inventory query failed.' ); }
			$ids = array_map(
				static function ( $post ) {
					return (int) $post->ID;
				},
				$posts
			);
			foreach ( $posts as $post ) {
				$plan['posts'][] = ec_link_page_migration_post_fields( $post );
			}
			if ( $ids ) {
				$list   = implode( ',', $ids );
				$checks = array(
					'comments'               => "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_post_ID IN ({$list})",
					'revisions'              => "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_parent IN ({$list}) AND post_type = 'revision'",
					'taxonomy_relationships' => "SELECT COUNT(*) FROM {$wpdb->term_relationships} WHERE object_id IN ({$list})",
				);
				foreach ( $checks as $name => $sql ) {
					$count = (int) $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- IDs are locally cast integers.
					if ( $count ) {
						$plan['unsupported'][] = array(
							'type'  => $name,
							'count' => $count,
						); }
				}
			}
			$plan['meta'] = ec_link_page_migration_meta_rows( $ids );
			$owners       = array();
			$slugs        = array();
			foreach ( $plan['posts'] as $post ) {
				if ( (int) $post['post_parent'] && ! in_array( (int) $post['post_parent'], $ids, true ) ) {
					$plan['unsupported'][] = array(
						'type'        => 'external_link_page_parent',
						'post_id'     => $post['ID'],
						'post_parent' => (int) $post['post_parent'],
					);
				}
				$owner = ec_get_link_page_owner( $post['ID'] );
				if ( is_wp_error( $owner ) || ! $owner ) {
					$plan['unsupported'][] = array(
						'type'    => 'invalid_owner',
						'post_id' => $post['ID'],
					); } elseif ( isset( $owners[ $owner['reference'] ] ) ) {
					$plan['unsupported'][] = array(
						'type'    => 'duplicate_owner',
						'post_id' => $post['ID'],
					); } else {
						$owners[ $owner['reference'] ] = $post['ID'];
						$plan['owners'][ $post['ID'] ] = $owner; }
					if ( isset( $slugs[ $post['post_name'] ] ) ) {
						$plan['unsupported'][] = array(
							'type'    => 'duplicate_slug',
							'post_id' => $post['ID'],
						); }
					$slugs[ $post['post_name'] ] = $post['ID'];
			}
			$context = array(
				'mode'                => $include_readiness ? 'readiness' : 'source_inventory',
				'source_blog_id'      => $plan['source_blog_id'],
				'destination_blog_id' => $plan['destination_blog_id'],
				'link_page_ids'       => $ids,
				'attachment_map'      => array(),
				'fingerprint'         => '',
				'journal_id'          => '',
				'journal_record'      => null,
			);
			foreach ( $plan['owners'] ?? array() as $link_page_id => $owner ) {
				$claims = array();
				foreach ( ec_link_page_migration_participant_registry()->snapshot() as $participant ) {
					$claimed = ec_link_page_migration_invoke_participant(
						$participant,
						'claim_owner',
						array_merge(
							$context,
							array(
								'link_page_id' => (int) $link_page_id,
								'owner'        => $owner,
							)
						)
					);
					if ( is_wp_error( $claimed ) ) {
						$plan['unsupported'][] = array(
							'type'        => 'owner_claim_error',
							'participant' => $participant['name'],
							'post_id'     => (int) $link_page_id,
						); } elseif ( true === $claimed ) {
						$claims[] = array(
							'id'               => $participant['name'],
							'contract_version' => $participant['contract_version'],
						); }
				}
				if ( 1 !== count( $claims ) ) {
					$plan['unsupported'][] = array(
						'type'    => 'owner_claim_count',
						'post_id' => (int) $link_page_id,
						'count'   => count( $claims ),
					); } else {
					$plan['owner_claims'][ $link_page_id ] = $claims[0]; }
			}
			$attachment_ids       = array();
			$attachment_semantics = array();
			foreach ( $plan['meta'] as $row ) {
				$value = maybe_unserialize( $row['meta_value'] );
				if ( in_array( $row['meta_key'], array( '_thumbnail_id', '_link_page_background_image_id', '_link_page_profile_image_id' ), true ) && absint( $value ) ) {
					$attachment_ids[] = absint( $value ); }
			}
			foreach ( $ids as $id ) {
				$children       = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_parent = %d ORDER BY ID ASC", $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact all-status child inventory.
				$attachment_ids = array_merge( $attachment_ids, $children );
			}
			foreach ( ec_link_page_migration_participant_registry()->snapshot() as $participant ) {
				$result = ec_link_page_migration_invoke_participant( $participant, 'plan', $context );
				if ( is_wp_error( $result ) ) {
					$plan['unsupported'][] = array(
						'type'  => 'participant',
						'name'  => $participant['name'],
						'error' => $result->get_error_message(),
					);
					continue; }
				if ( ! is_array( $result ) || empty( $result['fingerprint'] ) ) {
					$plan['unsupported'][] = array(
						'type' => 'participant_plan',
						'name' => $participant['name'],
					);
					continue; }
				$result['attachment_ids'] = array_values( array_unique( array_map( 'absint', $result['attachment_ids'] ?? array() ) ) );
				$attachment_ids           = array_merge( $attachment_ids, $result['attachment_ids'] );
				foreach ( $result['attachment_semantics'] ?? array() as $semantic ) {
					$attachment_id           = absint( $semantic['attachment_id'] ?? 0 );
					$semantic['participant'] = $participant['name'];
					if ( ! $attachment_id || ! array_key_exists( 'destination_parent', $semantic ) || ( isset( $attachment_semantics[ $attachment_id ] ) && $attachment_semantics[ $attachment_id ] !== $semantic ) ) {
						$plan['unsupported'][] = array(
							'type'          => 'attachment_semantics',
							'name'          => $participant['name'],
							'attachment_id' => $attachment_id,
						);
						continue;
					}
					$attachment_semantics[ $attachment_id ] = $semantic;
				}
				$plan['participants'][ $participant['name'] ] = $result;
			}
			$attachment_ids = array_values( array_unique( array_filter( array_map( 'absint', $attachment_ids ) ) ) );
			sort( $attachment_ids, SORT_NUMERIC );
			foreach ( $attachment_ids as $id ) {
				$post = get_post( $id );
				if ( ! $post || 'attachment' !== $post->post_type ) {
					$plan['missing'][] = array(
						'type' => 'attachment',
						'id'   => $id,
					);
					continue; }
				$files = ec_link_page_migration_attachment_files( $id );
				if ( is_wp_error( $files ) ) {
					$plan['missing'][] = array_merge(
						array(
							'type' => 'file',
							'id'   => $id,
						),
						(array) $files->get_error_data()
					);
					continue; }
				$fields           = ec_link_page_migration_post_fields( $post );
				$source_parent    = (int) $fields['post_parent'];
				$internal_parent  = in_array( $source_parent, $ids, true );
				$semantic         = $attachment_semantics[ $id ] ?? null;
				$requested_parent = $internal_parent ? $source_parent : -1;
				if ( ! $internal_parent && is_array( $semantic ) && 0 === absint( $semantic['destination_parent'] ?? -1 ) ) {
					$owner_reference = (string) ( $semantic['owner_reference'] ?? '' );
					$owner_post_id   = 0;
					foreach ( $plan['owners'] ?? array() as $link_page_id => $owner ) {
						if ( 0 === strcmp( (string) ( $owner['reference'] ?? '' ), $owner_reference ) && 0 === strcmp( $semantic['participant'], (string) ( $plan['owner_claims'][ $link_page_id ]['id'] ?? '' ) ) ) {
							$owner_post_id = (int) $link_page_id;
							break;
						}
					}
					if ( $owner_post_id ) {
						$requested_parent = 0;
					}
				}
				if ( ! in_array( $requested_parent, array_merge( array( 0 ), $ids ), true ) || ( $internal_parent && $requested_parent !== $source_parent ) ) {
					$plan['unsupported'][] = array(
						'type' => 'attachment_parent_semantics',
						'id'   => $id,
					);
					continue;
				}
				$fields['post_parent'] = $requested_parent;
				$plan['attachments'][] = array(
					'post'                  => $fields,
					'source_parent'         => $source_parent,
					'destination_parent'    => (int) $fields['post_parent'],
					'participant_semantics' => $semantic,
					'files'                 => $files,
				);
			}
			$attachment_object_ids = array_column( array_column( $plan['attachments'], 'post' ), 'ID' );
			if ( $attachment_object_ids ) {
				$list   = implode( ',', array_map( 'absint', $attachment_object_ids ) );
				$checks = array(
					'attachment_comments'               => "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_post_ID IN ({$list})",
					'attachment_revisions'              => "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_parent IN ({$list}) AND post_type = 'revision'",
					'attachment_taxonomy_relationships' => "SELECT COUNT(*) FROM {$wpdb->term_relationships} WHERE object_id IN ({$list})",
				);
				foreach ( $checks as $name => $sql ) {
					$count = (int) $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- IDs are locally cast integers.
					if ( $count ) {
						$plan['unsupported'][] = array(
							'type'  => $name,
							'count' => $count,
						);
					}
				}
			}
			$plan['attachment_meta'] = ec_link_page_migration_meta_rows( array_column( array_column( $plan['attachments'], 'post' ), 'ID' ) );
			return true;
		}
	);
	if ( is_wp_error( $source ) ) {
		return $source; }
	$registered_participants = array();
	foreach ( ec_link_page_migration_participant_registry()->snapshot() as $participant ) {
		$registered_participants[ $participant['name'] ] = $participant['contract_version']; }
	foreach ( array_values( array_unique( array_map( 'sanitize_key', $required_participant_ids ) ) ) as $required_id ) {
		if ( ! isset( $registered_participants[ $required_id ] ) ) {
			$plan['unsupported'][] = array(
				'type'        => 'required_participant_missing',
				'participant' => $required_id,
			); } else {
			$plan['caller_required_participants'][] = array(
				'id'               => $required_id,
				'contract_version' => $registered_participants[ $required_id ],
			); }
	}
	$destination = $include_readiness ? ec_link_page_migration_in_blog(
		$destination_blog_id,
		static function () use ( &$plan ) {
			$uploads = wp_upload_dir();
			foreach ( array_merge( array_column( $plan['posts'], 'ID' ), array_column( array_column( $plan['attachments'], 'post' ), 'ID' ) ) as $id ) {
				if ( get_post( $id ) ) {
					$plan['collisions'][] = array(
						'type' => 'post_id',
						'id'   => $id,
					); }
			}
			foreach ( array_merge( $plan['posts'], array_column( $plan['attachments'], 'post' ) ) as $post ) {
				$unique_slug = wp_unique_post_slug( $post['post_name'], 0, $post['post_status'], $post['post_type'], (int) $post['post_parent'] );
				if ( $unique_slug !== $post['post_name'] ) {
					$plan['collisions'][] = array(
						'type'        => 'slug',
						'slug'        => $post['post_name'],
						'unique_slug' => $unique_slug,
						'post_type'   => $post['post_type'],
					);
				}
			}
			foreach ( $plan['attachments'] as $attachment ) {
				foreach ( $attachment['files'] as $file ) {
					$destination_path = trailingslashit( $uploads['basedir'] ) . $file['path'];
					if ( ! ec_link_page_migration_destination_path_safe( $uploads['basedir'], $destination_path ) ) {
						$plan['unsupported'][] = array(
							'type' => 'unsafe_destination_path',
							'path' => $file['path'],
						); }
					if ( file_exists( $destination_path ) ) {
						$plan['collisions'][] = array(
							'type' => 'file',
							'id'   => $attachment['post']['ID'],
							'path' => $file['path'],
						); }
				}
			}
			return true;
		}
	) : true;
	if ( is_wp_error( $destination ) ) {
		return $destination; }
	$source_material     = array_intersect_key( $plan, array_flip( array( 'schema_version', 'network_id', 'source_blog_id', 'destination_blog_id', 'posts', 'meta', 'owners', 'owner_claims', 'caller_required_participants', 'attachments', 'attachment_meta', 'participants', 'unsupported', 'missing' ) ) );
	$plan['fingerprint'] = ec_link_page_migration_hash( $source_material );
	$plan['ready']       = empty( $plan['collisions'] ) && empty( $plan['missing'] ) && empty( $plan['unsupported'] );
	$plan['counts']      = array(
		'posts'        => count( $plan['posts'] ),
		'attachments'  => count( $plan['attachments'] ),
		'meta_rows'    => count( $plan['meta'] ) + count( $plan['attachment_meta'] ),
		'participants' => count( $plan['participants'] ),
	);
	return $plan;
}

/** Execute a complete source/destination lifecycle operation under one lock. */
function ec_link_page_migration_with_operation_lock( $source_blog_id, $destination_blog_id, $callback ) {
	global $wpdb;
	$pair = array( absint( $source_blog_id ), absint( $destination_blog_id ) );
	sort( $pair, SORT_NUMERIC );
	$name = 'ec_link_page_migration_operation:' . get_current_network_id() . ':' . implode( ':', $pair );
	if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 10)', $name ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Operation serialization.
		return new WP_Error( 'link_page_migration_operation_lock_failed', 'The migration operation lock could not be acquired.' );
	}
	try {
		$result = call_user_func( $callback );
	} catch ( Throwable $throwable ) {
		$result = new WP_Error( 'link_page_migration_operation_exception', $throwable->getMessage() );
	}
	$released = $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Matching advisory lock release.
	return '1' === (string) $released ? $result : new WP_Error( 'link_page_migration_operation_lock_release_failed', 'The migration operation lock could not be released.' );
}

/** Execute one network journal write under its advisory lock. */
function ec_link_page_migration_with_journal_lock( $callback ) {
	global $wpdb;
	$name = 'ec_link_page_migration_journal:' . get_current_network_id();
	if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 10)', $name ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Network journal serialization.
		return new WP_Error( 'link_page_migration_journal_lock_failed', 'The network migration journal lock could not be acquired.' );
	}
	try {
		$result = call_user_func( $callback ); } catch ( Throwable $throwable ) {
		$result = new WP_Error( 'link_page_migration_journal_write_exception', $throwable->getMessage() ); }
		$released = $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Matching advisory lock release.
		return '1' === (string) $released ? $result : new WP_Error( 'link_page_migration_journal_lock_release_failed', 'The network migration journal lock could not be released.' );
}

/** Return the versioned option key for one journal or entry. */
function ec_link_page_migration_journal_key( $journal_id, $sequence = 0 ) {
	$key = EC_LINK_PAGE_MIGRATION_JOURNAL_PREFIX . sanitize_key( $journal_id );
	return $sequence ? $key . '_entry_' . absint( $sequence ) : $key;
}

/** Return the immutable plan option key for one journal. */
function ec_link_page_migration_plan_key( $journal_id ) {
	return ec_link_page_migration_journal_key( $journal_id ) . EC_LINK_PAGE_MIGRATION_PLAN_SUFFIX;
}

/** Delete every durable option piece owned by one journal. */
function ec_link_page_migration_delete_journal( $journal_id, $entry_count ) {
	$entry_count = absint( $entry_count );
	for ( $sequence = 1; $sequence <= $entry_count; ++$sequence ) {
		delete_network_option( null, ec_link_page_migration_journal_key( $journal_id, $sequence ) );
	}
	delete_network_option( null, ec_link_page_migration_plan_key( $journal_id ) );
	delete_network_option( null, ec_link_page_migration_journal_key( $journal_id ) );
}

/** Persist and read back one journal header plus its protected index record. */
function ec_link_page_migration_store_journal( $journal ) {
	return ec_link_page_migration_with_journal_lock(
		static function () use ( $journal ) {
			$header = $journal;
			$plan   = array(
				'participant_plans' => $journal['participant_plans'] ?? array(),
				'source_inventory'  => $journal['source_inventory'] ?? array(),
			);
			unset( $header['entries'], $header['participant_plans'], $header['source_inventory'] );
			$header['entry_count'] = count( $journal['entries'] ?? array() );
			$key                   = ec_link_page_migration_journal_key( $journal['id'] );
			$existing_header       = get_network_option( null, $key, null );
			$index                 = get_network_option( null, EC_LINK_PAGE_MIGRATION_JOURNAL_INDEX_OPTION, array() );
			$index                 = is_array( $index ) ? $index : array();
			if ( ! is_array( $existing_header ) && ! isset( $index[ $journal['id'] ] ) && count( $index ) >= EC_LINK_PAGE_MIGRATION_JOURNAL_LIMIT ) {
				$removable = array_filter(
					$index,
					static function ( $item ) {
						return 'rolled_back' === ( $item['status'] ?? '' );
					}
				);
				uasort(
					$removable,
					static function ( $left, $right ) {
						return strcmp( (string) ( $left['created_at'] ?? '' ), (string) ( $right['created_at'] ?? '' ) );
					}
				);
				$oldest_id = key( $removable );
				if ( null === $oldest_id ) {
					return new WP_Error( 'link_page_migration_journal_capacity', 'The migration journal is full of rollbackable records; roll one back before applying another migration.' );
				}
				$old_header = get_network_option( null, ec_link_page_migration_journal_key( $oldest_id ), array() );
				ec_link_page_migration_delete_journal( $oldest_id, $old_header['entry_count'] ?? 0 );
				unset( $index[ $oldest_id ] );
			}
			$plan_key      = ec_link_page_migration_plan_key( $journal['id'] );
			$existing_plan = get_network_option( null, $plan_key, null );
			if ( null === $existing_plan ) {
				update_network_option( null, $plan_key, $plan );
			} elseif ( $existing_plan !== $plan ) {
				return new WP_Error( 'link_page_migration_plan_changed', 'The immutable migration journal plan changed.' );
			}
			if ( get_network_option( null, $plan_key, null ) !== $plan ) {
				return new WP_Error( 'link_page_migration_journal_write_failed', 'The immutable migration plan could not be verified.' );
			}
			update_network_option( null, $key, $header );
			if ( get_network_option( null, $key, null ) !== $header ) {
				return new WP_Error( 'link_page_migration_journal_write_failed', 'The durable migration journal header could not be verified.' );
			}
			$index[ $journal['id'] ] = array(
				'status'     => $journal['status'],
				'created_at' => $journal['created_at'],
				'network_id' => $journal['network_id'],
			);
			update_network_option( null, EC_LINK_PAGE_MIGRATION_JOURNAL_INDEX_OPTION, $index );
			if ( get_network_option( null, EC_LINK_PAGE_MIGRATION_JOURNAL_INDEX_OPTION, null ) !== $index ) {
				return new WP_Error( 'link_page_migration_journal_write_failed', 'The durable migration journal index could not be verified.' );
			}
			return true;
		}
	);
}

/** Persist and read back one independently addressed journal mutation entry. */
function ec_link_page_migration_store_entry( $journal_id, $entry ) {
	return ec_link_page_migration_with_journal_lock(
		static function () use ( $journal_id, $entry ) {
			$key = ec_link_page_migration_journal_key( $journal_id, $entry['sequence'] );
			update_network_option( null, $key, $entry );
			return get_network_option( null, $key, null ) === $entry ? true : new WP_Error( 'link_page_migration_journal_write_failed', 'A durable migration mutation entry could not be verified.' );
		}
	);
}

/** Read one network-affine durable migration journal. */
function ec_link_page_migration_get_journal( $journal_id ) {
	$header = get_network_option( null, ec_link_page_migration_journal_key( $journal_id ), null );
	if ( ! is_array( $header ) ) {
		return new WP_Error( 'link_page_migration_journal_not_found', 'The migration journal was not found.' ); }
	if ( get_current_network_id() !== (int) $header['network_id'] ) {
		return new WP_Error( 'link_page_migration_network_mismatch', 'The migration journal belongs to another network.' ); }
	$plan = get_network_option( null, ec_link_page_migration_plan_key( $journal_id ), null );
	if ( ! is_array( $plan ) ) {
		return new WP_Error( 'link_page_migration_journal_incomplete', 'The durable immutable migration plan is missing.' );
	}
	$header['participant_plans'] = $plan['participant_plans'] ?? array();
	$header['source_inventory']  = $plan['source_inventory'] ?? array();
	$header['entries']           = array();
	for ( $sequence = 1; $sequence <= (int) $header['entry_count']; ++$sequence ) {
		$entry = get_network_option( null, ec_link_page_migration_journal_key( $journal_id, $sequence ), null );
		if ( ! is_array( $entry ) ) {
			return new WP_Error( 'link_page_migration_journal_incomplete', 'A durable migration mutation entry is missing.' ); }
		$header['entries'][] = $entry;
	}
	return $header;
}

/** Append intent, persist, execute, then mark applied. */
function ec_link_page_migration_mutate( &$journal, $entry, $callback ) {
	$entry['applied']     = false;
	$entry['sequence']    = count( $journal['entries'] ) + 1;
	$journal['entries'][] = $entry;
	$stored               = ec_link_page_migration_store_entry( $journal['id'], $entry );
	if ( is_wp_error( $stored ) ) {
		return $stored; }
	$stored = ec_link_page_migration_store_journal( $journal );
	if ( is_wp_error( $stored ) ) {
		return $stored; }
	$result = call_user_func( $callback );
	if ( is_wp_error( $result ) || false === $result ) {
		return is_wp_error( $result ) ? $result : new WP_Error( 'link_page_migration_mutation_failed', 'A journaled migration mutation failed.' ); }
	$journal['entries'][ count( $journal['entries'] ) - 1 ]['applied'] = true;
	$stored = ec_link_page_migration_store_entry( $journal['id'], $journal['entries'][ count( $journal['entries'] ) - 1 ] );
	if ( is_wp_error( $stored ) ) {
		return $stored; }
	return $result;
}

/** Merge participant requirements stably while rejecting contract conflicts. */
function ec_link_page_migration_merge_required_participants( $groups ) {
	$merged = array();
	$seen   = array();
	foreach ( $groups as $group ) {
		foreach ( is_array( $group ) ? $group : array() as $item ) {
			$id      = sanitize_key( $item['id'] ?? '' );
			$version = (string) ( $item['contract_version'] ?? '' );
			if ( ! $id || '' === $version ) {
				continue;
			}
			if ( isset( $seen[ $id ] ) && $seen[ $id ] !== $version ) {
				return new WP_Error( 'link_page_migration_participant_contract_conflict', 'A required migration participant has conflicting contract versions.', array( 'id' => $id ) );
			}
			if ( ! isset( $seen[ $id ] ) ) {
				$seen[ $id ] = $version;
				$merged[]    = array(
					'id'               => $id,
					'contract_version' => $version,
				);
			}
		}
	}
	return $merged;
}

/** Compare-and-set a durable journal status and update the caller snapshot. */
function ec_link_page_migration_transition_status( &$journal, $expected_statuses, $next_status ) {
	return ec_link_page_migration_with_journal_lock(
		static function () use ( &$journal, $expected_statuses, $next_status ) {
			$key    = ec_link_page_migration_journal_key( $journal['id'] );
			$header = get_network_option( null, $key, null );
			if ( ! is_array( $header ) || ! in_array( $header['status'] ?? '', $expected_statuses, true ) ) {
				return new WP_Error( 'link_page_migration_status_race', 'The migration journal status changed concurrently.', array( 'status' => $header['status'] ?? null ) );
			}
			$header['status']  = $next_status;
			$journal['status'] = $next_status;
			update_network_option( null, $key, $header );
			if ( get_network_option( null, $key, null ) !== $header ) {
				return new WP_Error( 'link_page_migration_journal_write_failed', 'The durable migration status could not be verified.' );
			}
			$index = get_network_option( null, EC_LINK_PAGE_MIGRATION_JOURNAL_INDEX_OPTION, array() );
			if ( ! isset( $index[ $journal['id'] ] ) ) {
				return new WP_Error( 'link_page_migration_journal_incomplete', 'The migration journal index record is missing.' );
			}
			$index[ $journal['id'] ]['status'] = $next_status;
			update_network_option( null, EC_LINK_PAGE_MIGRATION_JOURNAL_INDEX_OPTION, $index );
			return get_network_option( null, EC_LINK_PAGE_MIGRATION_JOURNAL_INDEX_OPTION, null ) === $index ? true : new WP_Error( 'link_page_migration_journal_write_failed', 'The durable migration index status could not be verified.' );
		}
	);
}

/** Require every journaled participant ID and contract version. */
function ec_link_page_migration_require_participants( $journal ) {
	$registered = array();
	foreach ( ec_link_page_migration_participant_registry()->snapshot() as $participant ) {
		$registered[ $participant['name'] ] = $participant; }
	foreach ( $journal['required_participants'] ?? array() as $required ) {
		if ( ! isset( $registered[ $required['id'] ] ) || $registered[ $required['id'] ]['contract_version'] !== $required['contract_version'] ) {
			return new WP_Error( 'link_page_migration_participant_contract_missing', 'A journal-required migration participant or contract version is unavailable.', $required );
		}
	}
	return true;
}

/** Return required registered participants in deterministic registry order. */
function ec_link_page_migration_required_participants( $journal, $reverse = false ) {
	$required = array_column( $journal['required_participants'] ?? array(), 'contract_version', 'id' );
	$result   = array_values(
		array_filter(
			ec_link_page_migration_participant_registry()->snapshot(),
			static function ( $participant ) use ( $required ) {
				return isset( $required[ $participant['name'] ] ) && $participant['contract_version'] === $required[ $participant['name'] ];
			}
		)
	);
	return $reverse ? array_reverse( $result ) : $result;
}

/** Locate and verify the single temporary-token object owned by an insert intent. */
function ec_link_page_migration_find_token_post( $entry ) {
	global $wpdb;
	$token = (string) ( $entry['temporary_slug'] ?? '' );
	if ( '' === $token ) {
		return null;
	}
	$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_name = %s ORDER BY ID ASC", $token ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Recovery of a journal-owned temporary insert token.
	if ( count( $ids ) > 1 ) {
		return new WP_Error( 'link_page_migration_rollback_state_mismatch', 'The temporary migration token is ambiguous.', $entry );
	}
	if ( ! $ids ) {
		return null;
	}
	$post = get_post( (int) $ids[0] );
	if ( ! $post || $token !== (string) $post->post_name || (string) ( $entry['post_type'] ?? '' ) !== (string) $post->post_type ) {
		return new WP_Error( 'link_page_migration_rollback_state_mismatch', 'A temporary migration object no longer matches its ownership token.', $entry );
	}
	return $post;
}

/** Return the exact post object currently owned by a journal phase. */
function ec_link_page_migration_owned_post( $entry ) {
	$actual_id = (int) ( $entry['actual_id'] ?? 0 );
	$post      = $actual_id ? get_post( $actual_id ) : ec_link_page_migration_find_token_post( $entry );
	if ( is_wp_error( $post ) || ! $post ) {
		return $post;
	}
	$phase = (string) ( $entry['phase'] ?? 'intent' );
	if ( 'finalized' === $phase ) {
		return ec_link_page_migration_post_fields( $post ) === $entry['expected_post'] ? $post : new WP_Error( 'link_page_migration_rollback_state_mismatch', 'A destination post no longer matches journal-owned final state.', $entry );
	}
	if ( 'finalizing' === $phase ) {
		$current = ec_link_page_migration_post_fields( $post );
		$known   = array_filter( array( $entry['phase_post'] ?? null, $entry['finalization_post'] ?? null, $entry['expected_post'] ?? null ) );
		return in_array( $current, $known, true ) ? $post : new WP_Error( 'link_page_migration_rollback_state_mismatch', 'A destination post no longer matches a known finalization state.', $entry );
	}
	if ( 'inserted' === $phase && ! empty( $entry['phase_post'] ) && ec_link_page_migration_post_fields( $post ) !== $entry['phase_post'] ) {
		return new WP_Error( 'link_page_migration_rollback_state_mismatch', 'A destination post no longer matches journal-owned inserted state.', $entry );
	}
	return $post;
}

/** Immediately remove one exact journal-owned insert after ID persistence fails. */
function ec_link_page_migration_delete_owned_post( &$entry ) {
	$owned = ec_link_page_migration_owned_post( $entry );
	if ( is_wp_error( $owned ) ) {
		return $owned;
	}
	if ( $owned && ! wp_delete_post( (int) $owned->ID, true ) ) {
		return new WP_Error( 'link_page_migration_immediate_compensation_failed', 'An interrupted imported object could not be removed.', $entry );
	}
	$entry['mismatch_compensated'] = true;
	return true;
}

/** Roll back core-owned journal entries in reverse dependency order. */
function ec_link_page_migration_compensate( &$journal ) {
	$required = ec_link_page_migration_require_participants( $journal );
	if ( is_wp_error( $required ) ) {
		$journal['status'] = 'failed';
		$stored            = ec_link_page_migration_store_journal( $journal );
		return is_wp_error( $stored ) ? $stored : $required; }
	$stored = ec_link_page_migration_transition_status( $journal, array( 'applying', 'applied', 'failed' ), 'rolling_back' );
	if ( is_wp_error( $stored ) ) {
		return $stored; }
	$errors  = array();
	$context = ec_link_page_migration_journal_context( $journal );
	foreach ( ec_link_page_migration_required_participants( $journal, true ) as $participant ) {
		$result = ec_link_page_migration_invoke_participant( $participant, 'rollback', $context );
		if ( is_wp_error( $result ) ) {
			$errors[] = array(
				'participant' => $participant['name'],
				'error'       => $result->get_error_message(),
			); } else {
			foreach ( $journal['entries'] as &$participant_entry ) {
				if ( 'participant' === ( $participant_entry['type'] ?? '' ) && ( $participant_entry['participant'] ?? '' ) === $participant['name'] && ! empty( $participant_entry['applied'] ) ) {
					$participant_entry['rolled_back'] = true;
					$stored                           = ec_link_page_migration_store_entry( $journal['id'], $participant_entry );
					if ( is_wp_error( $stored ) ) {
						$errors[] = array(
							'participant' => $participant['name'],
							'error'       => $stored->get_error_message(),
						); }
				}
			}
			unset( $participant_entry );
			}
	}
	for ( $i = count( $journal['entries'] ) - 1; $i >= 0; --$i ) {
		$entry =& $journal['entries'][ $i ];
		if ( ! empty( $entry['rolled_back'] ) ) {
			continue; }
		if ( 'participant' === $entry['type'] ) {
			continue; }
		$result = ec_link_page_migration_in_blog(
			$journal['destination_blog_id'],
			static function () use ( $entry, $journal ) {
				if ( 'file' === $entry['type'] ) {
					$uploads = wp_upload_dir();
					$real    = file_exists( $entry['path'] ) ? ec_link_page_migration_realpath( $uploads['basedir'], $entry['path'] ) : false;
					if ( file_exists( $entry['path'] ) && ( false === $real || ! hash_equals( $entry['sha256'], hash_file( 'sha256', $real ) ) ) ) {
						return new WP_Error( 'link_page_migration_rollback_state_mismatch', 'A destination file no longer matches journal-owned state.', $entry ); }
					if ( file_exists( $entry['path'] ) ) {
						wp_delete_file( $entry['path'] );
					}
					return ! file_exists( $entry['path'] ); }
				if ( 'meta' === $entry['type'] ) {
					global $wpdb;
					$row = $entry['meta_id'] ? $wpdb->get_row( $wpdb->prepare( "SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE meta_id = %d", $entry['meta_id'] ), ARRAY_A ) : null; // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact rollback ownership proof.
					if ( ! $row ) {
						return true; }
					if ( (int) $row['post_id'] !== (int) $entry['post_id'] || $row['meta_key'] !== $entry['meta_key'] || $row['meta_value'] !== $entry['meta_value'] ) {
						return new WP_Error( 'link_page_migration_rollback_state_mismatch', 'A destination metadata row no longer matches journal-owned state.', $entry ); }
					return delete_metadata_by_mid( 'post', $entry['meta_id'] ); }
				if ( 'post' === $entry['type'] ) {
					if ( ! empty( $entry['mismatch_compensated'] ) ) {
						return true; }
					$owned = ec_link_page_migration_owned_post( $entry );
					if ( is_wp_error( $owned ) ) {
						return $owned; }
					return ! $owned || (bool) wp_delete_post( (int) $owned->ID, true ); }
				return true;
			}
		);
		if ( is_wp_error( $result ) || ! $result ) {
			$errors[] = array(
				'sequence' => $entry['sequence'],
				'error'    => is_wp_error( $result ) ? $result->get_error_message() : 'Rollback failed.',
			); } else {
			$entry['rolled_back'] = true;
			$stored               = ec_link_page_migration_store_entry( $journal['id'], $entry );
			if ( is_wp_error( $stored ) ) {
				$errors[] = array(
					'sequence' => $entry['sequence'],
					'error'    => $stored->get_error_message(),
				); }
			}
	}
	$remaining_participant_entries = array_filter(
		$journal['entries'],
		static function ( $entry ) {
			return 'participant' === ( $entry['type'] ?? '' ) && ! empty( $entry['applied'] ) && empty( $entry['rolled_back'] );
		}
	);
	if ( $remaining_participant_entries ) {
		$errors[] = array( 'error' => 'Applied participant journal entries remain.' ); }
	$journal['status']          = $errors ? 'failed' : 'rolled_back';
	$journal['rollback_errors'] = $errors;
	$stored                     = ec_link_page_migration_store_journal( $journal );
	if ( is_wp_error( $stored ) ) {
		return $stored; }
	return $errors ? new WP_Error( 'link_page_migration_rollback_incomplete', 'Rollback was incomplete.', $errors ) : $journal;
}

/** Build the callback-bearing participant context from a journal. */
function ec_link_page_migration_journal_context( &$journal ) {
	return array(
		'source_blog_id'      => $journal['source_blog_id'],
		'destination_blog_id' => $journal['destination_blog_id'],
		'link_page_ids'       => $journal['link_page_ids'],
		'attachment_map'      => $journal['attachment_map'],
		'fingerprint'         => $journal['fingerprint'],
		'journal_id'          => $journal['id'],
		'journal_entries'     => $journal['entries'],
		'participant_plans'   => $journal['participant_plans'] ?? array(),
		'journal_record'      => static function ( $entry, $callback ) use ( &$journal ) {
			$entry['type'] = 'participant';
			return ec_link_page_migration_mutate( $journal, $entry, $callback );
		},
	);
}

/**
 * Apply a previously fingerprinted plan without changing source or routing.
 *
 * @throws RuntimeException When a journaled mutation fails.
 */
function ec_apply_link_page_storage_migration_unlocked( $source_blog_id, $destination_blog_id, $expected_fingerprint, $required_participant_ids = array() ) {
	$plan = ec_plan_link_page_storage_migration( $source_blog_id, $destination_blog_id, true, $required_participant_ids );
	if ( is_wp_error( $plan ) ) {
		return $plan; }
	if ( ! hash_equals( (string) $expected_fingerprint, $plan['fingerprint'] ) ) {
		return new WP_Error( 'link_page_migration_source_drift', 'The source fingerprint changed; generate a fresh plan.' ); }
	if ( ! $plan['ready'] ) {
		return new WP_Error( 'link_page_migration_preflight_failed', 'Migration preflight found blockers.', $plan ); }
	$required_participants = ec_link_page_migration_merge_required_participants(
		array(
			array_values( $plan['owner_claims'] ),
			$plan['caller_required_participants'] ?? array(),
		)
	);
	if ( is_wp_error( $required_participants ) ) {
		return $required_participants;
	}
	$journal = array(
		'schema_version'                  => EC_LINK_PAGE_MIGRATION_SCHEMA_VERSION,
		'network_id'                      => $plan['network_id'],
		'id'                              => wp_generate_uuid4(),
		'status'                          => 'applying',
		'created_at'                      => gmdate( 'c' ),
		'source_blog_id'                  => (int) $source_blog_id,
		'destination_blog_id'             => (int) $destination_blog_id,
		'fingerprint'                     => $plan['fingerprint'],
		'link_page_ids'                   => array_column( $plan['posts'], 'ID' ),
		'attachment_map'                  => array_combine( array_column( array_column( $plan['attachments'], 'post' ), 'ID' ), array_column( array_column( $plan['attachments'], 'post' ), 'ID' ) ),
		'entries'                         => array(),
		'errors'                          => array(),
		'participant_plans'               => $plan['participants'],
		'required_participants'           => $required_participants,
		'caller_required_participant_ids' => array_column( $plan['caller_required_participants'] ?? array(), 'id' ),
		'source_inventory'                => $plan,
	);
	if ( false === $journal['attachment_map'] ) {
		$journal['attachment_map'] = array();
	}
	$stored = ec_link_page_migration_store_journal( $journal );
	if ( is_wp_error( $stored ) ) {
		return $stored; }
	$failure = null;
	try {
		$result = ec_link_page_migration_in_blog(
			$destination_blog_id,
			static function () use ( &$journal, $plan, $source_blog_id ) {
				global $wpdb;
				$attachment_ids = array_column( array_column( $plan['attachments'], 'post' ), 'ID' );
				foreach ( array_merge( $plan['posts'], array_column( $plan['attachments'], 'post' ) ) as $post ) {
					$id             = (int) $post['ID'];
					$data           = $post;
					$temporary_slug = 'ec-migration-' . str_replace( '-', '', $journal['id'] ) . '-' . $id;
					unset( $data['ID'] );
					$data['import_id'] = $id;
					$data['post_name'] = $temporary_slug;
					$inserted          = ec_link_page_migration_mutate(
						$journal,
						array(
							'type'           => 'post',
							'phase'          => 'intent',
							'post_type'      => $post['post_type'],
							'temporary_slug' => $temporary_slug,
							'requested_id'   => $id,
							'actual_id'      => 0,
							'expected_post'  => $post,
						),
						static function () use ( &$journal, $data, $id, $post, $attachment_ids, $wpdb ) {
							$result = in_array( $id, $attachment_ids, true ) ? wp_insert_attachment( wp_slash( $data ), false, (int) $data['post_parent'], true ) : wp_insert_post( wp_slash( $data ), true );
							if ( is_wp_error( $result ) ) {
								return $result; }
							$position                                      = count( $journal['entries'] ) - 1;
							$journal['entries'][ $position ]['actual_id']  = (int) $result;
							$journal['entries'][ $position ]['phase']      = 'inserted';
							$journal['entries'][ $position ]['phase_post'] = ec_link_page_migration_post_fields( get_post( (int) $result ) );
							$stored                                        = ec_link_page_migration_store_entry( $journal['id'], $journal['entries'][ $position ] );
							if ( is_wp_error( $stored ) ) {
								$compensated = ec_link_page_migration_delete_owned_post( $journal['entries'][ $position ] );
								return is_wp_error( $compensated ) ? $compensated : $stored; }
							if ( (int) $result !== $id ) {
								$actual_post = ec_link_page_migration_owned_post( $journal['entries'][ $position ] );
								if ( ! is_wp_error( $actual_post ) && $actual_post ) {
									wp_delete_post( (int) $result, true );
									$journal['entries'][ $position ]['mismatch_compensated'] = ! get_post( (int) $result );
									$stored = ec_link_page_migration_store_entry( $journal['id'], $journal['entries'][ $position ] );
									if ( is_wp_error( $stored ) ) {
										return $stored;
									}
								}
								return new WP_Error(
									'link_page_migration_id_mismatch',
									'Core did not preserve an imported object ID.',
									array(
										'expected' => $id,
										'actual'   => $result,
									)
								);
							}
							$journal['entries'][ $position ]['phase'] = 'finalizing';
							$stored                                   = ec_link_page_migration_store_entry( $journal['id'], $journal['entries'][ $position ] );
							if ( is_wp_error( $stored ) ) {
								$compensated = ec_link_page_migration_delete_owned_post( $journal['entries'][ $position ] );
								return is_wp_error( $compensated ) ? $compensated : $stored;
							}
							$final_data       = $post;
							$final_data['ID'] = $id;
							$updated          = wp_update_post( wp_slash( $final_data ), true );
							if ( is_wp_error( $updated ) || (int) $updated !== $id ) {
								return is_wp_error( $updated ) ? $updated : new WP_Error( 'link_page_migration_post_finalize_failed', 'The imported object could not be finalized.' );
							}
							// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Exact bounded timestamp repair.
							$wpdb->update(
								$wpdb->posts,
								array(
									'post_modified'     => $post['post_modified'],
									'post_modified_gmt' => $post['post_modified_gmt'],
								),
								array( 'ID' => $id ),
								array( '%s', '%s' ),
								array( '%d' )
							);
							// phpcs:enable WordPress.DB.DirectDatabaseQuery
							clean_post_cache( $id );
							$actual = ec_link_page_migration_post_fields( get_post( $id ) );
							$journal['entries'][ $position ]['finalization_post'] = $actual;
							$stored = ec_link_page_migration_store_entry( $journal['id'], $journal['entries'][ $position ] );
							if ( is_wp_error( $stored ) ) {
								return $stored;
							}
							if ( $actual !== $post ) {
								return new WP_Error(
									'link_page_migration_post_mismatch',
									'Core did not preserve exact post fields.',
									array(
										'post_id'  => $id,
										'expected' => $post,
										'actual'   => $actual,
									)
								);
							}
							$journal['entries'][ $position ]['phase'] = 'finalized';
							$stored                                   = ec_link_page_migration_store_entry( $journal['id'], $journal['entries'][ $position ] );
							return is_wp_error( $stored ) ? $stored : $result;
						}
					);
					if ( is_wp_error( $inserted ) ) {
						return $inserted; }
					foreach ( array_keys( get_post_meta( $id ) ) as $generated_meta_key ) {
						delete_post_meta( $id, $generated_meta_key );
					}
				}
				foreach ( array_merge( $plan['meta'], $plan['attachment_meta'] ) as $row ) {
					$meta_id  = 0;
					$position = count( $journal['entries'] );
					$result   = ec_link_page_migration_mutate(
						$journal,
						array(
							'type'       => 'meta',
							'post_id'    => $row['post_id'],
							'meta_key'   => $row['meta_key'], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Journal descriptor field.
							'meta_value' => $row['meta_value'], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Journal descriptor.
							'meta_id'    => 0,
						),
						static function () use ( &$journal, $position, $row, &$meta_id, $wpdb ) {
							// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.SlowDBQuery -- Byte-faithful bounded replay.
							$inserted = $wpdb->insert(
								$wpdb->postmeta,
								array(
									'post_id'    => $row['post_id'],
									'meta_key'   => $row['meta_key'],
									'meta_value' => $row['meta_value'],
								),
								array( '%d', '%s', '%s' )
							);
							// phpcs:enable WordPress.DB.DirectDatabaseQuery,WordPress.DB.SlowDBQuery
							$meta_id = $inserted ? (int) $wpdb->insert_id : 0;
							if ( ! $meta_id ) {
								return false;
							}
							$journal['entries'][ $position ]['meta_id'] = $meta_id;
							$stored                                     = ec_link_page_migration_store_entry( $journal['id'], $journal['entries'][ $position ] );
							if ( is_wp_error( $stored ) ) {
								delete_metadata_by_mid( 'post', $meta_id );
								return $stored;
							}
							return $meta_id;
						}
					);
					if ( is_wp_error( $result ) ) {
						return $result; }
				}
				$runtime_meta = ec_link_page_migration_verify_runtime_meta( array_merge( $plan['meta'], $plan['attachment_meta'] ) );
				if ( is_wp_error( $runtime_meta ) ) {
					return $runtime_meta;
				}
				$destination_uploads = wp_upload_dir();
				$source_uploads      = ec_link_page_migration_in_blog(
					$source_blog_id,
					static function () {
						return wp_upload_dir();
					}
				);
				foreach ( $plan['attachments'] as $attachment ) {
					foreach ( $attachment['files'] as $file ) {
						$source      = ec_link_page_migration_realpath( $source_uploads['basedir'], trailingslashit( $source_uploads['basedir'] ) . $file['path'] );
						$destination = trailingslashit( $destination_uploads['basedir'] ) . $file['path'];
						if ( false === $source || ! hash_equals( $file['sha256'], hash_file( 'sha256', $source ) ) ) {
							return new WP_Error( 'link_page_migration_source_file_drift', 'A source file changed or escaped uploads during apply.', $file ); }
						$result = ec_link_page_migration_mutate(
							$journal,
							array(
								'type'          => 'file',
								'path'          => $destination,
								'relative_path' => $file['path'],
								'sha256'        => $file['sha256'],
							),
							static function () use ( $source, $destination, $file, $destination_uploads ) {
								if ( file_exists( $destination ) || ! wp_mkdir_p( dirname( $destination ) ) || false === ec_link_page_migration_realpath( $destination_uploads['basedir'], dirname( $destination ) ) || ! copy( $source, $destination ) || false === ec_link_page_migration_realpath( $destination_uploads['basedir'], $destination ) ) {
									return new WP_Error( 'link_page_migration_file_copy_failed', 'A destination media file could not be copied.' ); }
								return hash_equals( $file['sha256'], hash_file( 'sha256', $destination ) ) ? true : new WP_Error( 'link_page_migration_file_checksum_failed', 'A copied media checksum did not match.' );
							}
						);
						if ( is_wp_error( $result ) ) {
							return $result; }
					}
				}
				return true;
			}
		);
		if ( is_wp_error( $result ) ) {
			$failure = $result;
			throw new RuntimeException( $result->get_error_message() ); }
		$drift_check = ec_plan_link_page_storage_migration( $source_blog_id, $destination_blog_id, false, $journal['caller_required_participant_ids'] );
		if ( is_wp_error( $drift_check ) || ! hash_equals( $journal['fingerprint'], $drift_check['fingerprint'] ) ) {
			throw new RuntimeException( 'The source changed during migration.' );
		}
		$context = ec_link_page_migration_journal_context( $journal );
		foreach ( ec_link_page_migration_required_participants( $journal ) as $participant ) {
			$result = ec_link_page_migration_invoke_participant( $participant, 'apply', $context );
			if ( is_wp_error( $result ) ) {
				$failure = $result;
				throw new RuntimeException( $result->get_error_message() ); }
		}
		$stored = ec_link_page_migration_transition_status( $journal, array( 'applying' ), 'applied' );
		if ( is_wp_error( $stored ) ) {
			return $stored; }
		return array(
			'mode'        => 'apply',
			'journal_id'  => $journal['id'],
			'status'      => 'applied',
			'fingerprint' => $journal['fingerprint'],
			'counts'      => $plan['counts'],
			'rollback'    => 'wp extrachill link-pages migrate-storage --rollback=' . $journal['id'],
		);
	} catch ( Throwable $throwable ) {
		$journal['errors'][] = $throwable->getMessage();
		$stored              = ec_link_page_migration_store_journal( $journal );
		if ( is_wp_error( $stored ) ) {
			return $stored;
		}
		$stored = ec_link_page_migration_transition_status( $journal, array( 'applying' ), 'failed' );
		if ( is_wp_error( $stored ) ) {
			return $stored; }
		$rollback = ec_link_page_migration_compensate( $journal );
		return new WP_Error(
			'link_page_migration_apply_failed',
			$throwable->getMessage(),
			array(
				'journal_id'     => $journal['id'],
				'journal_status' => $journal['status'],
				'rollback'       => is_wp_error( $rollback ) ? $rollback->get_error_data() : 'completed',
				'cause'          => is_wp_error( $failure ) ? array(
					'code' => $failure->get_error_code(),
					'data' => $failure->get_error_data(),
				) : null,
			)
		);
	}
}

/** Validate all exact copied state represented by a journal. */
function ec_validate_link_page_storage_migration_unlocked( $journal_id ) {
	$journal = ec_link_page_migration_get_journal( $journal_id );
	if ( is_wp_error( $journal ) ) {
		return $journal; }
	if ( get_current_blog_id() !== (int) $journal['source_blog_id'] ) {
		return new WP_Error( 'link_page_migration_source_affinity', 'Run validation on the journal source blog.', array( 'source_blog_id' => (int) $journal['source_blog_id'] ) ); }
	if ( 'applied' !== $journal['status'] ) {
		return new WP_Error( 'link_page_migration_not_applied', 'Only an applied journal can be validated.' ); }
	$required = ec_link_page_migration_require_participants( $journal );
	if ( is_wp_error( $required ) ) {
		return $required; }
	$plan = ec_plan_link_page_storage_migration( $journal['source_blog_id'], $journal['destination_blog_id'], false, $journal['caller_required_participant_ids'] ?? array() );
	if ( is_wp_error( $plan ) ) {
		return $plan; }
	if ( ! hash_equals( $journal['fingerprint'], $plan['fingerprint'] ) ) {
		return new WP_Error( 'link_page_migration_source_drift', 'The source changed after apply.' ); }
	$expected_posts = array_merge( $plan['posts'], array_column( $plan['attachments'], 'post' ) );
	$destination    = ec_link_page_migration_in_blog(
		$journal['destination_blog_id'],
		static function () use ( $expected_posts, $plan ) {
			foreach ( $expected_posts as $expected ) {
				$post = get_post( $expected['ID'] );
				if ( ! $post || ec_link_page_migration_post_fields( $post ) !== $expected ) {
					return new WP_Error( 'link_page_migration_validation_failed', 'A destination post differs from its source descriptor.', array( 'post_id' => $expected['ID'] ) );
				}
			}
			$expected_meta = array_merge( $plan['meta'], $plan['attachment_meta'] );
			$actual_meta   = ec_link_page_migration_meta_rows( array_column( $expected_posts, 'ID' ) );
			if ( $expected_meta !== $actual_meta ) {
				return new WP_Error( 'link_page_migration_validation_failed', 'Destination metadata rows differ from source metadata rows.' );
			}
			$uploads = wp_upload_dir();
			foreach ( $plan['attachments'] as $attachment ) {
				foreach ( $attachment['files'] as $file ) {
					$path = trailingslashit( $uploads['basedir'] ) . $file['path'];
					$real = ec_link_page_migration_realpath( $uploads['basedir'], $path );
					if ( is_link( $path ) || false === $real || ! is_file( $real ) || ! hash_equals( $file['sha256'], hash_file( 'sha256', $real ) ) ) {
						return new WP_Error(
							'link_page_migration_validation_failed',
							'A destination attachment file is missing or has changed.',
							array(
								'attachment_id' => $attachment['post']['ID'],
								'path'          => $file['path'],
							)
						);
					}
				}
			}
			return true;
		}
	);
	if ( is_wp_error( $destination ) ) {
		return $destination;
	}
	$context         = ec_link_page_migration_journal_context( $journal );
	$context['mode'] = 'validate';
	foreach ( ec_link_page_migration_required_participants( $journal ) as $participant ) {
		$result = ec_link_page_migration_invoke_participant( $participant, 'validate', $context );
		if ( is_wp_error( $result ) ) {
			return $result; }
	}
	return array(
		'mode'        => 'validate',
		'journal_id'  => $journal_id,
		'status'      => 'valid',
		'fingerprint' => $journal['fingerprint'],
	);
}

/** Roll back only mutations owned by a durable journal. */
function ec_rollback_link_page_storage_migration_unlocked( $journal_id ) {
	$journal = ec_link_page_migration_get_journal( $journal_id );
	if ( is_wp_error( $journal ) ) {
		return $journal; }
	if ( get_current_blog_id() !== (int) $journal['source_blog_id'] ) {
		return new WP_Error( 'link_page_migration_source_affinity', 'Run rollback on the journal source blog.', array( 'source_blog_id' => (int) $journal['source_blog_id'] ) ); }
	if ( 'rolled_back' === $journal['status'] ) {
		return array(
			'mode'       => 'rollback',
			'journal_id' => $journal_id,
			'status'     => 'rolled_back',
			'idempotent' => true,
		); }
	$result = ec_link_page_migration_compensate( $journal );
	return is_wp_error( $result ) ? $result : array(
		'mode'       => 'rollback',
		'journal_id' => $journal_id,
		'status'     => 'rolled_back',
		'idempotent' => false,
	);
}

/** Apply a migration while holding its operation-lifetime advisory lock. */
function ec_apply_link_page_storage_migration( $source_blog_id, $destination_blog_id, $expected_fingerprint, $required_participant_ids = array() ) {
	return ec_link_page_migration_with_operation_lock(
		$source_blog_id,
		$destination_blog_id,
		static function () use ( $source_blog_id, $destination_blog_id, $expected_fingerprint, $required_participant_ids ) {
			return ec_apply_link_page_storage_migration_unlocked( $source_blog_id, $destination_blog_id, $expected_fingerprint, $required_participant_ids );
		}
	);
}

/** Validate a migration while holding its operation-lifetime advisory lock. */
function ec_validate_link_page_storage_migration( $journal_id ) {
	$journal = ec_link_page_migration_get_journal( $journal_id );
	if ( is_wp_error( $journal ) ) {
		return $journal;
	}
	return ec_link_page_migration_with_operation_lock(
		$journal['source_blog_id'],
		$journal['destination_blog_id'],
		static function () use ( $journal_id ) {
			return ec_validate_link_page_storage_migration_unlocked( $journal_id );
		}
	);
}

/** Roll back a migration while holding its operation-lifetime advisory lock. */
function ec_rollback_link_page_storage_migration( $journal_id ) {
	$journal = ec_link_page_migration_get_journal( $journal_id );
	if ( is_wp_error( $journal ) ) {
		return $journal;
	}
	return ec_link_page_migration_with_operation_lock(
		$journal['source_blog_id'],
		$journal['destination_blog_id'],
		static function () use ( $journal_id ) {
			return ec_rollback_link_page_storage_migration_unlocked( $journal_id );
		}
	);
}

/** Ability callback for plan/apply/validate/rollback. */
function ec_migrate_link_page_storage_ability( $input ) {
	$mode = sanitize_key( $input['mode'] ?? 'plan' );
	if ( 'validate' === $mode ) {
		return ec_validate_link_page_storage_migration( $input['journal_id'] ?? '' ); }
	if ( 'rollback' === $mode ) {
		return ec_rollback_link_page_storage_migration( $input['journal_id'] ?? '' ); }
	if ( 'apply' === $mode ) {
		return ec_apply_link_page_storage_migration( $input['source_blog_id'] ?? 0, $input['destination_blog_id'] ?? 0, $input['expected_fingerprint'] ?? '', $input['required_participants'] ?? array() ); }
	return ec_plan_link_page_storage_migration( $input['source_blog_id'] ?? 0, $input['destination_blog_id'] ?? 0, true, $input['required_participants'] ?? array() );
}

/** Register the deliberately non-REST operator ability. */
function ec_register_link_page_storage_migration_ability() {
	wp_register_ability(
		'extrachill/migrate-link-page-storage',
		array(
			'label'               => 'Migrate Link Page storage',
			'description'         => 'Plan, apply, validate, or roll back a Link Page storage-site migration.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'mode'                  => array(
						'type'    => 'string',
						'enum'    => array( 'plan', 'apply', 'validate', 'rollback' ),
						'default' => 'plan',
					),
					'source_blog_id'        => array( 'type' => 'integer' ),
					'destination_blog_id'   => array( 'type' => 'integer' ),
					'expected_fingerprint'  => array( 'type' => 'string' ),
					'journal_id'            => array( 'type' => 'string' ),
					'required_participants' => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
				),
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'additionalProperties' => true,
			),
			'execute_callback'    => 'ec_migrate_link_page_storage_ability',
			'permission_callback' => static function () {
				return is_multisite() && current_user_can( 'manage_network_options' ); },
			'meta'                => array( 'show_in_rest' => false ),
		)
	);
}

add_action( 'wp_abilities_api_init', 'ec_register_link_page_storage_migration_ability' );
