import { Panel, type PanelProps } from './Panel.tsx';

export interface SurfaceProps extends PanelProps {}

export function Surface( props: SurfaceProps ) {
	return <Panel { ...props } />;
}
