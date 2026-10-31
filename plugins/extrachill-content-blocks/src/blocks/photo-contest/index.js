import './style.scss';
import './editor.scss';

import { registerBlockType } from '@wordpress/blocks';
import {
	TextControl,
	TextareaControl,
	ToggleControl,
	RangeControl,
	Notice,
} from '@wordpress/components';
import { createElement, useEffect, Fragment } from '@wordpress/element';

function createUniqueID( prefix = '' ) {
	return `${ prefix }-${ Date.now() }-${ Math.round(
		Math.random() * 1000000
	) }`;
}

function Edit( { attributes, setAttributes } ) {
	useEffect( () => {
		if ( attributes.contestId === '' ) {
			setAttributes( { contestId: createUniqueID( 'contest' ) } );
		}
	}, [ attributes.contestId, setAttributes ] );

	return createElement(
		'div',
		{
			className: 'extrachill-blocks-photo-contest-editor',
			'data-type': 'extrachill/photo-contest',
		},
		createElement(
			'h3',
			{ className: 'extrachill-blocks-photo-contest-editor-heading' },
			'📸 Photo Contest'
		),
		createElement(
			Notice,
			{ status: 'info', isDismissible: false },
			'Entries, voting, and share links render on the frontend. Entries are user-submitted and stored as attachments on this post — nothing is written into this content.'
		),
		createElement( TextControl, {
			label: 'Contest heading',
			value: attributes.heading,
			onChange: ( heading ) => setAttributes( { heading } ),
		} ),
		createElement(
			Fragment,
			{},
			createElement(
				'h4',
				{},
				'Event CTA banner (drives same-day ticket sales)'
			),
			createElement( TextControl, {
				label: 'Event title',
				value: attributes.eventTitle,
				help: 'Leave empty to hide the banner.',
				onChange: ( eventTitle ) => setAttributes( { eventTitle } ),
			} ),
			createElement( TextControl, {
				label: 'Event date (display text)',
				value: attributes.eventDate,
				onChange: ( eventDate ) => setAttributes( { eventDate } ),
			} ),
			createElement( TextControl, {
				label: 'Venue',
				value: attributes.eventVenue,
				onChange: ( eventVenue ) => setAttributes( { eventVenue } ),
			} ),
			createElement( TextControl, {
				label: 'Ticket URL',
				type: 'url',
				value: attributes.ticketUrl,
				onChange: ( ticketUrl ) => setAttributes( { ticketUrl } ),
			} ),
			createElement( TextControl, {
				label: 'Event page URL',
				type: 'url',
				value: attributes.eventUrl,
				onChange: ( eventUrl ) => setAttributes( { eventUrl } ),
			} )
		),
		createElement(
			Fragment,
			{},
			createElement( 'h4', {}, 'Windows' ),
			createElement( TextControl, {
				label: 'Entries open (site timezone)',
				type: 'datetime-local',
				value: attributes.entriesOpenAt,
				help: 'Leave empty to open entries immediately.',
				onChange: ( entriesOpenAt ) =>
					setAttributes( { entriesOpenAt } ),
			} ),
			createElement( TextControl, {
				label: 'Entries close (site timezone)',
				type: 'datetime-local',
				value: attributes.entriesCloseAt,
				help: 'Leave empty to keep entries open.',
				onChange: ( entriesCloseAt ) =>
					setAttributes( { entriesCloseAt } ),
			} ),
			createElement( TextControl, {
				label: 'Voting opens (site timezone)',
				type: 'datetime-local',
				value: attributes.votingOpensAt,
				help: 'Leave empty to open voting immediately.',
				onChange: ( votingOpensAt ) =>
					setAttributes( { votingOpensAt } ),
			} ),
			createElement( TextControl, {
				label: 'Voting closes (site timezone)',
				type: 'datetime-local',
				value: attributes.votingClosesAt,
				help: 'Leave empty to keep voting open.',
				onChange: ( votingClosesAt ) =>
					setAttributes( { votingClosesAt } ),
			} )
		),
		createElement(
			Fragment,
			{},
			createElement( 'h4', {}, 'Finalists' ),
			createElement( ToggleControl, {
				label: 'Show public finalists list once voting closes',
				checked: !! attributes.showFinalists,
				onChange: ( showFinalists ) =>
					setAttributes( { showFinalists } ),
			} ),
			!! attributes.showFinalists &&
				createElement( RangeControl, {
					label: 'Number of finalists',
					min: 1,
					max: 25,
					value: attributes.finalistCount,
					onChange: ( finalistCount ) =>
						setAttributes( { finalistCount } ),
				} )
		),
		createElement(
			Fragment,
			{},
			createElement( 'h4', {}, 'Welcome email' ),
			createElement(
				'p',
				{},
				'Sent once to each entrant and on a voter\'s first vote. Leave a body empty to skip it. Placeholders: {name}, {costume}, {entry_link}, {contest_link}.'
			),
			createElement( TextControl, {
				label: 'Subject',
				value: attributes.welcomeSubject,
				onChange: ( welcomeSubject ) =>
					setAttributes( { welcomeSubject } ),
			} ),
			createElement( TextareaControl, {
				label: 'Entrant email',
				value: attributes.welcomeEntrantBody,
				onChange: ( welcomeEntrantBody ) =>
					setAttributes( { welcomeEntrantBody } ),
			} ),
			createElement( TextareaControl, {
				label: 'Voter email',
				value: attributes.welcomeVoterBody,
				onChange: ( welcomeVoterBody ) =>
					setAttributes( { welcomeVoterBody } ),
			} )
		)
	);
}

registerBlockType( 'extrachill/photo-contest', {
	edit: Edit,
	save: () => null,
} );
