/**
 * Audit Actions Component
 *
 * Buttons to run/continue audits.
 */
import { ActionRow, InlineStatus } from '@extrachill/components';
import { useAudit } from '../context/AuditContext';

const AuditActions = () => {
	const {
		status,
		isLoading,
		error,
		startFullAudit,
		startBatchAudit,
		continueExistingAudit,
	} = useAudit();

	const showContinue = status === 'in_progress';

	return (
		<ActionRow className="extrachill-seo-audit-actions">
			<button
				type="button"
				className="button button-primary"
				onClick={ startFullAudit }
				disabled={ isLoading }
			>
				Run Full Audit
			</button>

			<button
				type="button"
				className="button"
				onClick={ startBatchAudit }
				disabled={ isLoading }
			>
				Run Batch Audit
			</button>

			{ showContinue && (
				<button
					type="button"
					className="button"
					onClick={ continueExistingAudit }
					disabled={ isLoading }
				>
					Continue Audit
				</button>
			) }

			{ isLoading && (
				<InlineStatus tone="info" className="extrachill-seo-audit-status">
					Running audit...
				</InlineStatus>
			) }

			{ error && (
				<InlineStatus tone="error" className="extrachill-seo-audit-status">
					{ error }
				</InlineStatus>
			) }
		</ActionRow>
	);
};

export default AuditActions;
