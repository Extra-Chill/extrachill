/**
 * Audit Actions Component
 *
 * Buttons to run/continue audits.
 */
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
		<div className="extrachill-seo-audit-actions">
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
				<span className="extrachill-seo-audit-status loading">
					Running audit...
				</span>
			) }

			{ error && (
				<span className="extrachill-seo-audit-status error">
					{ error }
				</span>
			) }
		</div>
	);
};

export default AuditActions;
