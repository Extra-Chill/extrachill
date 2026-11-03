/**
 * External dependencies
 */
import { FieldGroup, InlineStatus } from '@extrachill/components';

/**
 * WordPress dependencies
 */
import { useEffect, useState } from '@wordpress/element';

/**
 * Internal dependencies
 */
import { searchEventLocations } from '../../shared/api/client';

// Users owns the canonical search and resolution contract used by onboarding.
export default function ScenePicker( { slug, label, onChange } ) {
	const [ search, setSearch ] = useState( label || '' );
	const [ locations, setLocations ] = useState( [] );
	const [ error, setError ] = useState( '' );

	useEffect( () => {
		setSearch( label || '' );
		setLocations( [] );
	}, [ slug, label ] );

	useEffect( () => {
		let active = true;
		setLocations( [] );
		setError( '' );
		if ( search.trim().length < 2 || search === label ) {
			return () => {
				active = false;
			};
		}
		const timer = setTimeout( async () => {
			try {
				const data = await searchEventLocations( search.trim() );
				if ( active ) {
					setLocations( data?.locations || [] );
					if ( ! data?.locations?.length ) {
						setError( 'No matching scenes found.' );
					}
				}
			} catch ( err ) {
				if ( active ) {
					setError( err?.message || 'Could not search Local Scenes.' );
				}
			}
		}, 300 );
		return () => {
			active = false;
			clearTimeout( timer );
		};
	}, [ search, label ] );

	return (
		<FieldGroup label="Local Scene">
			<div className="ec-am__location-picker">
				<input
					aria-label="Local Scene"
					type="search"
					autoComplete="off"
					value={ search }
					placeholder="Search cities and regions"
					onChange={ ( event ) => {
						setSearch( event.target.value );
						onChange( null, null, true );
					} }
				/>
				{ locations.length > 0 && (
					<div className="ec-am__location-results">
						{ locations.map( ( location ) => (
							<button type="button" key={ location.slug } onClick={ () => {
								const nextLabel = location.hierarchy?.label || location.name;
								setSearch( nextLabel );
								setLocations( [] );
								onChange( location.slug, nextLabel, false );
							} }>{ location.hierarchy?.label || location.name }</button>
						) ) }
					</div>
				) }
			</div>
			{ error && <InlineStatus tone="error">{ error }</InlineStatus> }
			<p className="ec-am__help">The artist&apos;s hometown, independent of where members live. Select a search result to change it.</p>
		</FieldGroup>
	);
}
