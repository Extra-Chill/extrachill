/**
 * External dependencies
 */
import { InlineStatus, Panel } from '@extrachill/components';

export const Status = ( { state, onRetry } ) => {
	if ( ! state ) {
		return null;
	}
	return (
		<div
			role={ state.tone === 'error' ? 'alert' : 'status' }
			aria-live="polite"
		>
			<InlineStatus tone={ state.tone }>
				{ state.message }
				{ onRetry && (
					<button
						type="button"
						className="button-link"
						onClick={ onRetry }
					>
						Retry
					</button>
				) }
			</InlineStatus>
		</div>
	);
};

export const LoadingPanel = ( { label = 'Loading venue data...' } ) => (
	<Panel>
		<p aria-live="polite">{ label }</p>
	</Panel>
);
