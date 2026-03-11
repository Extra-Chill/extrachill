/**
 * Tabs Component
 *
 * Controlled tab navigation component for Extra Chill React interfaces.
 */

export default function Tabs( {
	tabs = [],
	activeTab,
	onChange,
	className = '',
} ) {
	if ( tabs.length === 0 ) {
		return null;
	}

	return (
		<div className={ `ec-tabs ${ className }`.trim() } role="tablist" aria-orientation="horizontal">
			{ tabs.map( ( tab ) => {
				const isActive = activeTab === tab.id;

				return (
					<button
						key={ tab.id }
						type="button"
						role="tab"
						aria-selected={ isActive }
						className={ `ec-tabs__tab${ isActive ? ' is-active' : '' }` }
						onClick={ () => onChange?.( tab.id ) }
					>
						<span className="ec-tabs__label">{ tab.label }</span>
						{ tab.badge > 0 && <span className="ec-tabs__badge">{ tab.badge }</span>}
					</button>
				);
			} ) }
		</div>
	);
}
