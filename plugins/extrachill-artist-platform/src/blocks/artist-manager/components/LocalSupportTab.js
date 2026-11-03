import { useEffect, useState } from '@wordpress/element';
import { ActionRow, InlineStatus, Panel } from '@extrachill/components';
import {
	getLocalSupportAvailability,
	updateLocalSupportAvailability,
} from '../../shared/api/client';

const LocalSupportTab = ( { artistId, onSaved } ) => {
	const [ available, setAvailable ] = useState( false );
	const [ scene, setScene ] = useState( null );
	const [ loading, setLoading ] = useState( false );
	const [ saving, setSaving ] = useState( false );
	const [ status, setStatus ] = useState( '' );
	const [ error, setError ] = useState( '' );

	useEffect( () => {
		let active = true;
		setLoading( true );
		getLocalSupportAvailability( artistId ).then( ( data ) => {
			if ( active ) {
				setAvailable( Boolean( data?.available ) );
				setScene( data?.scene || null );
				setError( '' );
			}
		} ).catch( ( err ) => {
			if ( active ) setError( err?.message || 'Could not load local support settings.' );
		} ).finally( () => {
			if ( active ) setLoading( false );
		} );
		return () => { active = false; };
	}, [ artistId ] );

	const save = async () => {
		setSaving( true );
		setStatus( '' );
		setError( '' );
		try {
			// The shared artist scene wins; the backend defaults to the manager
			// only when the artist has no hometown yet.
			const data = await updateLocalSupportAvailability( artistId, available, '' );
			setAvailable( Boolean( data?.available ) );
			setScene( data?.scene || null );
			setStatus( 'Local support settings saved.' );
			onSaved?.();
		} catch ( err ) {
			setError( err?.message || 'Could not save local support settings.' );
		} finally {
			setSaving( false );
		}
	};

	return (
		<Panel>
			{ loading && <InlineStatus tone="info">Loading local support settings…</InlineStatus> }
			{ error && <InlineStatus tone="error">{ error }</InlineStatus> }
			{ status && <InlineStatus tone="success">{ status }</InlineStatus> }
			<p>Opt in to be considered for event-specific local opening slots in your artist&apos;s Local Scene. Managers may receive matching recommendations and notifications.</p>
			<label className="ec-am__check-row" htmlFor="ec-am-local-support-available">
				<input id="ec-am-local-support-available" type="checkbox" checked={ available } onChange={ ( event ) => setAvailable( event.target.checked ) } />
				<span>Available for local support opportunities</span>
			</label>
			<p>Local Scene: { scene?.hierarchy?.label || scene?.name || 'Your account scene will be used if the artist has none.' } Change the artist&apos;s hometown in the Info tab.</p>
			<p className="ec-am__privacy-note">Your contact information is never included in candidate results. Events may share contact information only after explicit, request-scoped consent.</p>
			<ActionRow align="end">
				<button type="button" className="button-1 button-small" disabled={ loading || saving } onClick={ save }>{ saving ? 'Saving…' : 'Save Local Support Settings' }</button>
			</ActionRow>
		</Panel>
	);
};

export default LocalSupportTab;
