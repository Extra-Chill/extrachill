/**
 * Tabs — Canonical tab navigation for the Extra Chill platform.
 *
 * Migrated from the artist platform's TabNav. Supports configurable
 * CSS class prefix for per-block namespacing and ARIA roles for
 * accessibility. All consumers across the network use this component.
 *
 * @param {Object}   props
 * @param {Array}    props.tabs        Array of { id, label, badge? }
 * @param {string}   props.active      Currently active tab ID
 * @param {Function} props.onChange     Called with tab.id on click
 * @param {string}   props.classPrefix CSS class prefix. Defaults to 'ec-tabs'.
 * @param {string}   props.className   Additional CSS class on the wrapper.
 */
const Tabs = ( { tabs = [], active, onChange, classPrefix = 'ec-tabs', className = '' } ) => {
	if ( tabs.length === 0 ) {
		return null;
	}

	const rootClass = [ `${ classPrefix }__tabs`, className ].filter( Boolean ).join( ' ' );

	return (
		<div className={ rootClass } role="tablist" aria-orientation="horizontal">
			{ tabs.map( ( tab ) => {
				const isActive = active === tab.id;

				return (
					<button
						key={ tab.id }
						type="button"
						role="tab"
						aria-selected={ isActive }
						className={ `${ classPrefix }__tab${ isActive ? ' is-active' : '' }` }
						onClick={ () => onChange?.( tab.id ) }
					>
						{ tab.label }
						{ tab.badge > 0 && (
							<span className={ `${ classPrefix }__tab-badge` }>{ tab.badge }</span>
						) }
					</button>
				);
			} ) }
		</div>
	);
};

export default Tabs;
