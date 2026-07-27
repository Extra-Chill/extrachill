import { useEffect, useState } from '@wordpress/element';
import {
	ActionRow,
	FieldGroup,
	InlineStatus,
	Panel,
} from '@extrachill/components';
import {
	getLocalSupportAvailability,
	searchEventLocations,
	updateLocalSupportAvailability,
} from '../../shared/api/client';

const useDebounce = ( value, delay ) => {
	const [ debouncedValue, setDebouncedValue ] = useState( value );

	useEffect( () => {
		const handler = setTimeout( () => setDebouncedValue( value ), delay );
		return () => clearTimeout( handler );
	}, [ value, delay ] );

	return debouncedValue;
};

const LocalSupportTab = ( { artistId } ) => {
	const [ available, setAvailable ] = useState( false );
	const [ scene, setScene ] = useState( null );
	const [ search, setSearch ] = useState( '' );
	const [ locations, setLocations ] = useState( [] );
	const [ loading, setLoading ] = useState( false );
	const [ saving, setSaving ] = useState( false );
	const [ status, setStatus ] = useState( '' );
	const [ error, setError ] = useState( '' );
	const debouncedSearch = useDebounce( search, 300 );

	useEffect( () => {
		const load = async () => {
			setLoading( true );
			try {
				const data = await getLocalSupportAvailability( artistId );
				setAvailable( Boolean( data?.available ) );
				setScene( data?.scene || null );
				setSearch( data?.scene?.name || '' );
				setError( '' );
			} catch ( err ) {
				setError(
					err?.message || 'Could not load local support settings.'
				);
			} finally {
				setLoading( false );
			}
		};

		if ( artistId ) {
			load();
		}
	}, [ artistId ] );

	useEffect( () => {
		const run = async () => {
			if (
				debouncedSearch.trim().length < 2 ||
				debouncedSearch === scene?.name
			) {
				setLocations( [] );
				return;
			}
			try {
				const data = await searchEventLocations(
					debouncedSearch.trim()
				);
				setLocations(
					Array.isArray( data?.locations ) ? data.locations : []
				);
			} catch ( err ) {
				setLocations( [] );
			}
		};

		run();
	}, [ debouncedSearch, scene ] );

	const save = async () => {
		setSaving( true );
		setStatus( '' );
		setError( '' );
		try {
			const data = await updateLocalSupportAvailability(
				artistId,
				available,
				scene?.slug || ''
			);
			setAvailable( Boolean( data?.available ) );
			setScene( data?.scene || null );
			setSearch( data?.scene?.name || '' );
			setLocations( [] );
			setStatus( 'Local support settings saved.' );
		} catch ( err ) {
			setError(
				err?.message || 'Could not save local support settings.'
			);
		} finally {
			setSaving( false );
		}
	};

	return (
		<Panel>
			{ loading && (
				<InlineStatus tone="info">
					Loading local support settings…
				</InlineStatus>
			) }
			{ error && <InlineStatus tone="error">{ error }</InlineStatus> }
			{ status && <InlineStatus tone="success">{ status }</InlineStatus> }
			<p>
				Opt in to be considered for event-specific local opening slots
				in your selected Local Scene. Managers may receive matching
				recommendations and notifications.
			</p>
			<label
				className="ec-am__check-row"
				htmlFor="ec-am-local-support-available"
			>
				<input
					id="ec-am-local-support-available"
					type="checkbox"
					checked={ available }
					onChange={ ( event ) =>
						setAvailable( event.target.checked )
					}
				/>
				<span>Available for local support opportunities</span>
			</label>
			{ available && (
				<FieldGroup label="Matching Local Scene">
					<div className="ec-am__location-picker">
						<input
							className="ec-am__location-input"
							type="search"
							value={ search }
							onChange={ ( event ) => {
								setSearch( event.target.value );
								setScene( null );
							} }
							placeholder="Search canonical event locations"
						/>
						{ locations.length > 0 && (
							<div className="ec-am__location-results">
								{ locations.map( ( location ) => (
									<button
										type="button"
										key={ location.slug }
										onClick={ () => {
											setScene( location );
											setSearch( location.name );
											setLocations( [] );
										} }
									>
										{ location.hierarchy?.label ||
											location.name }
									</button>
								) ) }
							</div>
						) }
					</div>
					<p className="ec-am__help">
						Leave the scene unselected to use your account&apos;s
						canonical Local Scene when saving.
					</p>
				</FieldGroup>
			) }
			<p className="ec-am__privacy-note">
				Your contact information is never included in candidate results.
				Events may share contact information only after explicit,
				request-scoped consent.
			</p>
			<ActionRow align="end">
				<button
					type="button"
					className="button-1 button-medium"
					onClick={ save }
					disabled={ loading || saving }
				>
					{ saving ? 'Saving…' : 'Save Local Support Settings' }
				</button>
			</ActionRow>
		</Panel>
	);
};

export default LocalSupportTab;
