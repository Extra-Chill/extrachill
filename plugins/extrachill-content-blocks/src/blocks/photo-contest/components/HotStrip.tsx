import type { Entry } from '../types';

/**
 * Top-voted entries as a compact row of thumbnails. Each thumbnail links to
 * the entry's single view (?entry=ID), which shows the full photo + Vote.
 */
export function HotStrip( { entries }: { entries: Entry[] } ) {
	if ( entries.length === 0 ) {
		return null;
	}
	const linkFor = ( id: number ) => {
		const url = new URL( window.location.href );
		url.searchParams.set( 'entry', String( id ) );
		url.hash = '';
		return url.href;
	};
	return (
		<section
			className="extrachill-blocks-photo-contest-hot"
			aria-label="Leading entries"
		>
			<div className="extrachill-blocks-photo-contest-hot-header">
				<span className="extrachill-blocks-photo-contest-hot-badge">
					🔥 HOT
				</span>
				<span className="extrachill-blocks-photo-contest-hot-sub">
					Leading right now
				</span>
			</div>
			<div className="extrachill-blocks-photo-contest-hot-row">
				{ entries.map( ( entry ) => (
					<a
						key={ entry.id }
						className="extrachill-blocks-photo-contest-hot-item"
						href={ linkFor( entry.id ) }
					>
						<img
							src={ entry.image_url }
							alt={ `${ entry.display_name }'s entry` }
							loading="lazy"
						/>
						<span className="extrachill-blocks-photo-contest-hot-name">
							{ entry.display_name }
						</span>
						<span className="extrachill-blocks-photo-contest-hot-votes">
							{ entry.vote_count }{ ' ' }
							{ entry.vote_count === 1 ? 'vote' : 'votes' }
						</span>
					</a>
				) ) }
			</div>
		</section>
	);
}
