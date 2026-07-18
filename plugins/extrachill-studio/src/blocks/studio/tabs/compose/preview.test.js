import fs from 'node:fs';
import path from 'node:path';
import { openComposePreview } from './preview';

const previewLink =
	'https://extrachill.com/example/?preview_id=42&preview_nonce=nonce&preview=true';

function setup( overrides = {} ) {
	const previewWindow = {
		close: jest.fn(),
		location: { replace: jest.fn() },
	};
	let parentId = 42;
	const dependencies = {
		openWindow: jest.fn( () => previewWindow ),
		cancelPendingSave: jest.fn(),
		waitForPendingSaves: jest.fn( async () => {} ),
		getSnapshot: jest.fn( () => ( {
			title: 'Live title',
			content: '<!-- wp:paragraph -->Body',
		} ) ),
		getParentId: jest.fn( () => parentId ),
		createDraft: jest.fn( async () => 84 ),
		setParentId: jest.fn( ( id ) => {
			parentId = id;
		} ),
		createAutosave: jest.fn( async ( postId ) => ( {
			id: 900,
			parent: postId,
			preview_link: previewLink,
		} ) ),
		...overrides,
	};

	return { dependencies, previewWindow };
}

describe( 'openComposePreview', () => {
	it( 'opens synchronously, waits, then previews the latest existing draft snapshot', async () => {
		let releaseSave;
		const waiting = new Promise( ( resolve ) => {
			releaseSave = resolve;
		} );
		const { dependencies, previewWindow } = setup( {
			waitForPendingSaves: jest.fn( () => waiting ),
		} );

		const operation = openComposePreview( dependencies );
		expect( dependencies.openWindow ).toHaveBeenCalledTimes( 1 );
		expect( dependencies.getSnapshot ).not.toHaveBeenCalled();

		releaseSave();
		await operation;

		expect( dependencies.cancelPendingSave ).toHaveBeenCalledTimes( 1 );
		expect( dependencies.getSnapshot ).toHaveBeenCalledTimes( 1 );
		expect( dependencies.createAutosave ).toHaveBeenCalledWith( 42, {
			title: 'Live title',
			content: '<!-- wp:paragraph -->Body',
		} );
		expect( previewWindow.location.replace ).toHaveBeenCalledWith(
			previewLink
		);
	} );

	it( 'creates one parent draft before requesting its autosave preview', async () => {
		const { dependencies } = setup( {
			getParentId: jest.fn( () => null ),
		} );

		await openComposePreview( dependencies );

		expect( dependencies.createDraft ).toHaveBeenCalledTimes( 1 );
		expect( dependencies.setParentId ).toHaveBeenCalledWith( 84 );
		expect( dependencies.createAutosave ).toHaveBeenCalledWith(
			84,
			expect.any( Object )
		);
	} );

	it( 'reads the live selected parent and immediate title at preview time', async () => {
		const { dependencies } = setup( {
			getParentId: jest.fn( () => 77 ),
			getSnapshot: jest.fn( () => ( {
				title: 'Just typed',
				content: 'Draft B',
			} ) ),
		} );

		await openComposePreview( dependencies );

		expect( dependencies.createAutosave ).toHaveBeenCalledWith( 77, {
			title: 'Just typed',
			content: 'Draft B',
		} );
	} );

	it( 'never treats the autosave revision ID as the parent ID', async () => {
		const { dependencies } = setup();

		await openComposePreview( dependencies );

		expect( dependencies.setParentId ).not.toHaveBeenCalled();
		expect( dependencies.createAutosave ).toHaveBeenCalledWith(
			42,
			expect.any( Object )
		);
	} );

	it( 'reports a blocked popup before starting network work', async () => {
		const { dependencies } = setup( { openWindow: jest.fn( () => null ) } );

		await expect( openComposePreview( dependencies ) ).rejects.toThrow(
			'Allow pop-ups'
		);
		expect( dependencies.waitForPendingSaves ).not.toHaveBeenCalled();
	} );

	it.each( [
		[
			'request failure',
			{
				createAutosave: jest.fn( async () => {
					throw new Error( 'Forbidden' );
				} ),
			},
			'Forbidden',
		],
		[
			'missing link',
			{
				createAutosave: jest.fn( async () => ( {
					id: 900,
					parent: 42,
				} ) ),
			},
			'did not return a preview link',
		],
		[
			'wrong host',
			{
				createAutosave: jest.fn( async () => ( {
					id: 900,
					parent: 42,
					preview_link: 'https://studio.extrachill.com/?preview=true',
				} ) ),
			},
			'wrong site',
		],
	] )(
		'closes the temporary tab on %s',
		async ( label, overrides, message ) => {
			const { dependencies, previewWindow } = setup( overrides );

			await expect( openComposePreview( dependencies ) ).rejects.toThrow(
				message
			);
			expect( previewWindow.close ).toHaveBeenCalledTimes( 1 );
		}
	);
} );

describe( 'Compose autosave proxy contract', () => {
	it( 'relays the complete core autosave response without filtering preview_link', () => {
		const source = fs.readFileSync(
			path.resolve( __dirname, '../../../../../inc/compose/rest.php' ),
			'utf8'
		);
		const start = source.indexOf(
			'function ec_studio_compose_create_autosave'
		);
		const handler = source.slice(
			start,
			source.indexOf( '/**\n * Sanitize the post params', start )
		);

		expect( handler ).toContain(
			"'/wp/v2/posts/' . $post_id . '/autosaves'"
		);
		expect( handler ).toContain(
			'return ec_studio_compose_relay_response( $response );'
		);
		expect( handler ).not.toMatch(
			/preview_link.*unset|array_intersect_key/
		);
	} );
} );
