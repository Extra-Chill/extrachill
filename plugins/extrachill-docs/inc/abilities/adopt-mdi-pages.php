<?php
/** One-shot, metadata-only MDI adoption. Dry-run unless apply is explicit. */
defined( 'ABSPATH' ) || exit;

add_action( 'wp_abilities_api_init', 'extrachill_docs_register_adopt_mdi_pages' );
function extrachill_docs_register_adopt_mdi_pages(): void {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}
	wp_register_ability( 'extrachill-docs/adopt-mdi-pages', array(
		'label'               => 'Adopt existing documentation into MDI',
		'description'         => 'Copy source identities only. Dry-run by default; apply explicitly.',
		'category'            => 'extrachill-docs',
		'input_schema'        => array(
			'type'       => 'object',
			'properties' => array(
				'apply' => array(
					'type'    => 'boolean',
					'default' => false,
				),
			),
		),
		'output_schema'       => array( 'type' => 'object' ),
		'permission_callback' => 'extrachill_docs_upsert_doc_page_permission_callback',
		'execute_callback'    => 'extrachill_docs_execute_adopt_mdi_pages',
	) );
}

function extrachill_docs_execute_adopt_mdi_pages( array $input ): array {
	$pages  = get_posts( array(
		'post_type'      => 'page',
		'post_status'    => array( 'publish', 'private', 'draft', 'pending' ),
		'posts_per_page' => -1,
	) );
	$owners = array();
	foreach ( $pages as $page ) {
		foreach ( array( '_source_path', '_markdown_source_path', '_markdown_source_identity' ) as $key ) {
			$path = (string) get_post_meta( $page->ID, $key, true );
			if ( '' !== $path ) {
				$owners[ $path ][ $page->ID ] = true;
			}
		}
	}
	$rows = array();
	$ok   = true;
	foreach ( $pages as $page ) {
		$path = (string) get_post_meta( $page->ID, '_source_path', true );
		if ( ! extrachill_docs_mdi_source( $path ) ) {
			continue;
		}
		$changes  = array();
		$conflict = count( $owners[ $path ] ) > 1;
		foreach ( array( '_markdown_source_path', '_markdown_source_identity' ) as $key ) {
			$old      = (string) get_post_meta( $page->ID, $key, true );
			$conflict = $conflict || ( '' !== $old && $old !== $path );
			if ( $old !== $path ) {
				$changes[ $key ] = $path;
			}
		}
		if ( $conflict ) {
			$ok = false;
		} elseif ( ! empty( $input['apply'] ) ) {
			foreach ( $changes as $key => $value ) {
				update_post_meta( $page->ID, $key, $value );
			}
		}
		$rows[] = array(
			'page_id' => $page->ID,
			'path'    => $path,
			'action'  => $conflict ? 'conflict' : ( $changes ? 'adopt' : 'unchanged' ),
		);
	}
	return array(
		'success' => $ok,
		'dry_run' => empty( $input['apply'] ),
		'rows'    => $rows,
	);
}
