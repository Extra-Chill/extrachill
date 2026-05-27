/**
 * ImportRunProgress — Live progress indicator for a single import run.
 *
 * The progress bar itself stays bespoke: there's no canonical
 * `ProgressBar` primitive in `@extrachill/components` yet. If/when one
 * lands, the inner `.ec-concert-stats__import-run-bar*` markup should
 * be swapped out. Wrapped in `<Section>` for chrome consistency with
 * the rest of the platform. Success/error notes use `<InlineStatus>`.
 *
 * @package ExtraChillEvents
 */

import { InlineStatus, Section } from '@extrachill/components';

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
		<Section className="ec-concert-stats__import-run">
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

			{ /*
			   Bespoke progress bar — no canonical ProgressBar primitive
			   in @extrachill/components yet. Future swap candidate.
			 */ }
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
					<strong>{ run.total_events_created || 0 }</strong> created
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
				<InlineStatus tone="success">
					{ ( () => {
						const matched = run.total_events_matched || 0;
						const created = run.total_events_created || 0;
						const total = matched + created;
						const parts = [];
						parts.push(
							`${ total } show${ total === 1 ? '' : 's' } added to your history`
						);
						if ( created > 0 && matched > 0 ) {
							parts.push(
								` (${ matched } matched existing event${ matched === 1 ? '' : 's' }, ${ created } newly created)`
							);
						} else if ( created > 0 ) {
							parts.push( ` (${ created } newly created)` );
						}
						return parts.join( '' ) + '.';
					} )() }
				</InlineStatus>
			) }

			{ isFailed && run.error_message && (
				<InlineStatus tone="error">
					{ run.error_message }
				</InlineStatus>
			) }
		</Section>
	);
};

export default ImportRunProgress;
