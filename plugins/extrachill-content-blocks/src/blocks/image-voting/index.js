import './style.scss';
import './editor.scss';

import { MediaUpload } from '@wordpress/block-editor';
import { registerBlockType } from '@wordpress/blocks';
import { Button, TextControl } from '@wordpress/components';
import { createElement, useEffect } from '@wordpress/element';

function createUniqueID( prefix = '' ) {
	return `${ prefix }-${ Date.now() }-${ Math.round(
		Math.random() * 1000000
	) }`;
}

function Edit( { attributes, setAttributes } ) {
	useEffect( () => {
		if ( attributes.uniqueBlockId === '' ) {
			const uniqueBlockId = createUniqueID( 'block-' );
			setAttributes( { uniqueBlockId } );
		}
	}, [ attributes.uniqueBlockId, setAttributes ] );

	const onSelectImage = ( media ) => {
		setAttributes( { mediaID: media.id, mediaURL: media.url } );
	};

	return createElement(
		'div',
		{
			className: 'extrachill-blocks-image-voting-editor',
			'data-type': 'extrachill/image-voting',
		},
		attributes.mediaURL
			? createElement(
					'div',
					{ className: 'extrachill-blocks-image-wrapper-editor' },
					createElement( 'img', {
						src: attributes.mediaURL,
						alt: 'Selected image for voting',
					} ),
					createElement(
						'div',
						{
							className:
								'extrachill-blocks-overlay-badges-editor',
						},
						createElement(
							'span',
							{
								className:
									'extrachill-blocks-vote-badge-editor',
							},
							`Votes: ${ attributes.voteCount }`
						),
						createElement(
							'h2',
							{
								className:
									'extrachill-blocks-title-badge-editor',
							},
							attributes.blockTitle
						)
					)
			  )
			: null,
		createElement(
			'div',
			{ className: 'extrachill-blocks-image-voting-editor-controls' },
			createElement( TextControl, {
				label: 'Block Title',
				value: attributes.blockTitle,
				onChange: ( newTitle ) =>
					setAttributes( { blockTitle: newTitle } ),
			} ),
			createElement(
				'p',
				{},
				createElement( MediaUpload, {
					onSelect: onSelectImage,
					type: 'image',
					value: attributes.mediaID,
					render: ( { open } ) =>
						createElement(
							Button,
							{
								isPrimary: true,
								onClick: open,
							},
							attributes.mediaURL
								? 'Change Image'
								: 'Select Image'
						),
				} )
			),
			createElement( 'p', {}, `Vote Count: ${ attributes.voteCount }` )
		)
	);
}

registerBlockType( 'extrachill/image-voting', {
	edit: Edit,
	save: () => null,
} );
