/* global describe, expect, it */

/**
 * External dependencies
 */
import fs from 'node:fs';
import path from 'node:path';

const source = fs.readFileSync(
	path.resolve( __dirname, '../../../../../inc/compose/rest.php' ),
	'utf8'
);
const updateStart = source.indexOf( 'function ec_studio_compose_update_post' );
const updateHandler = source.slice(
	updateStart,
	source.indexOf( '/**\n * Prevent stale Compose tabs', updateStart )
);
const guardStart = source.indexOf( 'function ec_studio_compose_guard_reviewable_post' );
const guard = source.slice(
	guardStart,
	source.indexOf( '/**\n * Emit a Studio compose lifecycle', guardStart )
);

describe( 'Compose published-parent status guard', () => {
	it( 'checks the parent before forwarding an update to main', () => {
		expect( updateHandler ).toContain(
			'ec_studio_compose_guard_reviewable_post( $post_id )'
		);
		expect( updateHandler.indexOf( 'ec_studio_compose_guard_reviewable_post' ) )
			.toBeLessThan( updateHandler.indexOf( 'ec_cross_site_rest_request' ) );
	} );

	it( 'allows only draft and pending parents', () => {
		expect( guard ).toContain(
			"in_array( $post->post_status, array( 'draft', 'pending' ), true )"
		);
		expect( guard ).toContain( 'ec_studio_compose_post_state_conflict' );
		expect( guard ).toContain( "'status'         => 409" );
		expect( guard ).toContain( "'current_status' => $post->post_status" );
	} );
} );
