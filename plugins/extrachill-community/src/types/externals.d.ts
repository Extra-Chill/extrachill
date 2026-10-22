declare module '@extrachill/components/styles/components.scss';
declare module '*.scss';

// Provided by WordPress 7.1's script module registry, not bundled by this plugin.
declare module '@wordpress/abilities' {
	export function executeAbility( name: string, input?: unknown ): Promise< unknown >;
}
declare module '@wordpress/core-abilities' {
	export const ready: Promise< void >;
}
