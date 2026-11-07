/** Shapes shared by the Form Builder screens; they mirror Forms.php / Fields.php. */

export const FIELD_TYPES = [ 'text', 'email', 'tel', 'url', 'number', 'date', 'textarea', 'select', 'radio', 'checkbox_group', 'checkbox', 'acceptance', 'file', 'hidden', 'html' ] as const;
export type FieldType = ( typeof FIELD_TYPES )[ number ];

export const CHOICE_TYPES: FieldType[] = [ 'select', 'radio', 'checkbox_group' ];
export const FILE_EXTENSIONS = [ 'jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'txt', 'csv', 'doc', 'docx', 'xls', 'xlsx', 'odt', 'ods', 'zip' ] as const;
export const WIDTHS = [ 100, 75, 66, 50, 33, 25 ] as const;
export const OPERATORS = [ 'equals', 'not_equals', 'contains', 'empty', 'not_empty' ] as const;
export type Operator = ( typeof OPERATORS )[ number ];

export type FieldOption = { label: string; value: string };
export type Condition = { field: string; operator: Operator; value: string };

export type FormField = {
	key: string;
	type: FieldType;
	label: string;
	placeholder: string;
	help: string;
	required: boolean;
	default_value: string;
	width: number;
	css_class: string;
	conditions: Condition[];
	logic: 'all' | 'any';
	options?: FieldOption[];
	html?: string;
	terms_url?: string;
	minlength?: number | null;
	maxlength?: number | null;
	min?: number | null;
	max?: number | null;
	rows?: number;
	accept?: string[];
	max_size_mb?: number;
	multiple?: boolean;
};

export type FormSettings = {
	send_notification: boolean;
	to: string;
	subject: string;
	reply_to_field: string;
	attach_files: boolean;
	confirm_enabled: boolean;
	confirm_field: string;
	confirm_subject: string;
	confirm_message: string;
	success_message: string;
	redirect_url: string;
	submit_label: string;
	store: boolean;
	min_time: number;
	turnstile: boolean;
};

export type FormDef = {
	id: number;
	title: string;
	status: 'published' | 'draft';
	fields: FormField[];
	settings: FormSettings;
	shortcode: string;
};

export type FormSummary = {
	id: number;
	title: string;
	status: 'published' | 'draft';
	field_count: number;
	submissions: number;
	unread: number;
	created: string;
	modified: string;
	shortcode: string;
};

export type StoredFile = { name: string; size: number; mime: string; path: string };

export type Submission = {
	id: number;
	form_id: number;
	form_title: string;
	created: string;
	is_read: boolean;
	ip: string;
	user_agent: string;
	page_url: string;
	mail_status: string;
	data: Record< string, string | string[] >;
	files: Record< string, StoredFile[] >;
};

export type SubmissionRow = Omit< Submission, 'files' | 'data' > & { data: Record< string, string >; files: Record< string, number > };
