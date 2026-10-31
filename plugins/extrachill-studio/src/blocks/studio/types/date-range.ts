export interface AnalyticsDateRange {
	startDate: string;
	endDate: string;
}

export interface AnalyticsDateRangeController {
	getRange: () => AnalyticsDateRange | null;
	setRange: ( startDate: string, endDate: string, trigger?: boolean ) => void;
	destroy: () => void;
}

export interface AnalyticsDateRangeRuntime {
	maxDays: number;
	create: (
		input: HTMLInputElement,
		options: {
			startDate: string;
			endDate: string;
			maxDays: number;
			onChange: ( range: AnalyticsDateRange | null ) => void;
			onError: ( error: unknown ) => void;
		}
	) => AnalyticsDateRangeController;
}
