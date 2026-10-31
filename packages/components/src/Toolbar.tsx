import type { ReactNode } from 'react';

export interface ToolbarProps {
	children: ReactNode;
	actions?: ReactNode;
	className?: string;
	classPrefix?: string;
}

/**
 * Toolbar — a horizontal bar with left-aligned content and right-aligned actions.
 *
 * Use as a view switcher, command bar, or filter strip inside any pane.
 * Accepts `Tabs` or any controls as children (left side), and dropdowns,
 * badges, or buttons as `actions` (right side).
 *
 * On mobile (<=480px) the layout stacks vertically.
 */
export function Toolbar( {
	children,
	actions,
	className = '',
	classPrefix = 'ec-toolbar',
}: ToolbarProps ) {
	return (
		<div
			className={ [ classPrefix, className ].filter( Boolean ).join( ' ' ) }
		>
			<div className={ `${ classPrefix }__start` }>{ children }</div>
			{ actions && <div className={ `${ classPrefix }__end` }>{ actions }</div> }
		</div>
	);
}
