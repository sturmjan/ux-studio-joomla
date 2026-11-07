/** Shapes of GET core/onboarding and the state kept by the setup wizard (Core/Onboarding/OnboardingApi.php). */
import type { ModuleInfo } from '../api';

export type WizardStatus = 'new' | 'started' | 'dismissed' | 'completed';

export interface Profile {
	sender_email: string;
	sender_name: string;
	contact_email: string;
}

export type ProfileField = keyof Profile;

export interface OnboardingState {
	status: WizardStatus;
	site_type: string;
	modules: string[];
	profile: Profile;
	started_at: string | null;
	completed_at: string | null;
	dismissed_at: string | null;
}

export interface SiteTypeInfo {
	id: string;
	icon: string;
	label: string;
	description: string;
}

/** A module of the registry with the site types it is recommended for. */
export interface WizardModule extends ModuleInfo {
	types: string[];
}

export interface OnboardingData {
	state: OnboardingState;
	/** The wizard should still be offered (never finished, nothing turned on yet). */
	pending: boolean;
	/** Open the wizard by itself (first visit). */
	auto: boolean;
	site_types: SiteTypeInfo[];
	modules: WizardModule[];
	profile: Profile;
	defaults: Profile;
	/** Profile field => ids of modules whose settings it fills (only modules that exist and have such a setting). */
	profile_targets: Record< ProfileField, string[] >;
	site: { name: string; url: string };
	/** The Central App connector, null when this installation has no such module. */
	ca: ModuleInfo | null;
}

export interface ProfileResponse {
	state: OnboardingState;
	applied: Array< { module: string; key: string } >;
}

export type ItemState = 'pending' | 'running' | 'ok' | 'error';

export interface RunItem {
	id: string;
	state: ItemState;
	error: string;
}

export interface RunState {
	status: 'idle' | 'running' | 'done';
	items: RunItem[];
	profileError: string;
	profileApplied: number;
	completeError: string;
}

export interface WizardCtx {
	data: OnboardingData;
	hasCa: boolean;
	siteType: string;
	setSiteType: ( id: string ) => void;
	selected: string[];
	toggle: ( id: string ) => void;
	setSelected: ( ids: string[] ) => void;
	profile: Profile;
	setProfile: ( patch: Partial< Profile > ) => void;
	profileError: string;
	connectCa: boolean;
	setConnectCa: ( on: boolean ) => void;
	run: RunState;
	start: () => void;
	next: () => void;
	back: () => void;
	finish: ( to: string, params?: Record< string, string > ) => void;
}

export interface WizardStep {
	id: string;
	title: string;
	render: ( ctx: WizardCtx ) => JSX.Element;
}
