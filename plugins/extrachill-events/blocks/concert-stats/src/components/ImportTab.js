/**
 * ImportTab — Container for the Import tab in the concert-stats block.
 *
 * Lists every registered import source as a card and shows the current
 * user's run history below.
 *
 * @package ExtraChillEvents
 */

import { InlineStatus, Section } from '@extrachill/components';
import ImportSourceCard from './ImportSourceCard';
import useImportRuns from '../hooks/useImportRuns';

const ImportTab = () => {
	const { sources, runs, loading, error, preview, start } = useImportRuns();

	if ( loading ) {
		return <InlineStatus tone="info">Loading import sources…</InlineStatus>;
	}

	if ( error ) {
		return <InlineStatus tone="error">{ error }</InlineStatus>;
	}

	if ( ! sources.length ) {
		return (
			<InlineStatus tone="info">
				No import sources are available yet.
			</InlineStatus>
		);
	}

	return (
		<Section className="ec-concert-stats__import-tab">
			<p className="ec-concert-stats__import-intro">
				Already tracking your shows on another site? Pull your history into Extra Chill.
				We&rsquo;ll match each show to events in our database and mark you as attended.
				Shows we can&rsquo;t find will be skipped.
			</p>

			<div className="ec-concert-stats__import-cards">
				{ sources.map( ( source ) => (
					<ImportSourceCard
						key={ source.slug }
						source={ source }
						runs={ runs }
						onPreview={ preview }
						onStart={ start }
					/>
				) ) }
			</div>
		</Section>
	);
};

export default ImportTab;
