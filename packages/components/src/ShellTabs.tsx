import { Tabs, type TabsProps } from './Tabs.tsx';

export interface ShellTabsProps {
	tabs: TabsProps['tabs'];
	active: TabsProps['active'];
	onChange: TabsProps['onChange'];
	className?: string;
	classPrefix?: string;
	tabsClassName?: string;
	tabsClassPrefix?: string;
	showDivider?: boolean;
}

export function ShellTabs( {
	tabs,
	active,
	onChange,
	className = '',
	classPrefix = 'ec-shell-tabs',
	tabsClassName = '',
	tabsClassPrefix = 'ec-tabs',
	showDivider = true,
}: ShellTabsProps ) {
	return (
		<div
			className={ [
				classPrefix,
				showDivider ? `${ classPrefix }--with-divider` : `${ classPrefix }--without-divider`,
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
