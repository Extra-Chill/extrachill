/**
 * Audit Context
 *
 * State management for SEO audit dashboard.
 */
import { createContext, useContext, useState, useCallback, useRef } from '@wordpress/element';
import { runAudit, continueAudit, getAuditDetails, exportAuditDetails } from '../api/client';

const AuditContext = createContext();

export const useAudit = () => {
	const context = useContext( AuditContext );
	if ( ! context ) {
		throw new Error( 'useAudit must be used within AuditProvider' );
	}
	return context;
};

export const AuditProvider = ( { children, initialData } ) => {
	const [ status, setStatus ] = useState( initialData?.status || 'none' );
	const [ results, setResults ] = useState( initialData?.results || {} );
	const [ timestamp, setTimestamp ] = useState( initialData?.timestamp || 0 );
	const [ isLoading, setIsLoading ] = useState( false );
	const [ progress, setProgress ] = useState( null );
	const [ error, setError ] = useState( null );

	// Details state
	const [ detailsCategory, setDetailsCategory ] = useState( null );
	const [ detailsItems, setDetailsItems ] = useState( [] );
	const [ detailsPage, setDetailsPage ] = useState( 1 );
	const [ detailsTotal, setDetailsTotal ] = useState( 0 );
	const [ detailsTotalPages, setDetailsTotalPages ] = useState( 0 );
	const [ detailsLoading, setDetailsLoading ] = useState( false );

	const pollingRef = useRef( false );

	const updateFromResponse = useCallback( ( response ) => {
		setStatus( response.status );
		setResults( response.results || {} );
		if ( response.timestamp ) {
			setTimestamp( response.timestamp );
		}
		if ( response.progress ) {
			setProgress( response.progress );
		}
	}, [] );

	const pollForCompletion = useCallback( async () => {
		if ( pollingRef.current ) return;
		pollingRef.current = true;

		try {
			const response = await continueAudit();
			updateFromResponse( response );

			if ( response.status === 'in_progress' ) {
				pollingRef.current = false;
				setTimeout( () => pollForCompletion(), 100 );
			} else {
				setIsLoading( false );
				setProgress( null );
				pollingRef.current = false;
			}
		} catch ( err ) {
			setError( 'Audit failed. Please try again.' );
			setIsLoading( false );
			setProgress( null );
			pollingRef.current = false;
		}
	}, [ updateFromResponse ] );

	const startFullAudit = useCallback( async () => {
		setIsLoading( true );
		setError( null );
		setProgress( null );

		try {
			const response = await runAudit( 'full' );
			updateFromResponse( response );
		} catch ( err ) {
			setError( 'Audit failed. Please try again.' );
		} finally {
			setIsLoading( false );
		}
	}, [ updateFromResponse ] );

	const startBatchAudit = useCallback( async () => {
		setIsLoading( true );
		setError( null );

		try {
			const response = await runAudit( 'batch' );
			updateFromResponse( response );

			if ( response.status === 'in_progress' ) {
				pollForCompletion();
			} else {
				setIsLoading( false );
			}
		} catch ( err ) {
			setError( 'Audit failed. Please try again.' );
			setIsLoading( false );
		}
	}, [ updateFromResponse, pollForCompletion ] );

	const continueExistingAudit = useCallback( async () => {
		setIsLoading( true );
		setError( null );
		pollForCompletion();
	}, [ pollForCompletion ] );

	const loadDetails = useCallback( async ( category, page = 1 ) => {
		setDetailsCategory( category );
		setDetailsPage( page );
		setDetailsLoading( true );

		try {
			const response = await getAuditDetails( category, page );
			setDetailsItems( response.items || [] );
			setDetailsTotal( response.total || 0 );
			setDetailsTotalPages( response.total_pages || 0 );
		} catch ( err ) {
			setDetailsItems( [] );
			setError( 'Failed to load details.' );
		} finally {
			setDetailsLoading( false );
		}
	}, [] );

	const closeDetails = useCallback( () => {
		setDetailsCategory( null );
		setDetailsItems( [] );
		setDetailsPage( 1 );
		setDetailsTotal( 0 );
		setDetailsTotalPages( 0 );
	}, [] );

	const handleExport = useCallback( async () => {
		if ( ! detailsCategory ) return;

		try {
			const data = await exportAuditDetails( detailsCategory );
			const blob = new Blob( [ JSON.stringify( data, null, 2 ) ], { type: 'application/json' } );
			const url = URL.createObjectURL( blob );
			const date = new Date().toISOString().split( 'T' )[ 0 ];
			const filename = `seo-audit-${ detailsCategory }-${ date }.json`;

			const a = document.createElement( 'a' );
			a.href = url;
			a.download = filename;
			document.body.appendChild( a );
			a.click();
			document.body.removeChild( a );
			URL.revokeObjectURL( url );
		} catch ( err ) {
			setError( 'Export failed. Please try again.' );
		}
	}, [ detailsCategory ] );

	const value = {
		// Audit state
		status,
		results,
		timestamp,
		isLoading,
		progress,
		error,

		// Audit actions
		startFullAudit,
		startBatchAudit,
		continueExistingAudit,

		// Details state
		detailsCategory,
		detailsItems,
		detailsPage,
		detailsTotal,
		detailsTotalPages,
		detailsLoading,

		// Details actions
		loadDetails,
		closeDetails,
		handleExport,
	};

	return (
		<AuditContext.Provider value={ value }>
			{ children }
		</AuditContext.Provider>
	);
};

export default AuditContext;
