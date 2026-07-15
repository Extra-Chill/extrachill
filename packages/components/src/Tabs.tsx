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
	/**
	 * Associates each tab with a panel named `${idPrefix}-panel-${index}`.
	 * ResponsiveTabs supplies this when it renders the matching panels.
	 */
	idPrefix?: string;
}

export function Tabs({
	tabs = [],
	active,
	onChange,
	classPrefix = 'ec-tabs',
	className = '',
	idPrefix,
}: TabsProps) {
	if (tabs.length === 0) {
		return null;
	}

	const rootClass = [`${classPrefix}__tabs`, className].filter(Boolean).join(' ');
	const focusTab = ( index: number, tabList?: HTMLElement | null ) => {
		const tab = tabs[ index ];
		if ( ! tab ) {
			return;
		}

		onChange( tab.id );
		if ( idPrefix ) {
			document.getElementById( `${ idPrefix }-tab-${ index }` )?.focus();
			return;
		}

		tabList?.querySelectorAll<HTMLElement>( '[role="tab"]' )[ index ]?.focus();
	};

	return (
		<div className={rootClass} role="tablist" aria-orientation="horizontal">
			{tabs.map((tab, index) => {
				const isActive = active === tab.id;
				const tabId = idPrefix ? `${ idPrefix }-tab-${ index }` : undefined;
				const panelId = idPrefix ? `${ idPrefix }-panel-${ index }` : undefined;

				return (
					<button
						key={tab.id}
						type="button"
						role="tab"
						id={tabId}
						aria-controls={panelId}
						aria-selected={isActive}
						tabIndex={isActive ? 0 : -1}
						className={`${classPrefix}__tab${isActive ? ' is-active' : ''}`}
						onClick={() => onChange(tab.id)}
						onKeyDown={(event) => {
							let nextIndex: number | null = null;
							switch ( event.key ) {
								case 'ArrowRight':
									nextIndex = ( index + 1 ) % tabs.length;
									break;
								case 'ArrowLeft':
									nextIndex = ( index - 1 + tabs.length ) % tabs.length;
									break;
								case 'Home':
									nextIndex = 0;
									break;
								case 'End':
									nextIndex = tabs.length - 1;
									break;
							}

							if ( nextIndex !== null ) {
								event.preventDefault();
								focusTab( nextIndex, event.currentTarget.parentElement );
							}
						}}
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
