import type { ReactNode } from 'react';

export interface BlockShellProps {
	children: ReactNode;
	className?: string;
	classPrefix?: string;
	compact?: boolean;
	depth?: 0 | 1 | 2 | 3;
	variant?: 'card' | 'plain';
}

export function BlockShell( {
	children,
	className = '',
	classPrefix = 'ec-block-shell',
	compact = false,
	depth = 0,
	variant = 'card',
}: BlockShellProps ) {
	const shellClass = [
		classPrefix,
		compact ? `${ classPrefix }--compact` : '',
		`${ classPrefix }--depth-${ depth }`,
		`${ classPrefix }--${ variant }`,
		className,
	].filter( Boolean ).join( ' ' );

	return <div className={ shellClass }>{ children }</div>;
}
