import apiFetch from '@wordpress/api-fetch';

function extrachillLeaderboardRender( container, state ) {
	const { page, data, error, loading } = state;

	const header = document.createElement( 'div' );
	header.className = 'extrachill-leaderboard__header';

	const body = document.createElement( 'div' );
	body.className = 'extrachill-leaderboard__body';

	header.textContent = container.dataset.title || 'Leaderboard';

	if ( loading ) {
		body.textContent = 'Loading…';
		container.replaceChildren( header, body );
		return;
	}

	if ( error ) {
		body.textContent = 'Unable to load leaderboard.';
		container.replaceChildren( header, body );
		return;
	}

	if ( ! data || ! Array.isArray( data.items ) ) {
		body.textContent = 'Invalid leaderboard response.';
		container.replaceChildren( header, body );
		return;
	}

	const table = document.createElement( 'table' );
	table.className = 'extrachill-leaderboard__table';

	table.innerHTML = `
		<thead>
			<tr>
				<th scope="col">#</th>
				<th scope="col">User</th>
				<th scope="col">Points</th>
				<th scope="col">Rank</th>
				<th scope="col">Joined</th>
			</tr>
		</thead>
		<tbody></tbody>
	`;

	const tbody = table.querySelector( 'tbody' );
	data.items.forEach( ( item ) => {
		const tr = document.createElement( 'tr' );

		const joined = item.registered ? new Date( item.registered ) : null;
		const joinedValue =
			joined && ! Number.isNaN( joined.getTime() )
				? joined.toLocaleDateString()
				: '';

		const positionTd = document.createElement( 'td' );
		positionTd.className = 'extrachill-leaderboard__position';
		positionTd.textContent =
			item.position !== undefined && item.position !== null
				? item.position
				: '';

		const userTd = document.createElement( 'td' );
		userTd.className = 'extrachill-leaderboard__user';

		if ( item.profile_url ) {
			const link = document.createElement( 'a' );
			link.href = item.profile_url;
			link.textContent = item.display_name || item.username || '';
			userTd.appendChild( link );
		} else {
			userTd.textContent = item.display_name || item.username || '';
		}

		if (
			Array.isArray( item.badges ) &&
			item.badges.length &&
			container.dataset.spriteUrl
		) {
			const badgesContainer = document.createElement( 'span' );
			badgesContainer.className = 'ec-user-badges';

			item.badges.forEach( ( badge ) => {
				if (
					! badge ||
					! badge.icon ||
					! badge.class_name ||
					! badge.title
				) {
					return;
				}

				const badgeEl = document.createElement( 'span' );
				badgeEl.className = badge.class_name;
				badgeEl.dataset.title = badge.title;

				const svg = document.createElement( 'svg' );
				svg.className = 'ec-icon';

				const use = document.createElement( 'use' );
				use.setAttribute(
					'href',
					`${ container.dataset.spriteUrl }#${ badge.icon }`
				);

				svg.appendChild( use );
				badgeEl.appendChild( svg );
				badgesContainer.appendChild( badgeEl );
			} );

			if ( badgesContainer.childNodes.length ) {
				userTd.appendChild( badgesContainer );
			}
		}

		const pointsTd = document.createElement( 'td' );
		pointsTd.className = 'extrachill-leaderboard__points';
		pointsTd.textContent =
			item.points !== undefined && item.points !== null
				? item.points
				: '';

		const rankTd = document.createElement( 'td' );
		rankTd.className = 'extrachill-leaderboard__rank';
		rankTd.textContent =
			item.rank !== undefined && item.rank !== null ? item.rank : '';

		const joinedTd = document.createElement( 'td' );
		joinedTd.className = 'extrachill-leaderboard__joined';
		joinedTd.textContent = joinedValue;

		tr.appendChild( positionTd );
		tr.appendChild( userTd );
		tr.appendChild( pointsTd );
		tr.appendChild( rankTd );
		tr.appendChild( joinedTd );

		tbody.appendChild( tr );
	} );

	const pagination = document.createElement( 'div' );
	pagination.className = 'extrachill-leaderboard__pagination';

	const totalPages =
		data.pagination && data.pagination.total_pages
			? data.pagination.total_pages
			: 1;

	const prev = document.createElement( 'button' );
	prev.type = 'button';
	prev.className = 'button-3 button-small';
	prev.textContent = 'Previous';
	prev.disabled = page <= 1;
	prev.addEventListener( 'click', () => {
		state.setPage( page - 1 );
	} );

	const next = document.createElement( 'button' );
	next.type = 'button';
	next.className = 'button-3 button-small';
	next.textContent = 'Next';
	next.disabled = page >= totalPages;
	next.addEventListener( 'click', () => {
		state.setPage( page + 1 );
	} );

	const label = document.createElement( 'span' );
	label.className = 'extrachill-leaderboard__page-label';
	label.textContent = `Page ${ page } of ${ totalPages }`;

	pagination.replaceChildren( prev, label, next );

	body.replaceChildren( table, pagination );
	container.replaceChildren( header, body );
}

function extrachillInitLeaderboard( container ) {
	const perPage = Math.max(
		1,
		Math.min( 100, parseInt( container.dataset.perPage || '25', 10 ) )
	);

	const state = {
		perPage,
		page: 1,
		data: null,
		error: null,
		loading: false,
		setPage: null,
	};

	state.setPage = ( nextPage ) => {
		state.page = Math.max( 1, nextPage );
		extrachillLeaderboardLoad( container, state );
	};

	extrachillLeaderboardLoad( container, state );
}

function extrachillLeaderboardLoad( container, state ) {
	state.loading = true;
	state.error = null;
	extrachillLeaderboardRender( container, state );

	apiFetch( {
		path: `/extrachill/v1/users/leaderboard?page=${ state.page }&per_page=${ state.perPage }`,
	} )
		.then( ( data ) => {
			state.data = data;
			state.loading = false;
			extrachillLeaderboardRender( container, state );
		} )
		.catch( ( err ) => {
			state.error = err;
			state.loading = false;
			extrachillLeaderboardRender( container, state );
		} );
}

window.addEventListener( 'DOMContentLoaded', () => {
	document
		.querySelectorAll( '.wp-block-extrachill-leaderboard' )
		.forEach( ( container ) => extrachillInitLeaderboard( container ) );
} );
