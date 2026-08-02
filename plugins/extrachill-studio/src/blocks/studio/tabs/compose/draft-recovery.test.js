import fs from 'node:fs';
import path from 'node:path';
import { recoverDraftContent } from './draft-recovery';

const parent = {
	id: 42,
	title: { rendered: 'Parent title', raw: 'Parent title' },
	content: { rendered: 'Parent content', raw: 'Parent content' },
	status: 'draft',
	date: '2026-08-02T18:00:00',
	modified: '2026-08-02T18:00:00',
	modified_gmt: '2026-08-02T18:00:00',
};

describe( 'recoverDraftContent', () => {
	it( 'prefers a newer autosave belonging to the current user', async () => {
		const loadAutosaves = jest.fn( async () => [
			{
				id: 100,
				parent: 42,
				author: 7,
				title: { raw: 'Recovered title', rendered: 'Recovered title' },
				content: {
					raw: 'Recovered content',
					rendered: 'Recovered content',
				},
				modified_gmt: '2026-08-02T18:05:00',
			},
		] );

		await expect(
			recoverDraftContent( parent, 7, loadAutosaves )
		).resolves.toEqual( {
			title: 'Recovered title',
			content: 'Recovered content',
		} );
		expect( loadAutosaves ).toHaveBeenCalledWith( 42 );
	} );

	it.each( [
		[ 'no autosave exists', [] ],
		[
			'only another user has a newer autosave',
			[
				{
					id: 102,
					parent: 42,
					author: 8,
					title: { raw: 'Other title', rendered: 'Other title' },
					content: {
						raw: 'Other content',
						rendered: 'Other content',
					},
					modified_gmt: '2026-08-02T18:10:00',
				},
			],
		],
		[
			'the current-user autosave is not newer',
			[
				{
					id: 101,
					parent: 42,
					author: 7,
					title: { raw: 'Older title', rendered: 'Older title' },
					content: {
						raw: 'Older content',
						rendered: 'Older content',
					},
					modified_gmt: '2026-08-02T17:55:00',
				},
			],
		],
	] )( 'retains the parent when %s', async ( label, autosaves ) => {
		await expect(
			recoverDraftContent( parent, 7, async () => autosaves )
		).resolves.toEqual( {
			title: 'Parent title',
			content: 'Parent content',
		} );
	} );
} );

describe( 'Compose draft hydration contract', () => {
	it( 'uses the shared autosave recovery for initial load and explicit switching', () => {
		const source = fs.readFileSync(
			path.resolve( __dirname, 'index.ts' ),
			'utf8'
		);

		expect( source ).toContain(
			'const initialDraft = result.length > 0\n\t\t\t\t? await loadDraftContent( result[ 0 ] )'
		);
		expect( source ).toContain( '} = await loadDraftContent( post );' );
	} );
} );
