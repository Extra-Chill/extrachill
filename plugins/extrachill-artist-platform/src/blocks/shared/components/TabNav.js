/**
 * TabNav - Reusable tab navigation component
 *
 * Supports optional badge counts on tabs.
 * Uses classPrefix to namespace CSS classes per block.
 */

const TabNav = ( { tabs, active, onChange, classPrefix = 'ec' } ) => (
	<div className={ `${ classPrefix }__tabs` }>
		{ tabs.map( ( tab ) => (
			<button
				key={ tab.id }
				type="button"
				className={ `${ classPrefix }__tab${ active === tab.id ? ' is-active' : '' }` }
				onClick={ () => onChange( tab.id ) }
			>
				{ tab.label }
				{ tab.badge > 0 && (
					<span className={ `${ classPrefix }__tab-badge` }>{ tab.badge }</span>
				) }
			</button>
		) ) }
	</div>
);

export default TabNav;
