import type { ReactNode } from 'react';

export interface PanelProps {
	children: ReactNode;
	className?: string;
	classPrefix?: string;
	compact?: boolean;
	depth?: 0 | 1 | 2 | 3;
}

export function Panel( {
	children,
	className = '',
	classPrefix = 'ec-panel',
	compact = false,
	depth = 1,
}: PanelProps ) {
	const panelClass = [
		classPrefix,
		compact ? `${ classPrefix }--compact` : '',
		`${ classPrefix }--depth-${ depth }`,
		className,
	].filter( Boolean ).join( ' ' );

	return <div className={ panelClass }>{ children }</div>;
}
