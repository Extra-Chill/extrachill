/**
 * SEO Admin App
 *
 * Root component for the SEO audit dashboard.
 */
import { AuditProvider } from './context/AuditContext';
import AuditDashboard from './components/AuditDashboard';
import { getConfig } from './api/client';

const App = () => {
	const config = getConfig();
	const initialData = config.auditData || { status: 'none', results: {}, timestamp: 0 };

	return (
		<AuditProvider initialData={ initialData }>
			<AuditDashboard />
		</AuditProvider>
	);
};

export default App;
