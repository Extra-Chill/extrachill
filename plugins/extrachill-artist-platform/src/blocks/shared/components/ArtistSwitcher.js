/**
 * Shared ArtistSwitcher Component
 *
 * Dropdown for switching between user's artists across all artist platform blocks.
 */

import { useCallback } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

export default function ArtistSwitcher( {
	artists,
	selectedId,
	onChange,
	showCreateOption = false,
	showLabel = false,
	hideIfSingle = true,
	emptyStateMessage = null,
} ) {
	const handleChange = useCallback(
		( e ) => {
			const newId =
				e.target.value === '' ? 0 : parseInt( e.target.value, 10 );
			if ( newId !== selectedId ) {
				onChange( newId );
			}
		},
		[ selectedId, onChange ]
	);

	// Empty state with message
	if ( ! artists.length && ! showCreateOption ) {
		return emptyStateMessage ? (
			<div className="notice notice-info">
				<p>{ emptyStateMessage }</p>
			</div>
		) : null;
	}

	// Hide if single artist and hideIfSingle is true
	if ( hideIfSingle && artists.length <= 1 && ! showCreateOption ) {
		return null;
	}

	return (
		<div className="ec-artist-switcher">
			{ showLabel && (
				<label htmlFor="ec-artist-switcher-select">
					{ __( 'Your Artists', 'extrachill-artist-platform' ) }
				</label>
			) }
			<select
				id={ showLabel ? 'ec-artist-switcher-select' : undefined }
				value={ selectedId || '' }
				onChange={ handleChange }
				aria-label={ __( 'Select artist', 'extrachill-artist-platform' ) }
			>
				{ showCreateOption && (
					<option value="">
						{ __( 'Create new artist', 'extrachill-artist-platform' ) }
					</option>
				) }
				{ artists.map( ( artist ) => (
					<option key={ artist.id } value={ artist.id }>
						{ artist.name }
					</option>
				) ) }
			</select>
		</div>
	);
}
