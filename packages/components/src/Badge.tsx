import type { ReactNode } from 'react';

export interface BadgeProps {
	children: ReactNode;
	tone?: 'default' | 'muted' | 'success' | 'error' | 'warning' | 'info';
	variant?: 'solid' | 'outline' | 'subtle';
	size?: 'sm' | 'md';
	className?: string;
	classPrefix?: string;
}

export function Badge( {
	children,
	tone = 'default',
	variant = 'subtle',
	size = 'sm',
	className = '',
	classPrefix = 'ec-badge',
}: BadgeProps ) {
	const classes = [
		classPrefix,
		`${ classPrefix }--${ tone }`,
		`${ classPrefix }--${ variant }`,
		`${ classPrefix }--${ size }`,
		className,
	].filter( Boolean ).join( ' ' );

	return <span className={ classes }>{ children }</span>;
}
