/**
 * EventDetail Component
 *
 * Displays parsed event data based on event type.
 */

import { Button } from '@wordpress/components';

export default function EventDetail( { event, onClose } ) {
	if ( ! event ) return null;

	const { event_type, event_data, source_url, blog_id, user_id, created_at } =
		event;

	/**
	 * Render parsed event data based on event type.
	 */
	const renderEventData = () => {
		if ( ! event_data ) {
			return (
				<p className="ec-event-detail__no-data">No additional data.</p>
			);
		}

		switch ( event_type ) {
			case 'share_click':
				return (
					<dl className="ec-event-detail__parsed">
						<dt>Platform</dt>
						<dd>{ event_data.destination || '-' }</dd>
						<dt>Shared URL</dt>
						<dd>
							{ event_data.share_url ? (
								<a
									href={ event_data.share_url }
									target="_blank"
									rel="noopener noreferrer"
								>
									{ event_data.share_url }
								</a>
							) : (
								'-'
							) }
						</dd>
					</dl>
				);

			case 'newsletter_signup':
				return (
					<dl className="ec-event-detail__parsed">
						<dt>Context</dt>
						<dd>{ event_data.context || '-' }</dd>
						<dt>List ID</dt>
						<dd>{ event_data.list_id || '-' }</dd>
					</dl>
				);

			case 'user_registration':
				return (
					<dl className="ec-event-detail__parsed">
						<dt>Registered User ID</dt>
						<dd>{ event_data.user_id || '-' }</dd>
						<dt>Source</dt>
						<dd>{ event_data.source || '-' }</dd>
						<dt>Method</dt>
						<dd>{ event_data.method || '-' }</dd>
					</dl>
				);

			case 'search':
				return (
					<dl className="ec-event-detail__parsed">
						<dt>Search Term</dt>
						<dd>{ event_data.search_term || '-' }</dd>
						<dt>Result Count</dt>
						<dd>
							{ event_data.result_count !== undefined
								? event_data.result_count
								: '-' }
						</dd>
					</dl>
				);

			default:
				// For unknown event types, show raw JSON
				return (
					<pre className="ec-event-detail__raw">
						{ JSON.stringify( event_data, null, 2 ) }
					</pre>
				);
		}
	};

	const formatDate = ( dateString ) => {
		const date = new Date( dateString );
		return date.toLocaleString();
	};

	return (
		<div className="ec-event-detail">
			<div className="ec-event-detail__header">
				<h3>Event Details (ID: { event.id })</h3>
				<Button variant="tertiary" onClick={ onClose }>
					Close
				</Button>
			</div>

			<div className="ec-event-detail__content">
				<div className="ec-event-detail__meta">
					<dl>
						<dt>Event Type</dt>
						<dd>
							<span className="ec-badge ec-badge--info">
								{ event_type.replace( /_/g, ' ' ) }
							</span>
						</dd>
						<dt>Source URL</dt>
						<dd>
							{ source_url ? (
								<a
									href={ source_url }
									target="_blank"
									rel="noopener noreferrer"
								>
									{ source_url }
								</a>
							) : (
								'-'
							) }
						</dd>
						<dt>Blog ID</dt>
						<dd>{ blog_id }</dd>
						<dt>User ID</dt>
						<dd>{ user_id || 'Anonymous' }</dd>
						<dt>Timestamp</dt>
						<dd>{ formatDate( created_at ) }</dd>
					</dl>
				</div>

				<div className="ec-event-detail__data">
					<h4>Event Data</h4>
					{ renderEventData() }
				</div>
			</div>
		</div>
	);
}
