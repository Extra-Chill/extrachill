import type { ReactNode } from 'react';

export interface BlockShellProps {
	children: ReactNode;
	className?: string;
	classPrefix?: string;
	compact?: boolean;
	depth?: 0 | 1 | 2 | 3;
}

export function BlockShell( {
	children,
	className = '',
	classPrefix = 'ec-block-shell',
	compact = false,
	depth = 0,
}: BlockShellProps ) {
	const shellClass = [
		classPrefix,
		'ec-page-edge-shell',
		compact ? `${ classPrefix }--compact` : '',
		`${ classPrefix }--depth-${ depth }`,
		className,
	].filter( Boolean ).join( ' ' );

	return <div className={ shellClass }>{ children }</div>;
}
