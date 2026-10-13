import 'vite/modulepreload-polyfill';
import { createRoot, StrictMode } from './wordpress-element';
import { App } from './app/App';
import { ErrorBoundary } from './app/ErrorBoundary';
import { ToastProvider } from './design-system/Toast';
import './styles/tokens.css';
import './styles/app.css';

const root = document.getElementById('veridis-fraud-guard-root');

if (root) {
  createRoot(root).render(
    <StrictMode>
      <ErrorBoundary>
        <ToastProvider><App /></ToastProvider>
      </ErrorBoundary>
    </StrictMode>,
  );
}
