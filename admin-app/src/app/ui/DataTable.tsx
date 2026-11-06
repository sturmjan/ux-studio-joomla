import { useMemo, useState } from 'react';
import type { ReactNode } from 'react';
import { ArrowDown, ArrowUp } from 'lucide-react';
import { t } from '../i18n';

export interface Column< Row > {
	key: string;
	header: string;
	/** Cell content; default: String(row[key]). */
	render?: ( row: Row ) => ReactNode;
	/** Enables click-to-sort on this column (compares row[key]). */
	sortable?: boolean;
}

/**
 * Simple table with optional client-side sorting and paging. For server-side paging pass `page`/`pages`/`onPage`.
 */
export function DataTable< Row extends Record< string, unknown > >( {
	columns,
	rows,
	rowKey,
	empty,
	pageSize = 0,
	page,
	pages,
	onPage,
}: {
	columns: Column< Row >[];
	rows: Row[];
	rowKey: ( row: Row ) => string | number;
	empty?: string;
	/** Client-side page size (0 = no paging). */
	pageSize?: number;
	/** Controlled (server-side) paging. */
	page?: number;
	pages?: number;
	onPage?: ( page: number ) => void;
} ): JSX.Element {
	const [ sort, setSort ] = useState< { key: string; dir: 1 | -1 } | null >( null );
	const [ localPage, setLocalPage ] = useState( 1 );

	const sorted = useMemo( () => {
		if ( ! sort ) {
			return rows;
		}
		return [ ...rows ].sort( ( a, b ) => {
			const x = a[ sort.key ];
			const y = b[ sort.key ];
			if ( x === y ) {
				return 0;
			}
			return ( String( x ?? '' ) < String( y ?? '' ) ? -1 : 1 ) * sort.dir;
		} );
	}, [ rows, sort ] );

	const controlled = onPage !== undefined;
	const totalPages = controlled ? pages ?? 1 : pageSize > 0 ? Math.max( 1, Math.ceil( sorted.length / pageSize ) ) : 1;
	const current = controlled ? page ?? 1 : Math.min( localPage, totalPages );
	const visible = ! controlled && pageSize > 0 ? sorted.slice( ( current - 1 ) * pageSize, current * pageSize ) : sorted;
	const go = ( p: number ): void => ( controlled ? onPage?.( p ) : setLocalPage( p ) );

	return (
		<>
			<div className="uxs-table-wrap">
				<table className="uxs-table">
					<thead>
						<tr>
							{ columns.map( ( c ) => (
								<th key={ c.key } scope="col" aria-sort={ sort?.key === c.key ? ( sort.dir === 1 ? 'ascending' : 'descending' ) : undefined }>
									{ c.sortable ? (
										<button type="button" onClick={ () => setSort( sort?.key === c.key ? { key: c.key, dir: sort.dir === 1 ? -1 : 1 } : { key: c.key, dir: 1 } ) }>
											{ c.header }
											{ sort?.key === c.key ? sort.dir === 1 ? <ArrowUp size={ 12 } aria-hidden="true" /> : <ArrowDown size={ 12 } aria-hidden="true" /> : null }
										</button>
									) : (
										c.header
									) }
								</th>
							) ) }
						</tr>
					</thead>
					<tbody>
						{ visible.length === 0 ? (
							<tr>
								<td className="is-empty" colSpan={ columns.length }>
									{ empty ?? t( 'No data.' ) }
								</td>
							</tr>
						) : (
							visible.map( ( row ) => (
								<tr key={ rowKey( row ) }>
									{ columns.map( ( c ) => (
										<td key={ c.key }>{ c.render ? c.render( row ) : String( row[ c.key ] ?? '' ) }</td>
									) ) }
								</tr>
							) )
						) }
					</tbody>
				</table>
			</div>
			{ totalPages > 1 ? (
				<div className="uxs-pager">
					<button type="button" className="uxs-btn uxs-btn--small" disabled={ current <= 1 } onClick={ () => go( current - 1 ) }>
						{ t( 'Previous' ) }
					</button>
					<span>{ t( 'Page {page} of {pages}', { page: current, pages: totalPages } ) }</span>
					<button type="button" className="uxs-btn uxs-btn--small" disabled={ current >= totalPages } onClick={ () => go( current + 1 ) }>
						{ t( 'Next' ) }
					</button>
				</div>
			) : null }
		</>
	);
}
