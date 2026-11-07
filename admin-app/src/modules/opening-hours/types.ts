export const DAYS = [ 'mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun' ] as const;
export type DayKey = ( typeof DAYS )[ number ];

export type Range = { open: string; close: string };
export type Hours = Record< DayKey, Range[] >;
export type Exception = { date: string; closed: boolean; label: string; hours: Range[] };
export type Severity = 'info' | 'warning' | 'critical';
export type Announcement = { text: string; from: string; to: string; severity: Severity };

export type Status = {
	open: boolean;
	location_id: number;
	checked_at: string;
	next_change: string | null;
	override: { until: number; reason: string } | null;
};

export type Location = {
	id: number;
	title: string;
	address: string;
	phone: string;
	lat: number | null;
	lng: number | null;
	hours: Hours;
	exceptions: Exception[];
	announcements: Announcement[];
	override: { until: number; reason: string; kind: string } | null;
	status: Status;
};

export type Draft = Omit< Location, 'id' | 'override' | 'status' > & { id: number | null };

export const EMPTY_HOURS: Hours = { mon: [], tue: [], wed: [], thu: [], fri: [], sat: [], sun: [] };

export const EMPTY_DRAFT: Draft = {
	id: null,
	title: '',
	address: '',
	phone: '',
	lat: null,
	lng: null,
	hours: EMPTY_HOURS,
	exceptions: [],
	announcements: [],
};
