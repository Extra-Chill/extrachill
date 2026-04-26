import type { ReactElement, ReactNode } from 'react';

/**
 * Type declarations for external packages that don't ship their own types.
 */

/* ── @extrachill/components ── */

declare module '@extrachill/components/components/Tabs' {
	import type { ComponentType } from 'react';

	interface Tab {
		id: string;
		label: string;
		badge?: number;
	}

	interface TabsProps {
		tabs: Tab[];
		active: string;
		onChange: ( id: string ) => void;
		classPrefix?: string;
		className?: string;
	}

	const Tabs: ComponentType< TabsProps >;
	export default Tabs;
}

// SocialPlatformConfig is exported from @extrachill/api-client — import from
// there directly. The previous local re-declaration drifted out of sync with
// the api-client contract; removed in the platforms-array-contract refactor.

declare module '@extrachill/components' {
	export interface TabItem {
		id: string;
		label: string;
		badge?: number;
	}

	export interface TabsProps {
		tabs: TabItem[];
		active: string;
		onChange: ( id: string ) => void;
		classPrefix?: string;
		className?: string;
	}
	export function Tabs( props: TabsProps ): ReactElement;

	export interface ToolbarProps {
		children: ReactNode;
		actions?: ReactNode;
		className?: string;
		classPrefix?: string;
	}
	export function Toolbar( props: ToolbarProps ): ReactElement;

	export interface PanelProps {
		children: ReactNode;
		className?: string;
		classPrefix?: string;
		compact?: boolean;
		depth?: 0 | 1 | 2 | 3;
	}
	export function Panel( props: PanelProps ): ReactElement;

	export interface ActionRowProps {
		children: ReactNode;
		align?: 'start' | 'between' | 'end';
		className?: string;
		classPrefix?: string;
	}
	export function ActionRow( props: ActionRowProps ): ReactElement;

	export interface FieldGroupProps {
		label?: ReactNode;
		help?: ReactNode;
		error?: ReactNode;
		required?: boolean;
		children: ReactNode;
		className?: string;
		classPrefix?: string;
		htmlFor?: string;
	}
	export function FieldGroup( props: FieldGroupProps ): ReactElement;

	export interface InlineStatusProps {
		children: ReactNode;
		tone: 'success' | 'error' | 'warning' | 'info';
		className?: string;
		classPrefix?: string;
	}
	export function InlineStatus( props: InlineStatusProps ): ReactElement;

	export interface BadgeProps {
		children: ReactNode;
		tone?: 'default' | 'muted' | 'success' | 'error' | 'warning' | 'info';
		variant?: 'solid' | 'outline' | 'subtle';
		size?: 'sm' | 'md';
		className?: string;
		classPrefix?: string;
	}
	export function Badge( props: BadgeProps ): ReactElement;

	export interface BlockShellProps {
		children: ReactNode;
		className?: string;
		classPrefix?: string;
		compact?: boolean;
		depth?: 0 | 1 | 2 | 3;
	}
	export function BlockShell( props: BlockShellProps ): ReactElement;

	export interface BlockShellInnerProps {
		children: ReactNode;
		className?: string;
		classPrefix?: string;
		maxWidth?: 'none' | 'narrow' | 'wide';
	}
	export function BlockShellInner( props: BlockShellInnerProps ): ReactElement;
}

declare module '@extrachill/components/styles/components.scss';

/* ── @extrachill/chat ── */

declare module '@extrachill/chat/css';

/* ── Block metadata ── */

declare module '*.json' {
	const value: Record< string, unknown >;
	export default value;
}
