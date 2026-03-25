/**
 * SEO Admin App
 *
 * Root component with button-based tab navigation for Audit and Config panels.
 */

import { useState } from '@wordpress/element';
import { Panel, Tabs } from '@extrachill/components';
import '@extrachill/components/styles/components.scss';
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
			<Tabs tabs={ tabs.map( ( tab ) => ( { id: tab.id, label: tab.title } ) ) } active={ activeTab } onChange={ setActiveTab } className="extrachill-seo-admin__tabs" classPrefix="extrachill-seo-admin" />

			<Panel className="extrachill-seo-admin__content" compact>
				{ activeTab === 'audit' ? (
					<AuditProvider initialData={ initialData }>
						<AuditDashboard />
					</AuditProvider>
				) : (
					<ConfigPanel />
				) }
			</Panel>
		</div>
	);
};

export default App;
