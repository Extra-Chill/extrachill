import type { ReactNode } from 'react';

export interface PanelProps {
	children: ReactNode;
	className?: string;
	classPrefix?: string;
	compact?: boolean;
}

export function Panel( {
	children,
	className = '',
	classPrefix = 'ec-panel',
	compact = false,
}: PanelProps ) {
	const panelClass = [
		classPrefix,
		compact ? `${ classPrefix }--compact` : '',
		className,
	].filter( Boolean ).join( ' ' );

	return <div className={ panelClass }>{ children }</div>;
}
