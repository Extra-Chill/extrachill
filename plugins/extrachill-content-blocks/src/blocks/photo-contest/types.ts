export interface ContestConfig {
	postId: number;
	contestId: string;
	heading: string;
	eventTitle: string;
	entriesOpenAt: string;
	entriesOpenAtLabel: string;
	votingOpensAt: string;
	entriesCloseAt: string;
	votingClosesAt: string;
	showFinalists: boolean;
	finalistCount: number;
	isAdmin: boolean;
	welcomeEntrant?: boolean;
	welcomeVoter?: boolean;
	canModerate: boolean;
	restNonce: string;
	turnstileHtml: string;
}

export interface Entry {
	id: number;
	display_name: string;
	instagram: string;
	image_url: string;
	thumbnail_url: string;
	vote_count: number;
	share_url: string;
	hidden?: boolean;
}

export interface PhaseState {
	entries_closed: boolean;
	voting_closed: boolean;
	entries_manually_closed: boolean;
	voting_manually_closed: boolean;
}

export interface EntriesResponse {
	entries: Entry[];
	phase?: PhaseState;
}

export interface VoteResponse {
	vote_count: number;
}

export interface SubmitResponse {
	message: string;
	entry: Entry;
}

