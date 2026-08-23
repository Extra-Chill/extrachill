/**
 * External dependencies
 */
import fs from 'node:fs';
import path from 'node:path';

const source = fs.readFileSync(
	path.resolve( __dirname, '../../../../../inc/compose/rest.php' ),
	'utf8'
);
const routeStart = source.indexOf(
	"'/' . EC_STUDIO_COMPOSE_ROUTE . '/posts/(?P<id>\\d+)'"
);
const route = source.slice(
	routeStart,
	source.indexOf( 'register_rest_route(', routeStart + 1 )
);
const handlerStart = source.indexOf( 'function ec_studio_compose_get_post' );
const handler = source.slice(
	handlerStart,
	source.indexOf( '/**\n * Update an existing post', handlerStart )
);
const permissionStart = source.indexOf(
	'function ec_studio_compose_permission_check'
);
const permissionHandler = source.slice(
	permissionStart,
	source.indexOf( '/**\n * Guard that extrachill-multisite', permissionStart )
);

describe( 'Compose item GET proxy contract', () => {
	it( 'registers GET and POST separately behind the existing team gate', () => {
		expect( route ).toContain( "'methods'             => 'GET'" );
		expect( route ).toContain(
			"'callback'            => 'ec_studio_compose_get_post'"
		);
		expect( route ).toContain( "'methods'             => 'POST'" );
		expect(
			route.match( /ec_studio_compose_permission_check/g )
		).toHaveLength( 2 );
	} );

	it( 'fails closed when the requester is not an authorized team member', () => {
		expect( permissionHandler ).toContain( '! is_user_logged_in()' );
		expect( permissionHandler ).toContain(
			"! function_exists( 'ec_is_team_member' ) || ! ec_is_team_member()"
		);
		expect( permissionHandler ).toContain( "array( 'status' => 403 )" );
	} );

	it( 'preserves context=edit and other core item query parameters', () => {
		expect( handler ).toContain(
			'$query   = $request->get_query_params();'
		);
		expect( handler ).toContain(
			"array( 'context', 'password', 'excerpt_length', '_fields', '_embed' )"
		);
		expect( handler ).toContain(
			'$sub_request->set_query_params( $query );'
		);
	} );

	it( 'dispatches to the native main-site posts controller', () => {
		expect( handler ).toContain( "ec_get_blog_id( 'main' )" );
		expect( handler ).toContain(
			"new \\WP_REST_Request( 'GET', '/wp/v2/posts/' . $post_id )"
		);
		expect( handler ).toContain( 'rest_do_request( $sub_request )' );
	} );

	it( 'relays native missing-post and access errors as WP_Error', () => {
		expect( handler ).toContain( '$response->is_error()' );
		expect( handler ).toContain( '$response->as_error()' );
	} );

	it( 'preserves the successful core response status and headers', () => {
		expect( handler ).toContain(
			'ec_studio_compose_relay_response( $response )'
		);
	} );
} );

describe( 'Compose external-edit refresh route', () => {
	it( 'fetches the active post with edit context through the rewritten item path', () => {
		const composeSource = fs.readFileSync(
			path.resolve( __dirname, 'index.ts' ),
			'utf8'
		);
		const middlewareSource = fs.readFileSync(
			path.resolve( __dirname, 'cross-site-middleware.ts' ),
			'utf8'
		);

		expect( composeSource ).toContain(
			'path: `/wp/v2/posts/${ postId }?context=edit`'
		);
		expect( middlewareSource ).toContain(
			'return `${ PROXY_PREFIX }/posts/${ postMatch[ 1 ] }${ query }`;'
		);
	} );
} );
