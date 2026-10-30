import {
	Panel,
	SearchBox,
	Tabs as RootTabs,
	type PanelProps,
	type TabItem,
} from '@extrachill/components';
import { Tabs, type TabsProps } from '@extrachill/components/Tabs';

const tabs: TabItem[] = [ { id: 'contract', label: 'Contract' } ];
const tabProps: TabsProps = {
	tabs,
	active: 'contract',
	onChange: () => {},
};
const panelProps: PanelProps = { children: 'Compiled package consumer' };

export const consumerContract = (
	<Panel { ...panelProps }>
		<RootTabs { ...tabProps } />
		<Tabs { ...tabProps } />
		<SearchBox onSearch={ () => {} } />
		<SearchBox label="Search venue bookings" onSearch={ () => {} } />
	</Panel>
);
