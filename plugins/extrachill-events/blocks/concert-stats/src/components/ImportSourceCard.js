/**
 * ImportSourceCard — Per-source connect card + recent runs.
 *
 * Flow:
 *  1. Username field + "Preview" button.
 *  2. Preview returns total event count → confirmation dialog.
 *  3. User confirms → start import → progress polled by parent.
 *
 * @package ExtraChillEvents
 */

import { useState, useMemo } from '@wordpress/element';
import {
	ActionRow,
	FieldGroup,
	InlineStatus,
	Panel,
	PanelHeader,
} from '@extrachill/components';
import ImportRunProgress from './ImportRunProgress';

const ACTIVE_STATUSES = [ 'pending', 'running', 'paused' ];

const ImportSourceCard = ( { source, runs, onPreview, onStart } ) => {
	const [ username, setUsername ] = useState( source.username || '' );
	const [ previewState, setPreviewState ] = useState( null ); // { total, username }
	const [ previewing, setPreviewing ] = useState( false );
	const [ starting, setStarting ] = useState( false );
	const [ error, setError ] = useState( null );

	const sourceRuns = useMemo(
		() => runs.filter( ( r ) => r.source_slug === source.slug ),
		[ runs, source.slug ]
	);
	const activeRun = sourceRuns.find( ( r ) => ACTIVE_STATUSES.includes( r.status ) ) || null;
	const recentRuns = sourceRuns.filter( ( r ) => ! ACTIVE_STATUSES.includes( r.status ) ).slice( 0, 3 );

	const handlePreview = ( e ) => {
		e.preventDefault();
		setError( null );
		setPreviewState( null );
		setPreviewing( true );

		onPreview( source.slug, username )
			.then( ( res ) => {
				setPreviewState( res );
			} )
			.catch( ( err ) => {
				setError( err.message || 'Failed to verify username.' );
			} )
			.finally( () => setPreviewing( false ) );
	};

	const handleStart = () => {
		setError( null );
		setStarting( true );
		onStart( source.slug, username )
			.then( () => {
				setPreviewState( null );
			} )
			.catch( ( err ) => {
				setError( err.message || 'Failed to start import.' );
			} )
			.finally( () => setStarting( false ) );
	};

	const handleCancelPreview = () => {
		setPreviewState( null );
	};

	const rateLimitMeta =
		source.rate_limit && source.rate_limit.requests_per_day > 0
			? `${ source.rate_limit.requests_per_day } reqs/day`
			: null;

	return (
		<Panel className="ec-concert-stats__import-card">
			<PanelHeader
				title={ source.label }
				description={ rateLimitMeta }
			/>

			{ ! source.configured && (
				<InlineStatus tone="warning">
					Not yet available — { source.label } API key has not been configured on this platform.
				</InlineStatus>
			) }

			{ source.configured && activeRun && (
				<ImportRunProgress run={ activeRun } />
			) }

			{ source.configured && ! activeRun && ! previewState && (
				<form className="ec-concert-stats__import-card-form" onSubmit={ handlePreview }>
					<FieldGroup label={ `Your ${ source.label } username` }>
						<input
							type="text"
							className="ec-concert-stats__import-card-input"
							value={ username }
							onChange={ ( e ) => setUsername( e.target.value ) }
							placeholder="username"
							disabled={ previewing }
							required
						/>
					</FieldGroup>
					<ActionRow align="end">
						<button
							type="submit"
							className="ec-concert-stats__import-card-btn"
							disabled={ previewing || ! username.trim() }
						>
							{ previewing ? 'Checking…' : 'Connect' }
						</button>
					</ActionRow>
				</form>
			) }

			{ source.configured && previewState && (
				<div className="ec-concert-stats__import-card-confirm">
					<p>
						Import <strong>~{ previewState.total }</strong> show
						{ previewState.total === 1 ? '' : 's' } from { source.label } user{' '}
						<strong>{ previewState.username }</strong>?
					</p>
					<p className="ec-concert-stats__import-card-hint">
						We&rsquo;ll match each show to events in our database. Shows we can&rsquo;t find will be skipped.
						Large imports may take several days to complete due to { source.label } rate limits — we&rsquo;ll resume automatically.
					</p>
					<ActionRow align="end">
						<button
							type="button"
							className="ec-concert-stats__import-card-btn ec-concert-stats__import-card-btn--primary"
							onClick={ handleStart }
							disabled={ starting }
						>
							{ starting ? 'Starting…' : 'Yes, import' }
						</button>
						<button
							type="button"
							className="ec-concert-stats__import-card-btn-link"
							onClick={ handleCancelPreview }
							disabled={ starting }
						>
							Cancel
						</button>
					</ActionRow>
				</div>
			) }

			{ error && (
				<InlineStatus tone="error">{ error }</InlineStatus>
			) }

			{ recentRuns.length > 0 && (
				<div className="ec-concert-stats__import-card-history">
					<h4 className="ec-concert-stats__import-card-history-title">Recent imports</h4>
					{ recentRuns.map( ( run ) => (
						<ImportRunProgress key={ run.id } run={ run } />
					) ) }
				</div>
			) }
		</Panel>
	);
};

export default ImportSourceCard;
