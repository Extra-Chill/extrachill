import { Tabs, type TabsProps } from './Tabs.tsx';

export interface ShellTabsProps {
	tabs: TabsProps['tabs'];
	active: TabsProps['active'];
	onChange: TabsProps['onChange'];
	className?: string;
	classPrefix?: string;
	tabsClassName?: string;
	tabsClassPrefix?: string;
}

export function ShellTabs( {
	tabs,
	active,
	onChange,
	className = '',
	classPrefix = 'ec-shell-tabs',
	tabsClassName = '',
	tabsClassPrefix = 'ec-tabs',
}: ShellTabsProps ) {
	return (
		<div
			className={ [
				classPrefix,
				className,
			].filter( Boolean ).join( ' ' ) }
		>
			<Tabs
				tabs={ tabs }
				active={ active }
				onChange={ onChange }
				className={ tabsClassName }
				classPrefix={ tabsClassPrefix }
			/>
		</div>
	);
}
