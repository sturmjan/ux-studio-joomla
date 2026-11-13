/**
 * Steps of the setup wizard: welcome, site type, recommended modules, basic settings, Central App (only when the
 * connector module exists) and the summary that turns the chosen modules on. Look and wording follow the WordPress
 * edition (new-site wizard); the module list always comes from the registry, nothing here names a module.
 */
import { useState } from 'react';
import {
	ArrowRight,
	BedDouble,
	Building2,
	ChevronDown,
	CircleAlert,
	CircleCheck,
	Cloud,
	Globe,
	Info,
	ListChecks,
	Mail,
	Newspaper,
	PlugZap,
	Rocket,
	ShieldCheck,
	ShoppingCart,
	Sparkles,
	Undo2,
	Activity,
	Bell,
	Gauge,
	Hourglass,
	Network,
	ShieldAlert,
	ScanEye,
	TriangleAlert,
	Settings as SettingsIcon,
	type LucideIcon,
} from 'lucide-react';
import { t, tn } from '../i18n';
import { groupLabels } from '../ModuleGrid';
import { ModuleIcon } from '../moduleIcons';
import { DrawnCheck, ErrorLine, StateIcon, StepHead } from './parts';
import { allModules, buildPlan, nameOf, profileFields, pulledIn, validEmail } from './plan';
import type { ProfileField, WizardCtx, WizardModule, WizardStep } from './types';

const TYPE_ICONS: Record< string, LucideIcon > = {
	building: Building2,
	'shopping-cart': ShoppingCart,
	bed: BedDouble,
	newspaper: Newspaper,
	sparkles: Sparkles,
};

/* ---- 1 Welcome ---- */

function WelcomeStep( { ctx }: { ctx: WizardCtx } ): JSX.Element {
	const { data } = ctx;
	const enabled = data.modules.filter( ( m ) => m.enabled ).length;
	const points = [
		{ icon: <ListChecks size={ 18 } aria-hidden="true" />, text: t( 'You see every module first and choose what you want. Nothing is turned on without your tick.' ) },
		{ icon: <ShieldCheck size={ 18 } aria-hidden="true" />, text: t( 'The choice is only a recommendation for the type of your website. You can turn every module on or off later on the Modules screen.' ) },
		{ icon: <Undo2 size={ 18 } aria-hidden="true" />, text: t( 'Turning a module off never deletes its data. You can run this wizard again any time.' ) },
	];
	return (
		<section className="uxs-wiz-panel">
			<StepHead icon={ <Rocket size={ 26 } aria-hidden="true" /> } title={ t( 'Set up your website' ) }>
				{ t( 'UX1 Studio can turn on the modules that suit your kind of website and preset the basics in a few minutes.' ) }
			</StepHead>
			<ul className="uxs-wiz-points">
				{ points.map( ( p ) => (
					<li key={ p.text }>
						{ p.icon }
						<span>{ p.text }</span>
					</li>
				) ) }
			</ul>
			<dl className="uxs-wiz-facts">
				<div>
					<dt>{ t( 'Site name' ) }</dt>
					<dd>{ data.site.name !== '' ? data.site.name : '-' }</dd>
				</div>
				<div>
					<dt>{ t( 'Address' ) }</dt>
					<dd>{ data.site.url }</dd>
				</div>
				<div>
					<dt>{ t( 'Modules' ) }</dt>
					<dd>{ t( '{total} available, {on} already on', { total: data.modules.length, on: enabled } ) }</dd>
				</div>
			</dl>
			<div className="uxs-wiz-actions">
				<button type="button" className="uxs-btn uxs-btn--primary" onClick={ ctx.next }>
					{ t( 'Choose the type of the website' ) } <ArrowRight size={ 16 } aria-hidden="true" />
				</button>
			</div>
		</section>
	);
}

/* ---- 2 Site type ---- */

function TypeStep( { ctx }: { ctx: WizardCtx } ): JSX.Element {
	return (
		<section className="uxs-wiz-panel">
			<StepHead icon={ <Globe size={ 26 } aria-hidden="true" /> } title={ t( 'What kind of website is this?' ) }>
				{ t( 'The type decides which modules are recommended in the next step. You can change the selection there.' ) }
			</StepHead>
			<ul className="uxs-wiz-types" role="radiogroup" aria-label={ t( 'Type of the website' ) }>
				{ ctx.data.site_types.map( ( type ) => {
					const Icon = TYPE_ICONS[ type.icon ] ?? Sparkles;
					return (
						<li key={ type.id }>
							<button type="button" role="radio" aria-checked={ ctx.siteType === type.id } className="uxs-wiz-type" onClick={ () => ctx.setSiteType( type.id ) }>
								<span className="uxs-wiz-sourceicon">
									<Icon size={ 22 } aria-hidden="true" />
								</span>
								<span>
									<strong>{ type.label }</strong>
									<span className="uxs-wiz-muted">{ type.description }</span>
								</span>
							</button>
						</li>
					);
				} ) }
			</ul>
			<div className="uxs-wiz-actions">
				<button type="button" className="uxs-btn" onClick={ ctx.back }>
					{ t( 'Back' ) }
				</button>
				<button type="button" className="uxs-btn uxs-btn--primary" disabled={ ctx.siteType === '' } onClick={ ctx.next }>
					{ t( 'Recommended modules' ) } <ArrowRight size={ 16 } aria-hidden="true" />
				</button>
			</div>
		</section>
	);
}

/* ---- 3 Modules ---- */

function ModuleCard( { m, ctx, recommended }: { m: WizardModule; ctx: WizardCtx; recommended: boolean } ): JSX.Element {
	const checked = m.enabled || ctx.selected.includes( m.id );
	const missing = m.requires.filter( ( id ) => ! allModules( ctx.data ).find( ( x ) => x.id === id )?.enabled );
	const pulls = checked && ! m.enabled && missing.length > 0;
	return (
		<li className={ `uxs-wiz-choice${ checked && ! m.enabled ? ' is-selected' : '' }${ m.enabled ? ' is-disabled' : '' }` }>
			<div className="uxs-wiz-choice__row">
				<button type="button" role="checkbox" aria-checked={ checked } aria-label={ m.name } className="uxs-wiz-tick" disabled={ m.enabled } onClick={ () => ctx.toggle( m.id ) }>
					{ checked && <DrawnCheck size={ 16 } /> }
				</button>
				<span className="uxs-wiz-sourceicon">
					<ModuleIcon name={ m.icon } moduleId={ m.id } size={ 22 } />
				</span>
				<div className="uxs-wiz-choice__body">
					<div className="uxs-wiz-choice__head">
						<strong>{ m.name }</strong>
					</div>
					<p className="uxs-wiz-muted">{ m.description }</p>
					<div className="uxs-wiz-badges">
						{ m.enabled && <span className="uxs-badge is-success">{ t( 'Already on' ) }</span> }
						{ recommended && ! m.enabled && <span className="uxs-badge is-info">{ t( 'Recommended' ) }</span> }
					</div>
					{ pulls && (
						<p className="uxs-wiz-partial">
							<Info size={ 15 } aria-hidden="true" />
							<span>{ t( 'This module also turns on: {modules}', { modules: missing.map( ( id ) => nameOf( ctx.data, id ) ).join( ', ' ) } ) }</span>
						</p>
					) }
				</div>
			</div>
		</li>
	);
}

function ModulesStep( { ctx }: { ctx: WizardCtx } ): JSX.Element {
	const [ more, setMore ] = useState( false );
	const { data } = ctx;
	const typeLabel = data.site_types.find( ( s ) => s.id === ctx.siteType )?.label ?? '';
	const recommended = data.modules.filter( ( m ) => m.types.includes( ctx.siteType ) );
	const others = data.modules.filter( ( m ) => ! m.types.includes( ctx.siteType ) );
	const labels = groupLabels();
	const othersSelected = others.filter( ( m ) => ctx.selected.includes( m.id ) ).length;
	const tickable = recommended.filter( ( m ) => ! m.enabled ).map( ( m ) => m.id );

	return (
		<section className="uxs-wiz-panel">
			<StepHead icon={ <ListChecks size={ 26 } aria-hidden="true" /> } title={ t( 'Recommended modules' ) }>
				{ t( 'These modules suit this type of website: {type}. Untick what you do not want, tick what you miss.', { type: typeLabel } ) }
			</StepHead>

			<div className="uxs-wiz-bulk">
				<button type="button" className="uxs-wiz-linkbtn" onClick={ () => ctx.setSelected( tickable ) }>
					{ t( 'Select the recommended' ) }
				</button>
				<button type="button" className="uxs-wiz-linkbtn" onClick={ () => ctx.setSelected( [] ) }>
					{ t( 'Clear selection' ) }
				</button>
			</div>

			{ recommended.length > 0 ? (
				<ul className="uxs-wiz-choices">
					{ recommended.map( ( m ) => (
						<ModuleCard key={ m.id } m={ m } ctx={ ctx } recommended />
					) ) }
				</ul>
			) : (
				<p className="uxs-wiz-muted">{ t( 'No module is specially recommended for this type. You can pick modules below.' ) }</p>
			) }

			{ others.length > 0 && (
				<div className="uxs-wiz-packgroup">
					<h3 className="uxs-wiz-packgroup__title">
						<button type="button" className="uxs-wiz-linkbtn is-inline" aria-expanded={ more } onClick={ () => setMore( ! more ) }>
							{ t( 'More modules' ) } ({ othersSelected }/{ others.length })
							<ChevronDown size={ 15 } aria-hidden="true" className={ more ? 'is-open' : '' } />
						</button>
					</h3>
					{ more && (
						<ul className="uxs-wiz-choices">
							{ others.map( ( m ) => (
								<ModuleCard key={ m.id } m={ m } ctx={ ctx } recommended={ false } />
							) ) }
						</ul>
					) }
					{ more && (
						<p className="uxs-wiz-muted">{ Array.from( new Set( others.map( ( m ) => labels[ m.group ] ?? t( 'Other' ) ) ) ).join(' · ') }</p>
					) }
				</div>
			) }

			<div className="uxs-wiz-actions">
				<button type="button" className="uxs-btn" onClick={ ctx.back }>
					{ t( 'Back' ) }
				</button>
				<button type="button" className="uxs-btn uxs-btn--primary" onClick={ ctx.next }>
					{ ctx.selected.length === 0 ? t( 'Continue without modules' ) : tn( 'Continue with {n} module', 'Continue with {n} modules', ctx.selected.length ) } <ArrowRight size={ 16 } aria-hidden="true" />
				</button>
			</div>
		</section>
	);
}

/* ---- 4 Basic settings ---- */

function SettingsStep( { ctx }: { ctx: WizardCtx } ): JSX.Element {
	const { data, profile } = ctx;
	const [ touched, setTouched ] = useState( false );
	const rows: Array< { field: ProfileField; label: string; help: string; type: 'email' | 'text'; placeholder: string } > = [
		{ field: 'sender_email', label: t( 'Sender e-mail address' ), help: t( 'The address the website sends e-mails from (forms, password reset, notifications).' ), type: 'email', placeholder: data.defaults.sender_email },
		{ field: 'sender_name', label: t( 'Sender name' ), help: t( 'The name recipients see next to the address, usually the name of the website or company.' ), type: 'text', placeholder: data.defaults.sender_name },
		{ field: 'contact_email', label: t( 'Contact e-mail' ), help: t( 'The default address for alerts and notifications. Each module still has its own switch for sending them.' ), type: 'email', placeholder: data.defaults.contact_email },
	];
	const invalid = profileFields().filter( ( f ) => f !== 'sender_name' && ! validEmail( profile[ f ] ) );
	const usedBy = ( f: ProfileField ): string => data.profile_targets[ f ].map( ( id ) => nameOf( data, id ) ).join( ', ' );

	return (
		<section className="uxs-wiz-panel">
			<StepHead icon={ <SettingsIcon size={ 26 } aria-hidden="true" /> } title={ t( 'Basic settings' ) }>
				{ t( 'Fill in what the modules need most often. All of it is optional and you can change it later in the settings of each module.' ) }
			</StepHead>
			<div className="uxs-form uxs-form--grid">
				{ rows.map( ( row ) => {
					const used = usedBy( row.field );
					const bad = touched && invalid.includes( row.field );
					return (
						<div className="uxs-form__row" key={ row.field }>
							<label htmlFor={ `uxs-wiz-${ row.field }` }>{ row.label }</label>
							<input
								id={ `uxs-wiz-${ row.field }` }
								type={ row.type }
								value={ profile[ row.field ] }
								placeholder={ row.placeholder }
								maxLength={ row.type === 'email' ? 190 : 120 }
								aria-invalid={ bad }
								onChange={ ( e ) => ctx.setProfile( { [ row.field ]: e.target.value } ) }
							/>
							<p className="uxs-form__help">
								{ row.help }{ ' ' }
								{ used !== '' ? t( 'Used by: {modules}.', { modules: used } ) : t( 'No installed module uses it yet; it is kept for later.' ) }
							</p>
							{ bad && (
								<p className="uxs-wiz-error-text" role="alert">
									{ t( 'Enter a valid e-mail address.' ) }
								</p>
							) }
						</div>
					);
				} ) }
			</div>
			<p className="uxs-wiz-muted">{ t( 'A value you have already set in a module is never overwritten.' ) }</p>
			<div className="uxs-wiz-actions">
				<button type="button" className="uxs-btn" onClick={ ctx.back }>
					{ t( 'Back' ) }
				</button>
				<button
					type="button"
					className="uxs-btn uxs-btn--primary"
					onClick={ () => {
						setTouched( true );
						if ( invalid.length === 0 ) {
							ctx.next();
						}
					} }
				>
					{ t( 'Continue' ) } <ArrowRight size={ 16 } aria-hidden="true" />
				</button>
			</div>
		</section>
	);
}

/* ---- 5 Central App ---- */

function HeroArt(): JSX.Element {
	return (
		<div className="uxs-wiz-ca__art" aria-hidden="true">
			<svg viewBox="0 0 200 150" className="uxs-wiz-ca__lines" fill="none">
				<path d="M100 75 L34 34 M100 75 L168 30 M100 75 L30 116 M100 75 L170 112" />
			</svg>
			<span className="uxs-wiz-ca__hub">
				<Cloud size={ 34 } aria-hidden="true" />
			</span>
			<span className="uxs-wiz-ca__node is-a"><Globe size={ 16 } aria-hidden="true" /></span>
			<span className="uxs-wiz-ca__node is-b"><Globe size={ 16 } aria-hidden="true" /></span>
			<span className="uxs-wiz-ca__node is-c"><Globe size={ 16 } aria-hidden="true" /></span>
			<span className="uxs-wiz-ca__node is-d"><Globe size={ 16 } aria-hidden="true" /></span>
		</div>
	);
}

function caFeatures(): Array< { id: string; icon: LucideIcon; title: string; text: string } > {
	return [
		{ id: 'uptime', icon: Activity, title: t( 'Availability' ), text: t( 'Checks the site regularly, alerts you when it goes down and when it is back, and keeps the history of uptime and response time. It also tracks when the SSL certificate and the domain expire.' ) },
		{ id: 'vulnerabilities', icon: ShieldAlert, title: t( 'Known vulnerabilities' ), text: t( 'Compares the extensions, templates and the Joomla version of the site with a vulnerability database and warns you when something installed is affected.' ) },
		{ id: 'php', icon: Hourglass, title: t( 'Outdated PHP' ), text: t( 'Shows which PHP version the site runs on and warns you when it no longer gets security updates or has a critical vulnerability.' ) },
		{ id: 'speed', icon: Gauge, title: t( 'Speed and SEO' ), text: t( 'Measures the speed with Google PageSpeed regularly and keeps the history. A public SEO test and audit check the basics of search engine optimisation.' ) },
		{ id: 'quality', icon: ScanEye, title: t( 'Looks and accessibility' ), text: t( 'Takes screenshots of key pages every day and compares them, so a broken layout does not go unnoticed. Once a month it scans the site for accessibility problems.' ) },
		{ id: 'alerts', icon: Bell, title: t( 'Alerts and reports' ), text: t( 'Alerts arrive by e-mail, Discord, Slack or as a notification on your phone or computer, with a daily digest if you prefer fewer messages.' ) },
		{ id: 'shared', icon: Network, title: t( 'Shared protection' ), text: t( 'Ban an IP address, a range or a whole country once and it is blocked on every connected site.' ) },
	];
}

function CaStep( { ctx }: { ctx: WizardCtx } ): JSX.Element {
	const ca = ctx.data.ca;
	const already = !! ca?.enabled;
	const how: Array< [ string, string ] > = [
		[ t( 'Connect' ), t( 'One click on the connection page. The site proves who it is with a secret key.' ) ],
		[ t( 'Sign in or create an account' ), t( 'You land in the central app, log in or sign up and confirm the connection.' ) ],
		[ t( 'Done, the site is watched' ), t( 'You come back here and the central app starts keeping an eye on the site.' ) ],
	];
	return (
		<section className="uxs-wiz-panel uxs-wiz-ca">
			<div className="uxs-wiz-ca__hero">
				<div className="uxs-wiz-ca__herotext">
					<span className="uxs-wiz-ca__eyebrow">
						<PlugZap size={ 16 } aria-hidden="true" /> { t( 'Optional step' ) }
					</span>
					<h2>{ t( 'Central app' ) }</h2>
					<p>{ t( 'One place to watch over all your websites. Connect this one and the central app keeps an eye on it for you: whether it runs, whether it is safe and fast, and whether anything is about to expire.' ) }</p>
				</div>
				<HeroArt />
			</div>

			{ already && (
				<p className="uxs-wiz-banner is-ok" role="status">
					<CircleCheck size={ 16 } aria-hidden="true" /> { t( 'The connector module is already on. Open its page after the setup to check or finish the connection.' ) }
				</p>
			) }

			<h3 className="uxs-wiz-ca__h">{ t( 'How it works' ) }</h3>
			<ol className="uxs-wiz-ca__how">
				{ how.map( ( [ title, text ], i ) => (
					<li key={ title }>
						<span className="uxs-wiz-ca__num">{ i + 1 }</span>
						<div>
							<strong>{ title }</strong>
							<p>{ text }</p>
						</div>
					</li>
				) ) }
			</ol>

			<h3 className="uxs-wiz-ca__h">{ t( 'What you get' ) }</h3>
			<ul className="uxs-wiz-ca__grid">
				{ caFeatures().map( ( f ) => (
					<li key={ f.id } className="uxs-wiz-ca__card">
						<span className="uxs-wiz-ca__cardicon">
							<f.icon size={ 20 } aria-hidden="true" />
						</span>
						<h4>{ f.title }</h4>
						<p>{ f.text }</p>
					</li>
				) ) }
			</ul>

			<div className="uxs-wiz-ca__changes">
				<h3>{ t( 'What changes on your site' ) }</h3>
				<ul>
					<li>
						<Info size={ 18 } aria-hidden="true" />
						<span>{ t( 'The wizard turns on the connector module. It creates a secret key that only this site and the central app share.' ) }</span>
					</li>
					<li>
						<Info size={ 18 } aria-hidden="true" />
						<span>{ t( 'The connection itself is made on the connector page after the setup, with one more click, and you can cancel it there.' ) }</span>
					</li>
					<li>
						<Info size={ 18 } aria-hidden="true" />
						<span>{ t( 'The site must be reachable from the internet: the central app checks it while connecting.' ) }</span>
					</li>
				</ul>
			</div>

			<div className="uxs-wiz-ca__bar" role="group" aria-label={ t( 'Connect or skip' ) }>
				<span className="uxs-wiz-ca__barhint">{ t( 'You can also connect later.' ) }</span>
				<div className="uxs-wiz-ca__barbtns">
					<button type="button" className="uxs-btn" onClick={ ctx.back }>
						{ t( 'Back' ) }
					</button>
					<button
						type="button"
						className="uxs-btn"
						onClick={ () => {
							ctx.setConnectCa( false );
							ctx.next();
						} }
					>
						{ t( 'Skip' ) }
					</button>
					<button
						type="button"
						className="uxs-btn uxs-btn--primary"
						onClick={ () => {
							ctx.setConnectCa( true );
							ctx.next();
						} }
					>
						{ already ? t( 'Continue and open the connection' ) : t( 'Turn on and connect' ) } <ArrowRight size={ 16 } aria-hidden="true" />
					</button>
				</div>
			</div>
		</section>
	);
}

/* ---- 6 Summary, apply, done ---- */

function SummaryReview( { ctx }: { ctx: WizardCtx } ): JSX.Element {
	const { data, profile } = ctx;
	const plan = buildPlan( data, ctx.selected, ctx.connectCa );
	const extra = pulledIn( data, ctx.selected, plan );
	const regular = plan.filter( ( id ) => id !== data.ca?.id );
	const typeLabel = data.site_types.find( ( s ) => s.id === ctx.siteType )?.label ?? '';
	const filled = profileFields().filter( ( f ) => profile[ f ].trim() !== '' );
	const labels: Record< ProfileField, string > = { sender_email: t( 'Sender e-mail address' ), sender_name: t( 'Sender name' ), contact_email: t( 'Contact e-mail' ) };

	return (
		<section className="uxs-wiz-panel">
			<StepHead icon={ <ListChecks size={ 26 } aria-hidden="true" /> } title={ t( 'Summary' ) }>
				{ t( 'Check what is going to happen. Nothing has been changed yet.' ) }
			</StepHead>
			<div className="uxs-wiz-summary">
				<div className="uxs-wiz-box">
					<h3>{ t( 'Type of the website' ) }</h3>
					<p>{ typeLabel }</p>
				</div>
				<div className="uxs-wiz-box">
					<h3>{ t( 'Modules to turn on' ) }</h3>
					{ regular.length === 0 ? (
						<p className="uxs-wiz-muted">{ t( 'No module will be turned on.' ) }</p>
					) : (
						<ul className="uxs-wiz-plain">
							{ regular.map( ( id ) => (
								<li key={ id }>
									<ModuleIcon name={ allModules( data ).find( ( m ) => m.id === id )?.icon ?? 'puzzle' } moduleId={ id } size={ 16 } />
									<span>
										{ nameOf( data, id ) }
										{ extra.includes( id ) ? ` (${ t( 'required by another module' ) })` : '' }
									</span>
								</li>
							) ) }
						</ul>
					) }
				</div>
				<div className="uxs-wiz-box">
					<h3>{ t( 'Basic settings' ) }</h3>
					{ filled.length === 0 ? (
						<p className="uxs-wiz-muted">{ t( 'Nothing filled in.' ) }</p>
					) : (
						<ul className="uxs-wiz-plain">
							{ filled.map( ( f ) => (
								<li key={ f }>
									<Mail size={ 16 } aria-hidden="true" />
									<span>
										{ labels[ f ] }: <code>{ profile[ f ].trim() }</code>
									</span>
								</li>
							) ) }
						</ul>
					) }
				</div>
				{ ctx.hasCa && (
					<div className="uxs-wiz-box">
						<h3>{ t( 'Central app' ) }</h3>
						<p className="uxs-wiz-muted">
							{ ctx.connectCa ? t( 'The connector is turned on and the connection page opens when the setup is done.' ) : t( 'Not connected now. You can do it later in the connector module.' ) }
						</p>
					</div>
				) }
			</div>
			<div className="uxs-wiz-actions">
				<button type="button" className="uxs-btn" onClick={ ctx.back }>
					{ t( 'Back' ) }
				</button>
				<button type="button" className="uxs-btn uxs-btn--primary" onClick={ ctx.start }>
					<Rocket size={ 16 } aria-hidden="true" /> { t( 'Set up the website' ) }
				</button>
			</div>
		</section>
	);
}

function SummaryRun( { ctx }: { ctx: WizardCtx } ): JSX.Element {
	const { data, run } = ctx;
	const done = run.status === 'done';
	const okCount = run.items.filter( ( i ) => i.state === 'ok' ).length;
	const failed = run.items.filter( ( i ) => i.state === 'error' ).length + ( run.profileError !== '' ? 1 : 0 ) + ( run.completeError !== '' ? 1 : 0 );

	return (
		<section className="uxs-wiz-panel">
			<StepHead icon={ <PlugZap size={ 26 } aria-hidden="true" /> } title={ done ? t( 'Setup finished' ) : t( 'Setting up…' ) }>
				{ t( 'Please keep this page open. Each module is turned on on its own, so a problem in one does not stop the others.' ) }
			</StepHead>
			<ul className="uxs-wiz-progress">
				{ run.items.map( ( item ) => {
					const percent = item.state === 'ok' || item.state === 'error' ? 100 : item.state === 'running' ? 55 : 0;
					const text = item.state === 'error' ? item.error : item.state === 'ok' ? t( 'Done' ) : item.state === 'running' ? t( 'Working…' ) : t( 'Waiting' );
					return (
						<li key={ item.id } className={ `uxs-wiz-prow is-${ item.state }` }>
							<StateIcon state={ item.state } />
							<div className="uxs-wiz-prow__body">
								<strong>{ nameOf( data, item.id ) }</strong>
								<span className={ item.state === 'error' ? 'uxs-wiz-error-text' : 'uxs-wiz-muted' }>{ text }</span>
							</div>
							<span className="uxs-wiz-bar" role="progressbar" aria-valuemin={ 0 } aria-valuemax={ 100 } aria-valuenow={ percent } aria-label={ nameOf( data, item.id ) }>
								<span className={ item.state === 'error' ? 'is-error' : '' } style={ { width: `${ percent }%` } } />
							</span>
						</li>
					);
				} ) }
			</ul>
			<ErrorLine text={ run.profileError } />
			<ErrorLine text={ run.completeError } />
			{ done && (
				<p className={ `uxs-wiz-banner ${ failed === 0 ? 'is-ok' : 'is-bad' }` } role="status">
					{ failed === 0 ? <CircleCheck size={ 16 } aria-hidden="true" /> : <CircleAlert size={ 16 } aria-hidden="true" /> }
					{ t( '{ok} turned on, {failed} failed.', { ok: okCount, failed } ) }
				</p>
			) }
		</section>
	);
}

function DonePanel( { ctx }: { ctx: WizardCtx } ): JSX.Element {
	const { data, run } = ctx;
	const okIds = run.items.filter( ( i ) => i.state === 'ok' ).map( ( i ) => i.id );
	const failed = run.items.filter( ( i ) => i.state === 'error' );
	const caId = data.ca?.id ?? '';
	const caReady = ctx.connectCa && caId !== '' && ( okIds.includes( caId ) || !! data.ca?.enabled );
	const links = okIds.filter( ( id ) => id !== caId );
	const iconOf = ( id: string ): string => allModules( data ).find( ( m ) => m.id === id )?.icon ?? 'puzzle';

	return (
		<section className="uxs-wiz-panel uxs-wiz-finish">
			<span className="uxs-wiz-finish__badge">
				<DrawnCheck size={ 34 } />
			</span>
			<h2>{ t( 'The website is set up' ) }</h2>
			<p>{ tn( '{n} module was turned on.', '{n} modules were turned on.', okIds.length ) }</p>

			<div className="uxs-wiz-next">
				<h3>{ t( 'Summary' ) }</h3>
				<ul>
					{ failed.map( ( i ) => (
						<li key={ i.id }>
							<TriangleAlert size={ 14 } aria-hidden="true" /> <strong>{ nameOf( data, i.id ) }</strong>: { i.error }
						</li>
					) ) }
					{ run.profileError !== '' && <li>{ run.profileError }</li> }
					{ run.profileApplied > 0 && <li>{ tn( 'The basic settings were filled into {n} module setting.', 'The basic settings were filled into {n} module settings.', run.profileApplied ) }</li> }
					{ ctx.hasCa && <li>{ caReady ? t( 'The connector is ready. Finish the connection on its page.' ) : t( 'The website is not connected to the central app. You can do it later in the connector module.' ) }</li> }
					<li>{ t( 'You can run this wizard again from the Modules screen.' ) }</li>
				</ul>
			</div>

			{ links.length > 0 && (
				<div className="uxs-wiz-next">
					<h3>{ t( 'Open a module' ) }</h3>
					<div className="uxs-wiz-modulelinks">
						{ links.map( ( id ) => (
							<button key={ id } type="button" className="uxs-btn uxs-btn--small" onClick={ () => ctx.finish( 'module', { id } ) }>
								<ModuleIcon name={ iconOf( id ) } moduleId={ id } size={ 16 } /> { nameOf( data, id ) }
							</button>
						) ) }
					</div>
				</div>
			) }

			<div className="uxs-wiz-actions is-center">
				{ caReady && (
					<button type="button" className="uxs-btn uxs-btn--primary" onClick={ () => ctx.finish( 'module', { id: caId } ) }>
						<Cloud size={ 16 } aria-hidden="true" /> { t( 'Open the connection page' ) }
					</button>
				) }
				<button type="button" className={ caReady ? 'uxs-btn' : 'uxs-btn uxs-btn--primary' } onClick={ () => ctx.finish( 'modules' ) }>
					{ t( 'Back to modules' ) }
				</button>
			</div>
		</section>
	);
}

function SummaryStep( { ctx }: { ctx: WizardCtx } ): JSX.Element {
	if ( ctx.run.status === 'idle' ) {
		return <SummaryReview ctx={ ctx } />;
	}
	if ( ctx.run.status === 'done' ) {
		return (
			<>
				<DonePanel ctx={ ctx } />
				<details className="uxs-wiz-muted" style={ { marginTop: 'var(--uxs-sp-4)' } }>
					<summary>{ t( 'Show the details' ) }</summary>
					<SummaryRun ctx={ ctx } />
				</details>
			</>
		);
	}
	return <SummaryRun ctx={ ctx } />;
}

/** Ordered steps. The Central App step exists only when the connector module is installed (decided by the shell). */
export function getSteps( withCa: boolean ): WizardStep[] {
	const steps: WizardStep[] = [
		{ id: 'welcome', title: t( 'Welcome' ), render: ( ctx ) => <WelcomeStep ctx={ ctx } /> },
		{ id: 'type', title: t( 'Type of website' ), render: ( ctx ) => <TypeStep ctx={ ctx } /> },
		{ id: 'modules', title: t( 'Modules' ), render: ( ctx ) => <ModulesStep ctx={ ctx } /> },
		{ id: 'settings', title: t( 'Basic settings' ), render: ( ctx ) => <SettingsStep ctx={ ctx } /> },
	];
	if ( withCa ) {
		steps.push( { id: 'ca', title: t( 'Central app' ), render: ( ctx ) => <CaStep ctx={ ctx } /> } );
	}
	steps.push( { id: 'summary', title: t( 'Summary' ), render: ( ctx ) => <SummaryStep ctx={ ctx } /> } );
	return steps;
}
