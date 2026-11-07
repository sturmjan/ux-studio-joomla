/**
 * Rich settings widgets used by the generic settings renderer: media picker (Joomla Media Manager in a modal),
 * a small WYSIWYG for rich text (contenteditable, no dependencies) and the repeater (list of sub-field rows).
 * The server sanitises everything again; nothing here is trusted.
 */
import { useEffect, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import { ArrowDown, ArrowUp, Bold, Code, Heading2, Image as ImageIcon, Italic, Link as LinkIcon, List, ListOrdered, Plus, RemoveFormatting, Trash2, Underline } from 'lucide-react';
import { options } from './api';
import { t } from './i18n';
import { Modal } from './ui/Modal';

/* ---- Media picker ---- */

/** Site root path (with trailing slash) derived from the administrator API URL, e.g. "/joomla5/". */
function siteRoot(): string {
	const url = new URL( options().apiUrl, window.location.href );
	return url.pathname.replace( /administrator\/.*$/, '' );
}

function mediaManagerUrl(): string {
	const url = new URL( options().apiUrl, window.location.href );
	return `${ url.origin }${ url.pathname }?option=com_media&view=media&tmpl=component&mediatypes=0,1,2,3&path=local-images:/`;
}

/** "local-images:/a/b.png" (Media Manager) => "images/a/b.png" (what modules store). */
export function joomlaMediaPath( path: string ): string {
	const m = /^local-([^:]+):\/?(.*)$/.exec( path );
	return m ? `${ m[ 1 ] }/${ m[ 2 ] }` : path;
}

interface MediaSelection {
	type?: string;
	path?: string;
	name?: string;
}

const IMAGE_EXT = /\.(png|jpe?g|gif|webp|svg|avif)$/i;

export function MediaField( {
	id,
	label,
	value,
	maxLength,
	onChange,
}: {
	id: string;
	label: string;
	value: string;
	maxLength?: number;
	onChange: ( v: string ) => void;
} ): JSX.Element {
	const [ open, setOpen ] = useState( false );
	const [ picked, setPicked ] = useState< string >( '' );

	useEffect( () => {
		if ( ! open ) {
			return undefined;
		}
		// The Media Manager (iframe, same origin) announces the clicked item on the parent document.
		const onSelected = ( e: Event ): void => {
			const detail = ( e as CustomEvent< MediaSelection > ).detail;
			setPicked( detail && detail.type === 'file' && detail.path ? joomlaMediaPath( detail.path ) : '' );
		};
		document.addEventListener( 'onMediaFileSelected', onSelected );
		return () => document.removeEventListener( 'onMediaFileSelected', onSelected );
	}, [ open ] );

	const close = (): void => {
		setOpen( false );
		setPicked( '' );
	};
	const plain = value.split( '#' )[ 0 ] ?? '';
	const previewable = IMAGE_EXT.test( plain );
	const previewSrc = /^https?:\/\//i.test( plain ) ? plain : `${ siteRoot() }${ plain.replace( /^\//, '' ) }`;

	return (
		<div className="uxs-media">
			<span className="uxs-actions">
				<input type="text" id={ id } maxLength={ maxLength } placeholder="images/…" value={ value } onChange={ ( e ) => onChange( e.target.value ) } />
				<button type="button" className="uxs-btn uxs-btn--small" onClick={ () => setOpen( true ) }>
					<ImageIcon size={ 14 } aria-hidden="true" /> { t( 'Select' ) }
				</button>
				{ value ? (
					<button type="button" className="uxs-link-btn" onClick={ () => onChange( '' ) }>
						{ t( 'Clear' ) }
					</button>
				) : null }
			</span>
			{ value && previewable ? <img className="uxs-media__preview" src={ previewSrc } alt="" loading="lazy" /> : null }
			<Modal
				open={ open }
				onClose={ close }
				title={ label }
				wide
				actions={
					<>
						<span className="uxs-muted uxs-media__picked">{ picked || t( 'Click a file to select it.' ) }</span>
						<button type="button" className="uxs-btn" onClick={ close }>
							{ t( 'Cancel' ) }
						</button>
						<button
							type="button"
							className="uxs-btn uxs-btn--primary"
							disabled={ picked === '' }
							onClick={ () => {
								onChange( picked );
								close();
							} }
						>
							{ t( 'Select' ) }
						</button>
					</>
				}
			>
				<iframe className="uxs-media__frame" title={ t( 'Media Manager' ) } src={ mediaManagerUrl() } />
			</Modal>
		</div>
	);
}

/* ---- Rich text ---- */

function safeUrl( url: string ): boolean {
	return /^(https?:\/\/|mailto:|tel:|\/|#)/i.test( url.trim() );
}

export function RichTextField( { id, label, value, maxLength, onChange }: { id: string; label: string; value: string; maxLength?: number; onChange: ( v: string ) => void } ): JSX.Element {
	const area = useRef< HTMLDivElement >( null );
	const [ source, setSource ] = useState( false );

	useEffect( () => {
		const el = area.current;
		if ( el && el.innerHTML !== value ) {
			el.innerHTML = value;
		}
	}, [ value, source ] );

	const sync = (): void => {
		const el = area.current;
		if ( ! el ) {
			return;
		}
		const html = el.innerHTML;
		onChange( el.textContent === '' && ! el.querySelector( 'img, hr' ) ? '' : html );
	};
	const run = ( command: string, arg?: string ): void => {
		area.current?.focus();
		document.execCommand( command, false, arg );
		sync();
	};
	const link = (): void => {
		const url = window.prompt( t( 'Link address (https://, mailto:, tel: or /path)' ), 'https://' );
		if ( url && safeUrl( url ) ) {
			run( 'createLink', url.trim() );
		}
	};
	const tool = ( name: string, icon: ReactNode, action: () => void ): JSX.Element => (
		<button type="button" className="uxs-rte__btn" aria-label={ name } title={ name } onMouseDown={ ( e ) => e.preventDefault() } onClick={ action }>
			{ icon }
		</button>
	);

	return (
		<div className="uxs-rte">
			<div className="uxs-rte__bar" role="toolbar" aria-label={ label }>
				{ ! source ? (
					<>
						{ tool( t( 'Bold' ), <Bold size={ 15 } aria-hidden="true" />, () => run( 'bold' ) ) }
						{ tool( t( 'Italic' ), <Italic size={ 15 } aria-hidden="true" />, () => run( 'italic' ) ) }
						{ tool( t( 'Underline' ), <Underline size={ 15 } aria-hidden="true" />, () => run( 'underline' ) ) }
						{ tool( t( 'Heading' ), <Heading2 size={ 15 } aria-hidden="true" />, () => run( 'formatBlock', 'h3' ) ) }
						{ tool( t( 'Bulleted list' ), <List size={ 15 } aria-hidden="true" />, () => run( 'insertUnorderedList' ) ) }
						{ tool( t( 'Numbered list' ), <ListOrdered size={ 15 } aria-hidden="true" />, () => run( 'insertOrderedList' ) ) }
						{ tool( t( 'Link' ), <LinkIcon size={ 15 } aria-hidden="true" />, link ) }
						{ tool( t( 'Clear formatting' ), <RemoveFormatting size={ 15 } aria-hidden="true" />, () => run( 'removeFormat' ) ) }
					</>
				) : null }
				<button type="button" className={ `uxs-rte__btn uxs-rte__btn--source${ source ? ' is-active' : '' }` } aria-pressed={ source } title={ t( 'Edit HTML' ) } onClick={ () => setSource( ! source ) }>
					<Code size={ 15 } aria-hidden="true" /> HTML
				</button>
			</div>
			{ source ? (
				<textarea id={ id } rows={ 8 } spellCheck={ false } className="uxs-code-editor" maxLength={ maxLength } value={ value } onChange={ ( e ) => onChange( e.target.value ) } />
			) : (
				<div
					id={ id }
					ref={ area }
					className="uxs-rte__area"
					role="textbox"
					aria-multiline="true"
					aria-label={ label }
					contentEditable
					suppressContentEditableWarning
					onInput={ sync }
					onBlur={ sync }
					onPaste={ ( e ) => {
						// Paste as plain text: no foreign styles or markup from Word / web pages.
						e.preventDefault();
						document.execCommand( 'insertText', false, e.clipboardData.getData( 'text/plain' ) );
					} }
				/>
			) }
		</div>
	);
}

/* ---- Repeater ---- */

export function RepeaterField< Row extends Record< string, unknown > >( {
	label,
	addLabel,
	rows,
	max,
	onChange,
	blank,
	renderRow,
}: {
	label: string;
	addLabel?: string;
	rows: Row[];
	max: number;
	onChange: ( rows: Row[] ) => void;
	blank: () => Row;
	renderRow: ( row: Row, index: number, update: ( patch: Partial< Row > ) => void ) => ReactNode;
} ): JSX.Element {
	const move = ( from: number, to: number ): void => {
		const next = rows.slice();
		const item = next.splice( from, 1 )[ 0 ];
		if ( item !== undefined ) {
			next.splice( to, 0, item );
		}
		onChange( next );
	};
	return (
		<div className="uxs-repeater" role="group" aria-label={ label }>
			{ rows.map( ( row, index ) => (
				<div key={ index } className="uxs-repeater__row">
					<div className="uxs-repeater__fields">{ renderRow( row, index, ( patch ) => onChange( rows.map( ( r, i ) => ( i === index ? { ...r, ...patch } : r ) ) ) ) }</div>
					<div className="uxs-repeater__tools">
						<button type="button" className="uxs-rte__btn" aria-label={ t( 'Move up' ) } title={ t( 'Move up' ) } disabled={ index === 0 } onClick={ () => move( index, index - 1 ) }>
							<ArrowUp size={ 15 } aria-hidden="true" />
						</button>
						<button type="button" className="uxs-rte__btn" aria-label={ t( 'Move down' ) } title={ t( 'Move down' ) } disabled={ index === rows.length - 1 } onClick={ () => move( index, index + 1 ) }>
							<ArrowDown size={ 15 } aria-hidden="true" />
						</button>
						<button type="button" className="uxs-rte__btn" aria-label={ t( 'Remove item' ) } title={ t( 'Remove item' ) } onClick={ () => onChange( rows.filter( ( _, i ) => i !== index ) ) }>
							<Trash2 size={ 15 } aria-hidden="true" />
						</button>
					</div>
				</div>
			) ) }
			<button type="button" className="uxs-btn uxs-btn--small" disabled={ rows.length >= max } onClick={ () => onChange( [ ...rows, blank() ] ) }>
				<Plus size={ 14 } aria-hidden="true" /> { addLabel || t( 'Add item' ) }
			</button>
		</div>
	);
}
