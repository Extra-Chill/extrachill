import type { ReactNode } from 'react';

export interface BlockShellProps {
	children: ReactNode;
	className?: string;
	classPrefix?: string;
	compact?: boolean;
}

export function BlockShell( {
	children,
	className = '',
	classPrefix = 'ec-block-shell',
	compact = false,
}: BlockShellProps ) {
	const shellClass = [
		classPrefix,
		compact ? `${ classPrefix }--compact` : '',
		className,
	].filter( Boolean ).join( ' ' );

	return <div className={ shellClass }>{ children }</div>;
}
