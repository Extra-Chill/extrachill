export interface TabItem {
	id: string;
	label: string;
	badge?: number;
}

export interface TabsProps {
	tabs: TabItem[];
	active: string;
	onChange: (id: string) => void;
	classPrefix?: string;
	className?: string;
}

export function Tabs({
	tabs = [],
	active,
	onChange,
	classPrefix = 'ec-tabs',
	className = '',
}: TabsProps) {
	if (tabs.length === 0) {
		return null;
	}

	const rootClass = [`${classPrefix}__tabs`, className].filter(Boolean).join(' ');

	return (
		<div className={rootClass} role="tablist" aria-orientation="horizontal">
			{tabs.map((tab) => {
				const isActive = active === tab.id;

				return (
					<button
						key={tab.id}
						type="button"
						role="tab"
						aria-selected={isActive}
						className={`${classPrefix}__tab${isActive ? ' is-active' : ''}`}
						onClick={() => onChange(tab.id)}
					>
						{tab.label}
						{tab.badge != null && tab.badge > 0 && (
							<span className={`${classPrefix}__tab-badge`}>{tab.badge}</span>
						)}
					</button>
				);
			})}
		</div>
	);
}
