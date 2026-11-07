/**
 * Pixel & Tag Manager screen: Overview (state of each tag, consent warnings, hosts for a CSP) + Settings.
 */
import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { CircleAlert, CircleCheck, CircleDashed, ShieldAlert } from 'lucide-react';
import { api, type ModuleInfo } from '../../app/api';
import { createT } from '../../app/i18n';
import { SettingsPanel } from '../../app/SettingsForm';
import { Loading, TabPanel, Tabs } from '../../app/ui';

const { t } = createT( 'pixel-tag-manager' );

interface Provider {
	key: string;
	label: string;
	category: 'analytics' | 'marketing';
	id: string;
	state: 'active' | 'invalid' | 'unset';
}

interface Status {
	providers: Provider[];
	consent_mode: 'uxstudio' | 'cookie' | 'off';
	detected: string[];
	hosts: string[];
	active: number;
	bad_cookie: Array< 'analytics' | 'marketing' >;
}

function StateBadge( { state }: { state: Provider[ 'state' ] } ): JSX.Element {
	if ( state === 'active' ) {
		return (
			<span className="uxs-badge" style={ { color: 'var(--uxs-success)' } }>
				<CircleCheck size={ 14 } aria-hidden="true" /> { t( 'Active' ) }
			</span>
		);
	}
	if ( state === 'invalid' ) {
		return (
			<span className="uxs-badge" style={ { color: 'var(--uxs-danger)' } }>
				<CircleAlert size={ 14 } aria-hidden="true" /> { t( 'Invalid ID' ) }
			</span>
		);
	}
	return (
		<span className="uxs-badge uxs-muted">
			<CircleDashed size={ 14 } aria-hidden="true" /> { t( 'Not set' ) }
		</span>
	);
}

function Overview( { onSettings }: { onSettings: () => void } ): JSX.Element {
	const q = useQuery( { queryKey: [ 'pixel-tag-manager', 'status' ], queryFn: () => api.get< Status >( 'pixel-tag-manager/status' ) } );
	if ( q.isLoading ) {
		return <Loading />;
	}
	if ( q.error || ! q.data ) {
		return <p role="alert">{ q.error instanceof Error ? q.error.message : t( 'Something went wrong.' ) }</p>;
	}
	const s = q.data;
	const invalid = s.providers.filter( ( p ) => p.state === 'invalid' );

	return (
		<>
			{ s.consent_mode === 'off' && s.active > 0 ? (
				<div className="uxs-notice is-error" role="alert">
					<p>
						<ShieldAlert size={ 16 } aria-hidden="true" /> <strong>{ t( 'Tags load without consent.' ) }</strong>{ ' ' }
						{ t( 'Visitors are tracked before they agree. Under the cookie rules (GDPR, ePrivacy) this is not allowed for analytics and advertising tags.' ) }
					</p>
					<button type="button" className="uxs-btn uxs-btn--small" onClick={ onSettings }>
						{ t( 'Change consent settings' ) }
					</button>
				</div>
			) : null }

			{ s.consent_mode !== 'off' && s.detected.length === 0 ? (
				<div className="uxs-notice">
					<p>
						<ShieldAlert size={ 16 } aria-hidden="true" /> <strong>{ t( 'No consent solution found on this site.' ) }</strong>{ ' ' }
						{ s.consent_mode === 'uxstudio'
							? t( 'The tags stay off until a consent tool stores the visitor decision in the cookie "uxs_consent". Install a cookie consent tool, or switch to the cookie of the tool you use.' )
							: t( 'The tags load only when the configured consent cookie is present. Make sure a cookie consent tool sets it.' ) }
					</p>
				</div>
			) : null }

			{ s.bad_cookie.length > 0 ? (
				<div className="uxs-notice is-error" role="alert">
					<p>
						<strong>{ t( 'The consent cookie name is missing or invalid, so these tags never load:' ) }</strong>{ ' ' }
						{ s.bad_cookie.map( ( c ) => ( c === 'analytics' ? t( 'Analytics' ) : t( 'Marketing' ) ) ).join( ', ' ) }
					</p>
					<button type="button" className="uxs-btn uxs-btn--small" onClick={ onSettings }>
						{ t( 'Change consent settings' ) }
					</button>
				</div>
			) : null }

			{ invalid.length > 0 ? (
				<div className="uxs-notice is-error" role="alert">
					<p>
						<strong>{ t( 'Some IDs have a wrong format and are ignored:' ) }</strong> { invalid.map( ( p ) => p.label ).join( ', ' ) }
					</p>
				</div>
			) : null }

			<div className="uxs-table-wrap">
				<table className="uxs-table">
					<thead>
						<tr>
							<th>{ t( 'Tag' ) }</th>
							<th>{ t( 'ID' ) }</th>
							<th>{ t( 'Consent category' ) }</th>
							<th>{ t( 'State' ) }</th>
						</tr>
					</thead>
					<tbody>
						{ s.providers.map( ( p ) => (
							<tr key={ p.key }>
								<td>{ p.label }</td>
								<td>
									<code>{ p.id || '-' }</code>
								</td>
								<td>{ p.category === 'analytics' ? t( 'Analytics' ) : t( 'Marketing' ) }</td>
								<td>
									<StateBadge state={ p.state } />
								</td>
							</tr>
						) ) }
					</tbody>
				</table>
			</div>

			<div className="uxs-card" style={ { alignItems: 'flex-start', textAlign: 'left' } }>
				<strong>{ t( 'Consent' ) }</strong>
				<span className="uxs-muted">
					{ s.consent_mode === 'uxstudio' ? t( 'UX Studio consent (cookie "uxs_consent")' ) : s.consent_mode === 'cookie' ? t( 'Cookie of another consent tool' ) : t( 'No consent required (not recommended)' ) }
				</span>
				<span className="uxs-muted">{ s.detected.length > 0 ? t( 'Detected on this site: {list}', { list: s.detected.join( ', ' ) } ) : t( 'No consent tool detected.' ) }</span>
			</div>

			{ s.hosts.length > 0 ? (
				<div className="uxs-card" style={ { alignItems: 'flex-start', textAlign: 'left' } }>
					<strong>{ t( 'Content Security Policy' ) }</strong>
					<span className="uxs-muted">{ t( 'The loader is a normal script file with the page nonce. If your site sends a strict CSP, allow these hosts in script-src, img-src and connect-src:' ) }</span>
					<code style={ { wordBreak: 'break-word' } }>{ s.hosts.join( ' ' ) }</code>
				</div>
			) : null }
		</>
	);
}

export default function Page( { module }: { module: ModuleInfo } ): JSX.Element {
	const [ tab, setTab ] = useState( 'overview' );
	return (
		<>
			<Tabs
				label={ module.name }
				active={ tab }
				onChange={ setTab }
				items={ [
					{ id: 'overview', label: t( 'Overview' ) },
					{ id: 'settings', label: t( 'Settings' ) },
				] }
			/>
			{ tab === 'overview' ? (
				<TabPanel id="overview">
					<Overview onSettings={ () => setTab( 'settings' ) } />
				</TabPanel>
			) : (
				<TabPanel id="settings">
					<SettingsPanel moduleId={ module.id } />
				</TabPanel>
			) }
		</>
	);
}
