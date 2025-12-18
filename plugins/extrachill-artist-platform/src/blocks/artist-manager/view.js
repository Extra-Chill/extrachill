import React, { useEffect, useMemo, useState, useRef, useCallback } from 'react';
import { render } from '@wordpress/element';
import {
	getArtist,
	updateArtist,
	getRoster,
	inviteRosterMember,
	removeRosterMember,
	cancelRosterInvite,
	searchArtistCapableUsers,
	getSubscribers,
	exportSubscribers,
	uploadMedia,
	deleteMedia,
} from '../shared/api/client';
import ArtistSwitcher from '../shared/components/ArtistSwitcher';

const isValidEmail = (email) => {
	return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
};

const useDebounce = (value, delay) => {
	const [debouncedValue, setDebouncedValue] = useState(value);

	useEffect(() => {
		const handler = setTimeout(() => {
			setDebouncedValue(value);
		}, delay);

		return () => {
			clearTimeout(handler);
		};
	}, [value, delay]);

	return debouncedValue;
};

const useConfig = () => {
	const config = window.ecArtistManagerConfig || {};
	return useMemo(
		() => ({
			restUrl: config.restUrl,
			nonce: config.nonce,
			userArtists: Array.isArray(config.userArtists) ? config.userArtists : [],
			selectedId: config.selectedId || 0,
			artistSiteUrl: config.artistSiteUrl || '',
			assets: config.assets || {},
		}),
		[ config ]
	);
};

const TabNav = ({ tabs, active, onChange }) => (
	<div className="ec-am__tabs">
		{tabs.map((tab) => (
			<button
				key={tab.id}
				type="button"
				className={`ec-am__tab${active === tab.id ? ' is-active' : ''}`}
				onClick={() => onChange(tab.id)}
			>
				{tab.label}
			</button>
		))}
	</div>
);



const InfoTab = ({ formState, setFormState, selectedId }) => {
	const handleFileUpload = async (file, contextKey) => {
		const context = contextKey === 'header' ? 'artist_header' : 'artist_profile';
		const response = await uploadMedia(context, selectedId || 0, file);
		const attachmentId = response?.attachment_id || null;

		if (contextKey === 'profile') {
			setFormState((prev) => ({
				...prev,
				profileImage: response?.url || '',
				profileImageId: attachmentId,
			}));
		}
		if (contextKey === 'header') {
			setFormState((prev) => ({
				...prev,
				headerImage: response?.url || '',
				headerImageId: attachmentId,
			}));
		}
	};

	const handleRemoveImage = async (contextKey) => {
		if (!selectedId) {
			return;
		}

		if (contextKey === 'profile' && formState.profileImageId) {
			await deleteMedia('artist_profile', selectedId);
			setFormState((prev) => ({
				...prev,
				profileImage: '',
				profileImageId: null,
			}));
		}
		if (contextKey === 'header' && formState.headerImageId) {
			await deleteMedia('artist_header', selectedId);
			setFormState((prev) => ({
				...prev,
				headerImage: '',
				headerImageId: null,
			}));
		}
	};

	return (
		<div className="ec-am__panel">
			<label className="ec-am__field">
				<span>Profile Picture</span>
				{formState.profileImage && <img src={formState.profileImage} alt="Profile" className="ec-am__image-preview" />}
				<input
					type="file"
					accept="image/*"
					onChange={(e) => {
						const file = e.target.files?.[0];
						if (file) handleFileUpload(file, 'profile');
					}}
				/>
				{formState.profileImage && (
					<button type="button" className="button-danger button-small" onClick={() => handleRemoveImage('profile')}>
						Remove
					</button>
				)}
			</label>

			<label className="ec-am__field">
				<span>Header Image</span>
				{formState.headerImage && <img src={formState.headerImage} alt="Header" className="ec-am__image-preview" />}
				<input
					type="file"
					accept="image/*"
					onChange={(e) => {
						const file = e.target.files?.[0];
						if (file) handleFileUpload(file, 'header');
					}}
				/>
				{formState.headerImage && (
					<button type="button" className="button-danger button-small" onClick={() => handleRemoveImage('header')}>
						Remove
					</button>
				)}
			</label>

			<label className="ec-am__field">
				<span>Artist Name *</span>
				<input
					type="text"
					value={formState.name}
					onChange={(e) => setFormState((prev) => ({ ...prev, name: e.target.value }))}
					required
				/>
			</label>

			<label className="ec-am__field">
				<span>City / Region</span>
				<input
					type="text"
					value={formState.localCity}
					onChange={(e) => setFormState((prev) => ({ ...prev, localCity: e.target.value }))}
				/>
			</label>

			<label className="ec-am__field">
				<span>Genre</span>
				<input
					type="text"
					value={formState.genre}
					onChange={(e) => setFormState((prev) => ({ ...prev, genre: e.target.value }))}
				/>
			</label>

			<label className="ec-am__field">
				<span>Artist Bio</span>
				<textarea
					value={formState.bio}
					onChange={(e) => setFormState((prev) => ({ ...prev, bio: e.target.value }))}
					rows={6}
				/>
			</label>
		</div>
	);
};

const ManagersTab = ({ artistId }) => {
	const [loading, setLoading] = useState(false);
	const [roster, setRoster] = useState([]);
	const [invites, setInvites] = useState([]);
	const [inputValue, setInputValue] = useState('');
	const [selectedEmail, setSelectedEmail] = useState('');
	const [error, setError] = useState('');
	const [searchResults, setSearchResults] = useState([]);
	const [showDropdown, setShowDropdown] = useState(false);
	const [searching, setSearching] = useState(false);
	const containerRef = useRef(null);

	const debouncedSearch = useDebounce(inputValue, 300);

	const load = async () => {
		setLoading(true);
		try {
			const data = await getRoster(artistId);
			setRoster(Array.isArray(data.members) ? data.members : []);
			setInvites(Array.isArray(data.invites) ? data.invites : []);
			setError('');
		} catch (err) {
			setError('Could not load managers.');
		} finally {
			setLoading(false);
		}
	};

	useEffect(() => {
		if (artistId) {
			load();
		}
	// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [artistId]);

	// Search for artist-capable users when input changes
	useEffect(() => {
		const search = async () => {
			if (debouncedSearch.length < 2) {
				setSearchResults([]);
				setShowDropdown(false);
				return;
			}

			setSearching(true);
			try {
				const results = await searchArtistCapableUsers(debouncedSearch, artistId);
				setSearchResults(Array.isArray(results) ? results : []);
				setShowDropdown(true);
			} catch (err) {
				setSearchResults([]);
			} finally {
				setSearching(false);
			}
		};

		// Only search if we haven't selected a user (inputValue differs from selectedEmail)
		if (inputValue !== selectedEmail) {
			search();
		}
	}, [debouncedSearch, artistId, inputValue, selectedEmail]);

	// Close dropdown when clicking outside
	useEffect(() => {
		const handleClickOutside = (event) => {
			if (containerRef.current && !containerRef.current.contains(event.target)) {
				setShowDropdown(false);
			}
		};

		document.addEventListener('mousedown', handleClickOutside);
		return () => document.removeEventListener('mousedown', handleClickOutside);
	}, []);

	const handleInputChange = (e) => {
		const value = e.target.value;
		setInputValue(value);
		setSelectedEmail('');
		setError('');
	};

	const handleSelectUser = (user) => {
		setInputValue(user.email);
		setSelectedEmail(user.email);
		setShowDropdown(false);
		setSearchResults([]);
	};

	const invite = async () => {
		const emailToInvite = selectedEmail || inputValue.trim();

		if (!emailToInvite) return;

		if (!isValidEmail(emailToInvite)) {
			setError('Please enter a valid email address.');
			return;
		}

		setLoading(true);
		setError('');
		try {
			await inviteRosterMember(artistId, emailToInvite);
			setInputValue('');
			setSelectedEmail('');
			await load();
		} catch (err) {
			setError(err?.message || 'Error sending invite.');
		} finally {
			setLoading(false);
		}
	};

	const remove = async (userId) => {
		setLoading(true);
		try {
			await removeRosterMember(artistId, userId);
			await load();
		} catch (err) {
			setError(err?.message || 'Error removing member.');
		} finally {
			setLoading(false);
		}
	};

	const handleCancelInvite = async (inviteId) => {
		setLoading(true);
		try {
			await cancelRosterInvite(artistId, inviteId);
			await load();
		} catch (err) {
			setError(err?.message || 'Error cancelling invite.');
		} finally {
			setLoading(false);
		}
	};

	return (
		<div className="ec-am__panel">
			<h3>Profile Managers</h3>
			{loading && <p>Loading…</p>}
			{error && <p className="ec-am__error">{error}</p>}
			<div className="ec-am__inline">
				<div className="ec-am__search-container" ref={containerRef}>
					<input
						type="text"
						value={inputValue}
						onChange={handleInputChange}
						onFocus={() => searchResults.length > 0 && setShowDropdown(true)}
						placeholder="Search users or enter email"
						autoComplete="off"
					/>
					{showDropdown && searchResults.length > 0 && (
						<div className="ec-am__search-dropdown">
							{searchResults.map((user) => (
								<button
									key={user.id}
									type="button"
									className="ec-am__search-result"
									onClick={() => handleSelectUser(user)}
								>
									{user.avatar_url && (
										<img src={user.avatar_url} alt="" className="ec-am__search-avatar" />
									)}
									<div className="ec-am__search-info">
										<span className="ec-am__search-name">{user.display_name}</span>
										<span className="ec-am__search-email">{user.email}</span>
									</div>
								</button>
							))}
						</div>
					)}
					{searching && <span className="ec-am__search-loading">Searching…</span>}
				</div>
				<button
					type="button"
					className="button-1 button-medium"
					onClick={invite}
					disabled={loading || !inputValue.trim()}
				>
					Send Invite
				</button>
			</div>
			<div className="ec-am__list">
				{roster.map((member) => (
					<div key={member.id} className="ec-am__list-item">
						<div>
							{member.profile_url ? (
								<a href={member.profile_url} target="_blank" rel="noopener noreferrer">
									<strong>{member.display_name}</strong>
								</a>
							) : (
								<strong>{member.display_name}</strong>
							)}
							{member.status && <span className="ec-am__pill">{member.status}</span>}
						</div>
						<button type="button" className="button-danger button-small" onClick={() => remove(member.id)}>
							Remove
						</button>
					</div>
				))}
				{invites.map((inv) => (
					<div key={inv.id} className="ec-am__list-item">
						<div>
							<strong>{inv.email}</strong>
							<span className="ec-am__pill">Pending</span>
						</div>
						<button type="button" className="button-danger button-small" onClick={() => handleCancelInvite(inv.id)}>
							Cancel
						</button>
					</div>
				))}
				{!loading && roster.length === 0 && invites.length === 0 && <p>No managers yet.</p>}
			</div>
		</div>
	);
};

const SubscribersTab = ({ artistId }) => {
	const [loading, setLoading] = useState(false);
	const [subs, setSubs] = useState([]);
	const [page, setPage] = useState(1);
	const [total, setTotal] = useState(0);
	const [error, setError] = useState('');

	const load = async (nextPage = 1) => {
		setLoading(true);
		try {
			const data = await getSubscribers(artistId, nextPage, 20);
			setSubs(Array.isArray(data.subscribers) ? data.subscribers : []);
			setTotal(data.total || 0);
			setPage(nextPage);
			setError('');
		} catch (err) {
			setError('Could not load subscribers.');
		} finally {
			setLoading(false);
		}
	};

	useEffect(() => {
		if (artistId) {
			load(1);
		}
	// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ artistId ]);

	const exportCsv = async () => {
		try {
			const data = await exportSubscribers(artistId, false);
			if (data?.url) {
				window.location.href = data.url;
			}
		} catch (err) {
			setError('Export failed.');
		}
	};

	const totalPages = Math.max(1, Math.ceil(total / 20));

	return (
		<div className="ec-am__panel">
			<h3>Subscribers</h3>
			{loading && <p>Loading…</p>}
			{error && <p className="ec-am__error">{error}</p>}
			<div className="ec-am__list">
				{subs.map((sub) => (
					<div key={sub.subscriber_id} className="ec-am__list-item">
						<div>
							<strong>{sub.subscriber_email}</strong>
							{sub.username && <span className="ec-am__pill">{sub.username}</span>}
						</div>
						<span className="ec-am__pill">{sub.subscribed_at}</span>
					</div>
				))}
				{!loading && subs.length === 0 && <p>No subscribers yet.</p>}
			</div>
			<div className="ec-am__footer">
				<button type="button" className="button-2 button-medium" onClick={exportCsv} disabled={!subs.length}>
					Export CSV
				</button>
				<div className="ec-am__pagination">
					<button type="button" className="button-3 button-small" onClick={() => load(Math.max(1, page - 1))} disabled={page <= 1}>
						Prev
					</button>
					<span>
						Page {page} of {totalPages}
					</span>
					<button type="button" className="button-3 button-small" onClick={() => load(Math.min(totalPages, page + 1))} disabled={page >= totalPages}>
						Next
					</button>
				</div>
			</div>
		</div>
	);
};

const getInitialFormState = (artist) => ({
	name: artist?.name || '',
	bio: artist?.bio || '',
	localCity: artist?.local_city || '',
	genre: artist?.genre || '',
	profileImage: artist?.profile_image_url || '',
	headerImage: artist?.header_image_url || '',
	profileImageId: artist?.profile_image_id || null,
	headerImageId: artist?.header_image_id || null,
});

const App = () => {
	const config = useConfig();
	const [activeTab, setActiveTab] = useState('info');
	const [selectedId, setSelectedId] = useState(Number(config.selectedId) || 0);
	const [artist, setArtist] = useState(null);
	const [loading, setLoading] = useState(false);
	const [saving, setSaving] = useState(false);
	const [saveSuccess, setSaveSuccess] = useState(false);
	const [error, setError] = useState('');
	const [formState, setFormState] = useState(() => getInitialFormState(null));

	const tabs = [
		{ id: 'info', label: 'Info' },
		{ id: 'managers', label: 'Profile Managers' },
		{ id: 'subscribers', label: 'Subscribers' },
	];

	const loadArtist = async (id) => {
		if (!id || id <= 0) {
			setArtist(null);
			setFormState(getInitialFormState(null));
			return;
		}
		setLoading(true);
		try {
			const data = await getArtist(id);
			setArtist(data);
			setFormState(getInitialFormState(data));
			setError('');
		} catch (err) {
			setError(err?.message || 'Could not load artist.');
		} finally {
			setLoading(false);
		}
	};

	useEffect(() => {
		if (config.selectedId) {
			loadArtist(config.selectedId);
		}
	// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ config.selectedId ]);

	const handleSave = async () => {
		if (!selectedId) {
			setError('No artist selected.');
			return;
		}

		if (!formState.name.trim()) {
			setError('Artist name is required.');
			return;
		}

		setSaving(true);
		setSaveSuccess(false);
		setError('');

		const payload = {
			name: formState.name,
			bio: formState.bio,
			local_city: formState.localCity,
			genre: formState.genre,
			profile_image_id: formState.profileImageId,
			header_image_id: formState.headerImageId,
		};

		try {
			const updated = await updateArtist(selectedId, payload);
			setArtist(updated);
			setFormState(getInitialFormState(updated));
			setSaveSuccess(true);
			setTimeout(() => setSaveSuccess(false), 3000);
		} catch (err) {
			setError(err?.message || 'Save failed.');
		} finally {
			setSaving(false);
		}
	};

	const handleSelect = (id) => {
		const numId = Number(id) || 0;
		setSelectedId(numId);
		if (numId > 0) {
			loadArtist(numId);
		} else {
			setArtist(null);
			setFormState(getInitialFormState(null));
		}
	};

	const artistName = formState.name || '';
	const saveButtonLabel = saving ? 'Saving…' : 'Save';

	return (
		<div className="ec-am">
			<div className="ec-am__header">
				<div className="ec-am__header-left">
					<h2>{artistName}</h2>
					<ArtistSwitcher
						artists={config.userArtists}
						selectedId={selectedId}
						onChange={handleSelect}
					/>
				</div>
			<div className="ec-am__header-right">
				{error && <span className="ec-am__save-error">{error}</span>}
				{saveSuccess && <span className="ec-am__save-success">Saved!</span>}
				{artist?.slug && config.artistSiteUrl && (
					<a
						href={`${config.artistSiteUrl}/${artist.slug}`}
						className="button-3 button-medium"
					>
						View Profile
					</a>
				)}
				<button
					type="button"
					className="button-1 button-medium"
					onClick={handleSave}
					disabled={saving || !selectedId}
				>
					{saveButtonLabel}
				</button>
			</div>
			</div>

			{loading && <p>Loading artist…</p>}

			<TabNav tabs={tabs} active={activeTab} onChange={setActiveTab} />

			{activeTab === 'info' && (
				<InfoTab
					formState={formState}
					setFormState={setFormState}
					selectedId={selectedId}
				/>
			)}

			{selectedId && activeTab === 'managers' && <ManagersTab artistId={selectedId} />}
			{selectedId && activeTab === 'subscribers' && <SubscribersTab artistId={selectedId} />}
		</div>
	);
};

const rootEl = document.getElementById('ec-artist-manager-root');

if (rootEl) {
	render(<App />, rootEl);
}
