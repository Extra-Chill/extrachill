/* eslint-disable no-undef -- Homeboy's file-scoped ESLint runner does not load the Jest environment. */

/**
 * WordPress dependencies
 */
import { createElement, createRoot } from '@wordpress/element';

/**
 * External dependencies
 */
import { act } from 'react';

window.IS_REACT_ACT_ENVIRONMENT = true;

const mockGetAllComments = jest.fn();
const mockReplyToComment = jest.fn();

jest.mock( '../../../app/client', () => ( {
	studioSocialsApi: {
		getAllComments: ( ...args ) => mockGetAllComments( ...args ),
		replyToComment: ( ...args ) => mockReplyToComment( ...args ),
	},
} ) );

jest.mock( '@extrachill/components', () => {
	const { createElement: element } = require( '@wordpress/element' );
	const Container = ( { children, compact, ...props } ) =>
		element( 'div', props, children );

	return {
		ActionRow: Container,
		FieldGroup: Container,
		InlineStatus: ( { children, tone, ...props } ) =>
			element( 'div', props, children ),
		Panel: Container,
		PanelHeader: Container,
	};
} );

/**
 * Internal dependencies
 */
import CommentsView from './index';

const comment = {
	id: 'comment-1',
	platform: 'instagram',
	author_username: 'listener',
	text: 'Great post',
	timestamp: '2026-08-23T00:00:00Z',
	like_count: 0,
	reply_count: 0,
	mentions: [],
	parent_id: null,
	raw: {},
};

let root;
let container;

const flush = async () => {
	await act( async () => {
		await Promise.resolve();
	} );
};

const enterReply = ( message ) => {
	const textarea = container.querySelector( 'textarea' );
	const valueSetter = Object.getOwnPropertyDescriptor(
		HTMLTextAreaElement.prototype,
		'value'
	).set;

	act( () => {
		valueSetter.call( textarea, message );
		textarea.dispatchEvent( new Event( 'input', { bubbles: true } ) );
	} );
};

const submitReply = () => {
	const replyButton = [ ...container.querySelectorAll( 'button' ) ].find(
		( button ) => button.textContent === 'Reply'
	);
	act( () => replyButton.click() );
};

beforeEach( async () => {
	mockGetAllComments.mockResolvedValue( { data: { comments: [ comment ] } } );
	mockReplyToComment.mockResolvedValue( { success: true } );
	container = document.createElement( 'div' );
	document.body.appendChild( container );
	root = createRoot( container );

	await act( async () => {
		root.render(
			createElement( CommentsView, {
				slug: 'instagram',
				label: 'Instagram',
			} )
		);
	} );
} );

afterEach( () => {
	act( () => root.unmount() );
	container.remove();
	mockGetAllComments.mockReset();
	mockReplyToComment.mockReset();
} );

describe( 'CommentsView reply feedback', () => {
	it( 'preserves one success announcement after the comment refresh completes', async () => {
		enterReply( 'Thanks!' );
		submitReply();
		await flush();

		expect( mockReplyToComment ).toHaveBeenCalledWith(
			'instagram',
			'comment-1',
			'Thanks!'
		);
		expect( mockGetAllComments ).toHaveBeenCalledTimes( 2 );
		expect( container.textContent ).toContain( 'Loaded 1 comments.' );
		expect( container.textContent ).toContain(
			'Reply posted successfully.'
		);
		expect(
			[ ...container.querySelectorAll( '[role="status"]' ) ].filter(
				( status ) =>
					status.textContent === 'Reply posted successfully.'
			)
		).toHaveLength( 1 );
	} );

	it( 'keeps a failed reply actionable without clearing the draft', async () => {
		mockReplyToComment.mockRejectedValue(
			new Error( 'Account authorization expired.' )
		);
		enterReply( 'Please try this reply' );
		submitReply();
		await flush();

		expect( container.querySelector( '[role="alert"]' ).textContent ).toBe(
			'Account authorization expired.'
		);
		expect( container.querySelector( 'textarea' ).value ).toBe(
			'Please try this reply'
		);
		expect( mockGetAllComments ).toHaveBeenCalledTimes( 1 );
	} );
} );
