import { __ } from '@wordpress/i18n';
import { useCallback, useEffect, useRef, useState } from '../wordpress-element';
import { Overview } from '../components/Overview';
import { BlocklistPage } from '../components/BlocklistPage';
import { SettingsPage } from '../components/SettingsPage';
import { ActivityPage } from '../components/ActivityPage';
import { HelpPage } from '../components/ProductShellPages';
import { ImportExportPage } from '../components/ImportExportPage';

type Section = 'dashboard' | 'blocklist' | 'settings' | 'activity' | 'import-export' | 'help';

const sections: Array<{ id: Section; label: string }> = [
  { id: 'dashboard', label: __('Dashboard', 'veridis-fraud-guard') },
  { id: 'blocklist', label: __('Blocklist', 'veridis-fraud-guard') },
  { id: 'settings', label: __('Settings', 'veridis-fraud-guard') },
  { id: 'activity', label: __('Activity', 'veridis-fraud-guard') },
  { id: 'import-export', label: __('Import / Export', 'veridis-fraud-guard') },
  { id: 'help', label: __('Help', 'veridis-fraud-guard') },
];

function sectionFromHash(): Section | null {
  const value = window.location.hash.replace(/^#/, '');
  if (value === '') return 'dashboard';
  return sections.some(section => section.id === value) ? value as Section : null;
}

export function App() {
  const version = window.veridisFraudGuard?.version;
  const [section, setSection] = useState<Section>(() => sectionFromHash() ?? 'dashboard');
  const [addEntryRequest, setAddEntryRequest] = useState(0);
  const contentRef = useRef<HTMLElement>(null);
  const focusContentRef = useRef(false);

  useEffect(() => {
    const syncRoute = () => {
      const next = sectionFromHash();
      if (next) setSection(next);
    };
    window.addEventListener('hashchange', syncRoute);
    window.addEventListener('popstate', syncRoute);
    return () => {
      window.removeEventListener('hashchange', syncRoute);
      window.removeEventListener('popstate', syncRoute);
    };
  }, []);

  useEffect(() => {
    if (!focusContentRef.current) return;
    focusContentRef.current = false;
    contentRef.current?.focus({ preventScroll: true });
  }, [section]);

  const navigate = useCallback((next: Section) => {
    if (section === next) return;
    focusContentRef.current = true;
    window.history.pushState(null, '', `#${next}`);
    setSection(next);
  }, [section]);

  const addBlocklistEntry = useCallback(() => {
    if (section !== 'blocklist') window.history.pushState(null, '', '#blocklist');
    setAddEntryRequest(current => current + 1);
    setSection('blocklist');
  }, [section]);

  const clearAddEntryRequest = useCallback(() => setAddEntryRequest(0), []);

  const content = (() => {
    switch (section) {
      case 'blocklist': return <BlocklistPage addEntryRequest={addEntryRequest} onAddEntryRequestHandled={clearAddEntryRequest} />;
      case 'settings': return <SettingsPage />;
      case 'activity': return <ActivityPage />;
      case 'import-export': return <ImportExportPage />;
      case 'help': return <HelpPage />;
      case 'dashboard':
      default: return <Overview onNavigate={navigate} onAddEntry={addBlocklistEntry} />;
    }
  })();

  return (
    <>
      <a className="vfg-skip" href="#vfg-content" onClick={event => { event.preventDefault(); contentRef.current?.focus(); }}>{__('Skip to content', 'veridis-fraud-guard')}</a>
      <header className="vfg-brand-header">
        <div className="vfg-brand-group">
          <span className="vfg-brand-lockup" aria-label={__('Veridis Guard', 'veridis-fraud-guard')}>
            <span className="vfg-brand-lockup-veridis">{__('VERIDIS', 'veridis-fraud-guard')}</span>
            <span className="vfg-brand-lockup-guard">{__('GUARD', 'veridis-fraud-guard')}</span>
          </span>
          <div>
            <div className="vfg-product-title">
              <h1>{__('Fraud Guard', 'veridis-fraud-guard')}</h1>
              {version ? <span className="vfg-version">v{version}</span> : null}
            </div>
            <p>{__('Local protection for confident WooCommerce operations.', 'veridis-fraud-guard')}</p>
          </div>
        </div>
      </header>

      <div className="vfg-workspace">
        <nav className="vfg-navigation" aria-label={__('Fraud Guard sections', 'veridis-fraud-guard')}>
          {sections.map(item => (
            <a key={item.id} href={`#${item.id}`} aria-current={section === item.id ? 'page' : undefined} onClick={event => { event.preventDefault(); navigate(item.id); }}>{item.label}</a>
          ))}
        </nav>
        <main ref={contentRef} id="vfg-content" className="vfg-content" tabIndex={-1}>
          {content}
        </main>
      </div>

      <footer className="vfg-admin-footer">
        <p>&copy; {new Date().getFullYear()} {__('Veridis. Built for WooCommerce.', 'veridis-fraud-guard')}</p>
      </footer>
    </>
  );
}
