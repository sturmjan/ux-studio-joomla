import { LoaderCircle } from 'lucide-react';
import { t } from '../i18n';

export function Loading(): JSX.Element {
	return (
		<div className="uxs-loading">
			<LoaderCircle size={ 24 } aria-label={ t( 'Loading…' ) } />
		</div>
	);
}
