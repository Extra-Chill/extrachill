import fs from 'fs';
import path from 'path';

describe( 'portable Link Page editor adapter', () => {
	it( 'uses the owning APIs and registers the shared adapter', () => {
		const source = fs.readFileSync(
			path.resolve(
				__dirname,
				'../../src/blocks/link-page-editor/adapter.js'
			),
			'utf8'
		);
		expect( source ).toContain( 'registerAdapter' );
		expect( source ).toContain( 'updateArtist' );
		expect( source ).toContain( 'updateLinks' );
		expect( source ).toContain( 'updateSocials' );
	} );
} );
