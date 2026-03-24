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

declare module '@extrachill/components/styles/components.scss';

/* ── @extrachill/chat ── */

declare module '@extrachill/chat/css';

/* ── Block metadata ── */

declare module '*.json' {
	const value: Record< string, unknown >;
	export default value;
}
