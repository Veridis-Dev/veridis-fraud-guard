import { __, _n, sprintf } from '@wordpress/i18n';
import type { ChangeEvent, FormEvent, ReactElement, Ref } from 'react';
import { useEffect, useId, useRef, useState } from '../wordpress-element';
import { importBlocklist } from '../api/importExport';
import { Button } from '../design-system/Button';
import { Card } from '../design-system/Card';
import { Notice } from '../design-system/Notice';
import { useToast } from '../design-system/Toast';
import { CSV_IMPORT_MAX_FILE_BYTES, CSV_IMPORT_MAX_ROWS } from '../csvLimits';
import { formatDecimal, formatInteger } from '../format';
import type { BlocklistImportResult, ImportDiagnosticCategory } from '../types';

const templateCsv = `\uFEFF${[
  ['type', 'value', 'note'],
  ['email', 'blocked@example.com', __('Chargeback review', 'veridis-fraud-guard')],
  ['phone', "'+40712345678", __('Repeated failed orders', 'veridis-fraud-guard')],
  ['ip', '203.0.113.10', ''],
  ['domain', 'example.com', __('Disposable email provider', 'veridis-fraud-guard')],
].map(row => row.map(csvCell).join(',')).join('\r\n')}\r\n`;

const diagnosticLabels: Record<ImportDiagnosticCategory, string> = {
  duplicate: __('Duplicate', 'veridis-fraud-guard'),
  invalid: __('Invalid', 'veridis-fraud-guard'),
  storage_failure: __('Storage failure', 'veridis-fraud-guard'),
};

export function ImportExportPage() {
  return (
    <>
      <div className="vfg-page-heading">
        <div>
          <p className="vfg-eyebrow">{__('DATA TRANSFER', 'veridis-fraud-guard')}</p>
          <h2>{__('Import / Export', 'veridis-fraud-guard')}</h2>
          <p>{__('Move or maintain your blocklist with a predictable, validated CSV workflow.', 'veridis-fraud-guard')}</p>
        </div>
      </div>
      <div className="vfg-transfer-grid vfg-transfer-grid--active">
        <ImportCard />
        <ExportCard />
      </div>
    </>
  );
}

function ImportCard() {
  const inputId = useId();
  const inputRef = useRef<HTMLInputElement>(null);
  const resultsRef = useRef<HTMLDivElement>(null);
  const notify = useToast();
  const [file, setFile] = useState<File | null>(null);
  const [importing, setImporting] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [result, setResult] = useState<BlocklistImportResult | null>(null);

  useEffect(() => {
    if (result) resultsRef.current?.focus({ preventScroll: true });
  }, [result]);

  const selectFile = (event: ChangeEvent<HTMLInputElement>) => {
    const selected = event.target.files?.[0] ?? null;
    setResult(null);
    setError(null);
    if (!selected) {
      setFile(null);
      return;
    }
    const extensionValid = selected.name.toLowerCase().endsWith('.csv');
    if (!extensionValid) {
      setFile(null);
      setError(__('Choose a file with a .csv extension.', 'veridis-fraud-guard'));
      event.target.value = '';
      return;
    }
    if (selected.size === 0) {
      setFile(null);
      setError(__('Choose a non-empty CSV file.', 'veridis-fraud-guard'));
      event.target.value = '';
      return;
    }
    if (selected.size > CSV_IMPORT_MAX_FILE_BYTES) {
      setFile(null);
      setError(__('The CSV file cannot exceed 10 MB.', 'veridis-fraud-guard'));
      event.target.value = '';
      return;
    }
    setFile(selected);
  };

  const fileGuidance = sprintf(
    /* translators: %s: localized maximum number of CSV data rows. */
    __('CSV only · Maximum 10 MB · %s data rows', 'veridis-fraud-guard'),
    formatInteger(CSV_IMPORT_MAX_ROWS),
  );

  const clearFile = () => {
    setFile(null);
    setError(null);
    if (inputRef.current) inputRef.current.value = '';
    inputRef.current?.focus();
  };

  const submit = async (event: FormEvent) => {
    event.preventDefault();
    if (!file || importing) return;
    setImporting(true);
    setError(null);
    setResult(null);
    try {
      const imported = await importBlocklist(file);
      setResult(imported);
      setFile(null);
      if (inputRef.current) inputRef.current.value = '';
      const tone = imported.invalid > 0 || imported.storage_failures > 0 ? 'warning' : 'success';
      notify(__('CSV import completed.', 'veridis-fraud-guard'), tone);
    } catch (requestError) {
      const message = requestError instanceof Error ? requestError.message : __('The CSV could not be imported. Please try again.', 'veridis-fraud-guard');
      setError(message);
      notify(message, 'error');
    } finally {
      setImporting(false);
    }
  };

  return (
    <Card
      className="vfg-settings-card vfg-transfer-card"
      title={__('Import blocklist', 'veridis-fraud-guard')}
      titleIcon={<span className="vfg-settings-title-icon" aria-hidden="true"><TransferIcon name="import" /></span>}
    >
      <form className="vfg-transfer-layout" onSubmit={submit}>
        <div className="vfg-transfer-content">
          <p className="vfg-settings-intro">{__('Add validated identifiers in bulk without changing entries that are already blocked.', 'veridis-fraud-guard')}</p>

          <div className="vfg-csv-format" aria-label={__('CSV column format', 'veridis-fraud-guard')}>
            <code>type,value,note</code>
            <p>
              <span><strong>{__('Required:', 'veridis-fraud-guard')}</strong> type, value</span>
              <span><strong>{__('Optional:', 'veridis-fraud-guard')}</strong> note</span>
            </p>
          </div>
          <p className="vfg-transfer-help">{__('Supported type values: email, phone, ip, and domain (email domain). Duplicates are skipped; existing values and notes are never overwritten.', 'veridis-fraud-guard')}</p>

          <div className="vfg-import-form">
            <label className="vfg-file-picker" htmlFor={inputId}>
              <span className="vfg-file-picker-icon" aria-hidden="true"><TransferIcon name="file" /></span>
              <span>
                <strong>{file ? file.name : __('Choose a CSV file', 'veridis-fraud-guard')}</strong>
                <small>{file ? `${formatFileSize(file.size)} · ${fileGuidance}` : fileGuidance}</small>
              </span>
              <span className="vfg-file-picker-action">{file ? __('Change', 'veridis-fraud-guard') : __('Browse', 'veridis-fraud-guard')}</span>
              <input ref={inputRef} id={inputId} type="file" accept=".csv,text/csv" disabled={importing} onChange={selectFile} />
            </label>

            {file ? <div className="vfg-selected-file" role="status"><span>{__('Ready to import', 'veridis-fraud-guard')}</span><Button variant="ghost" disabled={importing} onClick={clearFile}>{__('Remove', 'veridis-fraud-guard')}</Button></div> : null}
            {error ? <Notice title={__('Import could not start', 'veridis-fraud-guard')} tone="error">{error}</Notice> : null}

            <p className="vfg-transfer-action-status" aria-live="polite">
              {file ? __('Review the selected file, then start the import.', 'veridis-fraud-guard') : result ? __('Choose another CSV file to run a new import.', 'veridis-fraud-guard') : __('Choose a CSV file to enable import.', 'veridis-fraud-guard')}
            </p>
          </div>

          {result ? <ImportResults ref={resultsRef} result={result} /> : null}
        </div>

        <div className="vfg-settings-footer vfg-transfer-action-area">
          <Button className="vfg-dashboard-action" variant="secondary" disabled={importing} onClick={downloadTemplate}><TransferIcon name="download" />{__('Download CSV template', 'veridis-fraud-guard')}</Button>
          <Button type="submit" disabled={!file || importing} aria-busy={importing || undefined} className={`vfg-dashboard-action vfg-import-button${importing ? ' is-busy' : ''}`}>
            {importing ? <><span className="vfg-inline-spinner" aria-hidden="true" />{__('Importing CSV…', 'veridis-fraud-guard')}</> : <><TransferIcon name="upload" />{__('Import CSV', 'veridis-fraud-guard')}</>}
          </Button>
        </div>
      </form>
    </Card>
  );
}

function ExportCard() {
  const exportUrl = window.veridisFraudGuard?.exportUrl;
  return (
    <Card
      className="vfg-settings-card vfg-transfer-card"
      title={__('Export blocklist', 'veridis-fraud-guard')}
      titleIcon={<span className="vfg-settings-title-icon" aria-hidden="true"><TransferIcon name="export" /></span>}
    >
      <div className="vfg-transfer-layout">
        <div className="vfg-transfer-content">
          <p className="vfg-settings-intro">{__('Download a portable CSV backup of every current blocklist entry.', 'veridis-fraud-guard')}</p>
          <div className="vfg-export-summary">
            <div><TransferIcon name="rows" /><span><strong>{__('All current entries', 'veridis-fraud-guard')}</strong><small>{__('Email, phone, IP, and email-domain records', 'veridis-fraud-guard')}</small></span></div>
            <div><TransferIcon name="note" /><span><strong>{__('Notes included', 'veridis-fraud-guard')}</strong><small>{__('Only type, value, and note are exported', 'veridis-fraud-guard')}</small></span></div>
            <div><TransferIcon name="shield" /><span><strong>{__('Protected download', 'veridis-fraud-guard')}</strong><small>{__('The link is authenticated and no public file is created', 'veridis-fraud-guard')}</small></span></div>
          </div>
          <div className="vfg-export-notice">
            <Notice title={__('Handle exports carefully', 'veridis-fraud-guard')} tone="warning">{__('The CSV contains customer identifiers. Store it securely and share it only with authorized people.', 'veridis-fraud-guard')}</Notice>
          </div>
          {!exportUrl ? <p className="vfg-transfer-error" role="alert">{__('The secure export link is unavailable. Reload the page and try again.', 'veridis-fraud-guard')}</p> : null}
        </div>

        <div className="vfg-settings-footer vfg-transfer-action-area">
          {exportUrl ? <a className="vfg-button vfg-button--primary vfg-dashboard-action vfg-export-download" href={exportUrl}><TransferIcon name="download" />{__('Download blocklist CSV', 'veridis-fraud-guard')}</a> : <Button className="vfg-dashboard-action" disabled><TransferIcon name="download" />{__('Download unavailable', 'veridis-fraud-guard')}</Button>}
        </div>
      </div>
    </Card>
  );
}

const ImportResults = function ImportResults({ result, ref }: { result: BlocklistImportResult; ref: Ref<HTMLDivElement> }) {
  const hasProblems = result.invalid > 0 || result.storage_failures > 0;
  return (
    <div ref={ref} className="vfg-import-results" tabIndex={-1} aria-live="polite">
      <div className="vfg-import-results-heading">
        <div><h3>{__('Import results', 'veridis-fraud-guard')}</h3><p>{hasProblems ? __('The valid rows were imported. Review the rows that need attention.', 'veridis-fraud-guard') : __('The file was processed successfully.', 'veridis-fraud-guard')}</p></div>
      </div>
      <dl className="vfg-import-counts">
        <ResultCount label={__('Processed', 'veridis-fraud-guard')} value={result.processed} />
        <ResultCount label={__('Imported', 'veridis-fraud-guard')} value={result.imported} tone="success" />
        <ResultCount label={__('Duplicates', 'veridis-fraud-guard')} value={result.duplicates} />
        <ResultCount label={__('Invalid', 'veridis-fraud-guard')} value={result.invalid} tone={result.invalid ? 'error' : undefined} />
        <ResultCount label={__('Storage failures', 'veridis-fraud-guard')} value={result.storage_failures} tone={result.storage_failures ? 'error' : undefined} />
      </dl>
      {result.diagnostics.length > 0 ? (
        <div className="vfg-diagnostics">
          <h4>{__('Row details', 'veridis-fraud-guard')}</h4>
          <div className="vfg-table-scroll">
            <table className="vfg-table">
              <thead><tr><th>{__('Row', 'veridis-fraud-guard')}</th><th>{__('Result', 'veridis-fraud-guard')}</th><th>{__('Details', 'veridis-fraud-guard')}</th></tr></thead>
              <tbody>{result.diagnostics.map((item, index) => <tr key={`${item.row}-${item.code}-${index}`}><td data-label={__('Row', 'veridis-fraud-guard')}>{formatInteger(item.row)}</td><td data-label={__('Result', 'veridis-fraud-guard')}><span className={`vfg-diagnostic-status is-${item.category}`}>{diagnosticLabels[item.category]}</span></td><td data-label={__('Details', 'veridis-fraud-guard')}>{item.message}</td></tr>)}</tbody>
            </table>
          </div>
          {result.diagnostics_limited ? <p className="vfg-diagnostics-limit" role="status">{__('Only the first 100 row details are shown. The totals above include the entire file.', 'veridis-fraud-guard')}</p> : null}
        </div>
      ) : null}
    </div>
  );
};

function ResultCount({ label, value, tone }: { label: string; value: number; tone?: 'success' | 'error' }) {
  return <div className={tone ? `is-${tone}` : ''}><dt>{label}</dt><dd>{formatInteger(value)}</dd></div>;
}

type TransferIconName = 'import' | 'export' | 'file' | 'upload' | 'download' | 'rows' | 'note' | 'shield';
function TransferIcon({ name }: { name: TransferIconName }) {
  const paths: Record<TransferIconName, ReactElement> = {
    import: <><path d="M12 3v12m0 0 4-4m-4 4-4-4" /><path d="M5 17v3h14v-3" /></>,
    export: <><path d="M12 16V4m0 0 4 4m-4-4L8 8" /><path d="M5 17v3h14v-3" /></>,
    file: <><path d="M6 3.5h8l4 4V20H6z" /><path d="M14 3.5V8h4M9 12h6M9 15.5h6" /></>,
    upload: <><path d="M12 16V5m0 0 4 4m-4-4L8 9" /><path d="M5 18.5h14" /></>,
    download: <><path d="M12 4v11m0 0 4-4m-4 4-4-4" /><path d="M5 19.5h14" /></>,
    rows: <><path d="M8 6h12M8 12h12M8 18h12" /><circle cx="4" cy="6" r="1" /><circle cx="4" cy="12" r="1" /><circle cx="4" cy="18" r="1" /></>,
    note: <><path d="M5 4h14v16H5z" /><path d="M8.5 8h7M8.5 12h7M8.5 16h4" /></>,
    shield: <><path d="M12 3 5.5 5.7v4.8c0 4.1 2.7 7.8 6.5 9.5 3.8-1.7 6.5-5.4 6.5-9.5V5.7L12 3Z" /><path d="m9.2 11.7 1.8 1.8 3.9-4" /></>,
  };
  return <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">{paths[name]}</svg>;
}

function downloadTemplate() {
  const url = URL.createObjectURL(new Blob([templateCsv], { type: 'text/csv;charset=utf-8' }));
  const link = document.createElement('a');
  link.href = url;
  link.download = 'veridis-fraud-guard-blocklist-template.csv';
  document.body.append(link);
  link.click();
  link.remove();
  URL.revokeObjectURL(url);
}

function csvCell(value: string): string {
  return /[",\r\n]/.test(value) ? `"${value.replaceAll('"', '""')}"` : value;
}

function formatFileSize(bytes: number): string {
  if (bytes < 1024) {
    return sprintf(
      /* translators: %s: localized file size in bytes. */
      _n('%s byte', '%s bytes', bytes, 'veridis-fraud-guard'),
      formatInteger(bytes),
    );
  }
  if (bytes < 1024 * 1024) {
    return sprintf(
      /* translators: %s: localized file size in kilobytes. */
      __('%s KB', 'veridis-fraud-guard'),
      formatDecimal(bytes / 1024),
    );
  }
  return sprintf(
    /* translators: %s: localized file size in megabytes. */
    __('%s MB', 'veridis-fraud-guard'),
    formatDecimal(bytes / (1024 * 1024)),
  );
}
