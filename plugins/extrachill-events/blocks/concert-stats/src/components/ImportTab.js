/**
 * ImportTab — Container for the Import tab in the concert-stats block.
 *
 * Lists every CONFIGURED import source as a card and shows the current
 * user's run history below. End users never see "not yet configured"
 * plumbing — the underlying ability filters unconfigured sources out
 * server-side. By the time the source list reaches this component, every
 * entry is actionable.
 *
 * @package ExtraChillEvents
 */

import { Grid, InlineStatus, Section } from '@extrachill/components';
import ImportSourceCard from './ImportSourceCard';

/**
 * The parent (`view.js`) hoists the `useImportRuns` hook and passes the bag
 * down so the Import tab visibility check (`hasImports`) shares the same
 * fetch as the tab body. This component is a pure renderer — it does not
 * fetch on its own. `bag` is the return value of `useImportRuns()`.
 */
const ImportTab = ( { bag } ) => {
	const { sources, runs, loading, error, preview, start } = bag;

	if ( loading ) {
		return <InlineStatus tone="info">Loading import sources…</InlineStatus>;
	}

	if ( error ) {
		return <InlineStatus tone="error">{ error }</InlineStatus>;
	}

	// The parent view gates rendering on `sources.length > 0`, so reaching
	// this component with an empty list means the source list mutated after
	// initial mount (provider deconfigured mid-session). Leave a quiet
	// fallback rather than rendering the heading.
	if ( ! sources.length ) {
		return null;
	}

	return (
		<Section className="ec-concert-stats__import-tab">
			<p className="ec-concert-stats__import-intro">
				Already tracking your shows on another site? Pull your history into Extra Chill.
				We&rsquo;ll match each show to events in our database and mark you as attended.
				Shows we don&rsquo;t already have we&rsquo;ll add for you so your full history comes in.
			</p>

			<Grid
				className="ec-concert-stats__import-cards"
				minColumnWidth="280px"
			>
				{ sources.map( ( source ) => (
					<ImportSourceCard
						key={ source.slug }
						source={ source }
						runs={ runs }
						onPreview={ preview }
						onStart={ start }
					/>
				) ) }
			</Grid>
		</Section>
	);
};

export default ImportTab;
