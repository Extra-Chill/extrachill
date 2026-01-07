/**
 * SEO Admin App
 *
 * Root component with button-based tab navigation for Audit and Config panels.
 */

import { useState } from '@wordpress/element';
import { Button } from '@wordpress/components';
import { AuditProvider } from './context/AuditContext';
import AuditDashboard from './components/AuditDashboard';
import ConfigPanel from './components/ConfigPanel';
import { getConfig } from './api/client';

const tabs = [
	{ id: 'audit', title: 'Audit' },
	{ id: 'config', title: 'Config' },
];

const App = () => {
	const config = getConfig();
	const initialData = config.auditData || {
		status: 'none',
		results: {},
		timestamp: 0,
	};

	const [ activeTab, setActiveTab ] = useState( 'audit' );

	return (
		<div className="extrachill-seo-admin">
			<div className="extrachill-seo-admin__tabs">
				{ tabs.map( ( tab ) => (
					<Button
						key={ tab.id }
						variant={ activeTab === tab.id ? 'primary' : 'secondary' }
						onClick={ () => setActiveTab( tab.id ) }
						className="extrachill-seo-admin__tab"
					>
						{ tab.title }
					</Button>
				) ) }
			</div>

			<div className="extrachill-seo-admin__content">
				{ activeTab === 'audit' ? (
					<AuditProvider initialData={ initialData }>
						<AuditDashboard />
					</AuditProvider>
				) : (
					<ConfigPanel />
				) }
			</div>
		</div>
	);
};

export default App;
