import type { ReactNode } from 'react';

export interface BlockShellInnerProps {
	children: ReactNode;
	className?: string;
	classPrefix?: string;
	maxWidth?: 'none' | 'narrow' | 'wide';
}

export function BlockShellInner( {
	children,
	className = '',
	classPrefix = 'ec-block-shell-inner',
	maxWidth = 'none',
}: BlockShellInnerProps ) {
	const innerClass = [
		classPrefix,
		maxWidth !== 'none' ? `${ classPrefix }--${ maxWidth }` : '',
		className,
	]
		.filter( Boolean )
		.join( ' ' );

	return <div className={ innerClass }>{ children }</div>;
}
