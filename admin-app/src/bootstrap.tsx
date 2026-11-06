/**
 * SPA entry: mounts the app into #uxs-root (rendered by com_uxstudio/tmpl/dashboard/default.php).
 */
import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { QueryClientProvider } from '@tanstack/react-query';
import { App } from './app/App';
import { queryClient } from './app/api';
import { apply as applyTheme } from './app/prefs';
import './style.scss';

const el = document.getElementById( 'uxs-root' );
if ( el ) {
	applyTheme();
	createRoot( el ).render(
		<StrictMode>
			<QueryClientProvider client={ queryClient }>
				<App />
			</QueryClientProvider>
		</StrictMode>
	);
}
