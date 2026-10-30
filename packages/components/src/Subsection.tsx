import { Section, type SectionProps } from './Section.tsx';

export interface SubsectionProps extends SectionProps {}

export function Subsection( props: SubsectionProps ) {
	return <Section { ...props } />;
}
