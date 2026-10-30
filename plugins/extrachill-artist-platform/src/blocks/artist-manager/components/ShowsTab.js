import React from 'react';

export default function ShowsTab( { artistName, submitUrl } ) {
	if ( ! submitUrl ) {
		return null;
	}

	return (
		<div>
			<h2>Shows for { artistName }</h2>
			<p>
				Add your shows to Extra Chill Events. Include { artistName } in
				the lineup when submitting.
			</p>
			<h3>Add a show</h3>
			<p>Upload a flyer or enter the show details manually.</p>
			<a className="button-1" href={ submitUrl }>
				Add a show
			</a>
			<h3>Import a recurring source</h3>
			<p>
				Have a calendar or another source for your shows? Set up a
				recurring import on Extra Chill Events.
			</p>
			<a
				className="button-2"
				href={ `${ submitUrl }#ec-event-source-import` }
			>
				Import a show source
			</a>
		</div>
	);
}
