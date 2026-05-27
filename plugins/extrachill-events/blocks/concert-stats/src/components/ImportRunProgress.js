/**
 * ImportRunProgress — Live progress indicator for a single import run.
 *
 * @package ExtraChillEvents
 */

const STATUS_LABEL = {
	pending: 'Queued',
	running: 'Running',
	paused: 'Paused (rate-limited — resuming soon)',
	complete: 'Complete',
	failed: 'Failed',
};

function formatPercent( run ) {
	if ( ! run.total_pages || run.total_pages < 1 ) {
		return null;
	}
	const current = Math.min( run.total_pages, Math.max( 0, ( run.next_page || 1 ) - 1 ) );
	const pct = Math.round( ( current / run.total_pages ) * 100 );
	return Math.min( 100, Math.max( 0, pct ) );
}

const ImportRunProgress = ( { run } ) => {
	const isActive = [ 'pending', 'running', 'paused' ].includes( run.status );
	const isDone = run.status === 'complete';
	const isFailed = run.status === 'failed';
	const pct = formatPercent( run );

	return (
		<div className="ec-concert-stats__import-run">
			<div className="ec-concert-stats__import-run-header">
				<span className="ec-concert-stats__import-run-status">
					{ STATUS_LABEL[ run.status ] || run.status }
				</span>
				{ pct !== null && isActive && (
					<span className="ec-concert-stats__import-run-pct">
						{ pct }%
					</span>
				) }
			</div>

			{ pct !== null && (
				<div className="ec-concert-stats__import-run-bar">
					<div
						className="ec-concert-stats__import-run-bar-fill"
						style={ { width: pct + '%' } }
					/>
				</div>
			) }

			<div className="ec-concert-stats__import-run-counts">
				<span>
					<strong>{ run.total_events_matched }</strong> matched
				</span>
				<span>
					<strong>{ run.total_events_unmatched }</strong> unmatched
				</span>
				<span>
					<strong>{ run.total_events_seen }</strong> seen
				</span>
			</div>

			{ run.status === 'paused' && run.next_attempt_at && (
				<div className="ec-concert-stats__import-run-note">
					Resumes at { run.next_attempt_at } UTC
				</div>
			) }

			{ isDone && (
				<div className="ec-concert-stats__import-run-note ec-concert-stats__import-run-note--success">
					{ run.total_events_matched } show
					{ run.total_events_matched === 1 ? '' : 's' } added to your history.
					{ run.total_events_unmatched > 0 && (
						<> { run.total_events_unmatched } not found in our database (skipped).</>
					) }
				</div>
			) }

			{ isFailed && run.error_message && (
				<div className="ec-concert-stats__import-run-note ec-concert-stats__import-run-note--error">
					{ run.error_message }
				</div>
			) }
		</div>
	);
};

export default ImportRunProgress;
