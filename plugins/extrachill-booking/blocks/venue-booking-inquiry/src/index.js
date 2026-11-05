/**
 * WordPress dependencies
 */
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import { registerBlockType } from '@wordpress/blocks';
import { PanelBody, TextControl } from '@wordpress/components';

/**
 * Internal dependencies
 */
import metadata from '../block.json';
import './style.scss';

function Edit( { attributes, setAttributes } ) {
	const { venueId, heading, buttonLabel } = attributes;
	return (
		<>
			<InspectorControls>
				<PanelBody title="Venue inquiry settings">
					<TextControl
						label="Canonical venue term ID"
						type="number"
						min="0"
						value={ venueId || '' }
						help="Leave empty on a canonical venue archive."
						onChange={ ( value ) =>
							setAttributes( { venueId: Number( value ) || 0 } )
						}
					/>
					<TextControl
						label="Heading"
						value={ heading }
						onChange={ ( value ) =>
							setAttributes( { heading: value } )
						}
					/>
					<TextControl
						label="Button label"
						value={ buttonLabel }
						onChange={ ( value ) =>
							setAttributes( { buttonLabel: value } )
						}
					/>
				</PanelBody>
			</InspectorControls>
			<div
				{ ...useBlockProps( { className: 'ec-venue-booking-editor' } ) }
			>
				<strong>{ heading || 'Booking inquiries' }</strong>
				<p>
					The public form resolves the canonical venue profile,
					spaces, consent, and intake fields at render time.
				</p>
				<p>
					<small>
						{ venueId
							? `Venue term ${ venueId }`
							: 'Current venue archive' }
					</small>
				</p>
			</div>
		</>
	);
}

registerBlockType( metadata.name, { edit: Edit, save: () => null } );
