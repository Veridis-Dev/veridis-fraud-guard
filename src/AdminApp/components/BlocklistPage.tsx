import type { FormEvent } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import { useEffect, useId, useMemo, useState } from '../wordpress-element';
import { createBlocklistEntry, deleteBlocklistEntry, updateBlocklistEntry } from '../api/blocklist';
import { ApiError } from '../api/client';
import { useBlocklist } from '../hooks/useBlocklist';
import { Badge } from '../design-system/Badge';
import { Button } from '../design-system/Button';
import { EmptyState } from '../design-system/EmptyState';
import { Modal } from '../design-system/Modal';
import { Skeleton } from '../design-system/Skeleton';
import { useToast } from '../design-system/Toast';
import { formatInteger, formatUtcDate } from '../format';
import type { BlocklistEntry, BlocklistInput, BlocklistType, Tone } from '../types';

const typeLabels: Record<BlocklistType, string> = {
  email: __('Email', 'veridis-fraud-guard'),
  domain: __('Domain', 'veridis-fraud-guard'),
  phone: __('Phone', 'veridis-fraud-guard'),
  ip: __('IP address', 'veridis-fraud-guard'),
};

const typeTones: Record<BlocklistType, Tone> = { email: 'info', domain: 'success', phone: 'warning', ip: 'neutral' };

export function BlocklistPage({ addEntryRequest = 0, onAddEntryRequestHandled }: { addEntryRequest?: number; onAddEntryRequestHandled?: () => void }) {
  const [searchInput, setSearchInput] = useState('');
  const [search, setSearch] = useState('');
  const [type, setType] = useState<BlocklistType | ''>('');
  const [page, setPage] = useState(1);
  const [editing, setEditing] = useState<BlocklistEntry | null | undefined>(undefined);
  const [rowUnblock, setRowUnblock] = useState<BlocklistEntry | null>(null);
  const [unblockingId, setUnblockingId] = useState<number | null>(null);
  const query = useMemo(() => ({ search, type, page, perPage: 10 }), [page, search, type]);
  const { data, latestData, error, initialLoading, isFetching, refresh } = useBlocklist(query);
  const notify = useToast();
  const searchId = useId();

  useEffect(() => {
    if (addEntryRequest <= 0) return;
    setEditing(null);
    onAddEntryRequestHandled?.();
  }, [addEntryRequest, onAddEntryRequestHandled]);

  useEffect(() => {
    const timer = window.setTimeout(() => {
      setPage(1);
      setSearch(searchInput.trim());
    }, 350);
    return () => window.clearTimeout(timer);
  }, [searchInput]);

  useEffect(() => {
    if (data && page > data.pagination.total_pages) setPage(data.pagination.total_pages);
  }, [data, page]);

  const start = data && data.pagination.total_items > 0 ? (data.pagination.page - 1) * data.pagination.per_page + 1 : 0;
  const end = data ? Math.min(data.pagination.page * data.pagination.per_page, data.pagination.total_items) : 0;
  const summaryData = data ?? latestData;
  const hasFilters = searchInput !== '' || search !== '' || type !== '';
  const searchPending = searchInput.trim() !== search;
  const contentLoading = initialLoading || searchPending;
  const isUpdating = isFetching || (contentLoading && latestData !== null);
  const clearSearch = () => {
    setSearchInput('');
    setSearch('');
    setPage(1);
  };
  const resetFilters = () => {
    clearSearch();
    setType('');
  };
  const unblockEntry = async (entry: BlocklistEntry, closeEditor: boolean) => {
    if (unblockingId !== null) return;
    setUnblockingId(entry.id);
    try {
      await deleteBlocklistEntry(entry.id);
      if (closeEditor) setEditing(undefined);
      else setRowUnblock(null);
      refresh();
      notify(__('Identifier unblocked.', 'veridis-fraud-guard'), 'success');
    } catch (requestError) {
      const message = requestError instanceof Error ? requestError.message : __('The identifier could not be unblocked.', 'veridis-fraud-guard');
      notify(message, 'error');
    } finally {
      setUnblockingId(null);
    }
  };

  return (
    <>
      <div className="vfg-page-heading vfg-page-heading--actions">
        <div>
          <p className="vfg-eyebrow">{__('MANUAL PROTECTION', 'veridis-fraud-guard')}</p>
          <h2>{__('Blocklist', 'veridis-fraud-guard')}</h2>
          <p>{__('Manage exact customer identifiers that your future protection workflow can evaluate.', 'veridis-fraud-guard')}</p>
        </div>
        <Button onClick={() => setEditing(null)}>{__('Add entry', 'veridis-fraud-guard')}</Button>
      </div>

      <section className="vfg-blocklist-summary" aria-label={__('Blocklist summary', 'veridis-fraud-guard')}>
        <div>
          <span>{__('Total entries', 'veridis-fraud-guard')}</span>
          {summaryData ? <strong>{formatInteger(summaryData.counts.total)}</strong> : <Skeleton className="vfg-blocklist-summary-skeleton" />}
        </div>
        <p>{__('Exact matches only. Wildcards and CIDR ranges are not supported.', 'veridis-fraud-guard')}</p>
      </section>

      <div className="vfg-toolbar" role="search">
        <div className="vfg-search-field">
          <label className="vfg-sr-only" htmlFor={searchId}>{__('Search blocklist', 'veridis-fraud-guard')}</label>
          <div className="vfg-search-control">
            <input id={searchId} type="search" value={searchInput} onChange={event => setSearchInput(event.target.value)} placeholder={__('Search values or notes…', 'veridis-fraud-guard')} />
            {searchInput ? <button type="button" className="vfg-search-clear" aria-label={__('Clear search', 'veridis-fraud-guard')} onClick={clearSearch}>×</button> : null}
          </div>
        </div>
        <label className="vfg-filter-field">
          <span className="vfg-sr-only">{__('Filter by type', 'veridis-fraud-guard')}</span>
          <select value={type} onChange={event => { setType(event.target.value as BlocklistType | ''); setPage(1); }}>
            <option value="">{__('All types', 'veridis-fraud-guard')}</option>
            {Object.entries(typeLabels).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
          </select>
        </label>
        <div
          className={`vfg-toolbar-status${isUpdating || (error && data) ? ' is-active' : ''}${error && data ? ' is-error' : ''}`}
          role={error && data ? 'alert' : 'status'}
          aria-live="polite"
          aria-atomic="true"
        >
          {isUpdating ? <><span className="vfg-toolbar-spinner" aria-hidden="true" />{__('Updating…', 'veridis-fraud-guard')}</> : error && data ? __('Could not refresh', 'veridis-fraud-guard') : null}
        </div>
        <Button variant="ghost" className="vfg-reset-filters" disabled={!hasFilters} onClick={resetFilters}>{__('Reset filters', 'veridis-fraud-guard')}</Button>
      </div>

      <section className="vfg-table-card" aria-busy={contentLoading || isFetching}>
        {contentLoading ? <BlocklistSkeleton /> : error && !data ? (
          <EmptyState title={__('The blocklist could not be loaded', 'veridis-fraud-guard')} description={error.message} action={<Button onClick={refresh}>{__('Try again', 'veridis-fraud-guard')}</Button>} />
        ) : data && data.items.length > 0 ? (
          <>
            <div className="vfg-table-scroll">
              <table className="vfg-table">
                <thead><tr><th>{__('Type', 'veridis-fraud-guard')}</th><th>{__('Value', 'veridis-fraud-guard')}</th><th>{__('Note', 'veridis-fraud-guard')}</th><th>{__('Updated', 'veridis-fraud-guard')}</th><th><span className="vfg-sr-only">{__('Actions', 'veridis-fraud-guard')}</span></th></tr></thead>
                <tbody>{data.items.map(entry => (
                  <tr key={entry.id}>
                    <td data-label={__('Type', 'veridis-fraud-guard')}><Badge tone={typeTones[entry.type]}>{typeLabels[entry.type]}</Badge></td>
                    <td data-label={__('Value', 'veridis-fraud-guard')}><span className="vfg-entry-value">{entry.value}</span></td>
                    <td data-label={__('Note', 'veridis-fraud-guard')}><span className="vfg-entry-note">{entry.note || '—'}</span></td>
                    <td data-label={__('Updated', 'veridis-fraud-guard')}>
                      <time dateTime={`${entry.updated_at.replace(' ', 'T')}Z`}>{formatUtcDate(entry.updated_at)}</time>
                      <span className="vfg-date-secondary">{__('Created', 'veridis-fraud-guard')} {formatUtcDate(entry.created_at)}</span>
                    </td>
                    <td className="vfg-row-actions">
                      <Button variant="ghost" onClick={() => setEditing(entry)}>{__('Edit', 'veridis-fraud-guard')}</Button>
                      <Button variant="ghost" className="vfg-text-danger" onClick={() => setRowUnblock(entry)}>{__('Unblock', 'veridis-fraud-guard')}</Button>
                    </td>
                  </tr>
                ))}</tbody>
              </table>
            </div>
            <div className="vfg-pagination">
              <p>{sprintf(
                /* translators: 1: localized first item number, 2: localized last item number, 3: localized total item count. */
                __('Showing %1$s–%2$s of %3$s', 'veridis-fraud-guard'),
                formatInteger(start),
                formatInteger(end),
                formatInteger(data.pagination.total_items),
              )}</p>
              <div>
                <Button variant="secondary" disabled={page <= 1} onClick={() => setPage(current => current - 1)}>{__('Previous', 'veridis-fraud-guard')}</Button>
                <span>{sprintf(
                  /* translators: 1: localized current page number, 2: localized total number of pages. */
                  __('Page %1$s of %2$s', 'veridis-fraud-guard'),
                  formatInteger(data.pagination.page),
                  formatInteger(data.pagination.total_pages),
                )}</span>
                <Button variant="secondary" disabled={page >= data.pagination.total_pages} onClick={() => setPage(current => current + 1)}>{__('Next', 'veridis-fraud-guard')}</Button>
              </div>
            </div>
          </>
        ) : (
          <EmptyState
            title={search || type ? __('No matching entries', 'veridis-fraud-guard') : __('Your blocklist is empty', 'veridis-fraud-guard')}
            description={search || type ? __('Try a different search or type filter.', 'veridis-fraud-guard') : __('Add an email, domain, phone number, or IP address to begin building your manual protection list.', 'veridis-fraud-guard')}
            action={search || type ? <Button variant="secondary" onClick={resetFilters}>{__('Clear filters', 'veridis-fraud-guard')}</Button> : <Button onClick={() => setEditing(null)}>{__('Add first entry', 'veridis-fraud-guard')}</Button>}
          />
        )}
      </section>

      {editing !== undefined ? <EntryEditor entry={editing} onClose={() => setEditing(undefined)} onSaved={(created) => {
        setEditing(undefined);
        if (created) setPage(1);
        refresh();
        notify(created ? __('Blocklist entry added.', 'veridis-fraud-guard') : __('Blocklist entry updated.', 'veridis-fraud-guard'), 'success');
      }} unblocking={editing !== null && editing !== undefined && unblockingId === editing.id} onUnblock={() => {
        if (editing) void unblockEntry(editing, true);
      }} /> : null}
      {rowUnblock ? <RowUnblockDialog
        entry={rowUnblock}
        unblocking={unblockingId === rowUnblock.id}
        onClose={() => { if (unblockingId === null) setRowUnblock(null); }}
        onUnblock={() => { void unblockEntry(rowUnblock, false); }}
      /> : null}
    </>
  );
}

function EntryEditor({ entry, onClose, onSaved, unblocking, onUnblock }: { entry: BlocklistEntry | null; onClose: () => void; onSaved: (created: boolean) => void; unblocking: boolean; onUnblock: () => void }) {
  const [form, setForm] = useState<BlocklistInput>({ type: entry?.type ?? 'email', value: entry?.value ?? '', note: entry?.note ?? '' });
  const [saving, setSaving] = useState(false);
  const [confirmingUnblock, setConfirmingUnblock] = useState(false);
  const [error, setError] = useState<{ message: string; field: string } | null>(null);
  const notify = useToast();
  const created = entry === null;

  const submit = async (event: FormEvent) => {
    event.preventDefault();
    if (!form.value.trim()) {
      setError({ message: __('Enter a value to block.', 'veridis-fraud-guard'), field: 'value' });
      return;
    }
    setSaving(true);
    setError(null);
    try {
      if (created) await createBlocklistEntry(form);
      else await updateBlocklistEntry(entry.id, form);
      onSaved(created);
    } catch (requestError) {
      const message = requestError instanceof Error ? requestError.message : __('The entry could not be saved.', 'veridis-fraud-guard');
      setError({
        message,
        field: requestError instanceof ApiError ? requestError.field : '',
      });
      notify(message, 'error');
      setSaving(false);
    }
  };

  return (
    <Modal title={created ? __('Add blocklist entry', 'veridis-fraud-guard') : __('Edit blocklist entry', 'veridis-fraud-guard')} description={__('Entries use exact normalized matching.', 'veridis-fraud-guard')} onClose={onClose} footer={
      confirmingUnblock ? <div className="vfg-modal-confirmation">
        <p className="vfg-modal-confirmation-prompt">{__('Are you sure you want to unblock this identifier?', 'veridis-fraud-guard')}</p>
        <div className="vfg-modal-confirmation-actions">
          <Button variant="secondary" disabled={unblocking} onClick={() => setConfirmingUnblock(false)}>{__('Keep blocked', 'veridis-fraud-guard')}</Button>
          <Button variant="danger" className={unblocking ? 'vfg-button--busy' : ''} disabled={unblocking} aria-busy={unblocking || undefined} onClick={onUnblock}>{__('Confirm unblock', 'veridis-fraud-guard')}</Button>
        </div>
      </div> : <>
        {!created ? <Button variant="danger" className="vfg-modal-destructive" disabled={saving} onClick={() => setConfirmingUnblock(true)}>{__('Unblock', 'veridis-fraud-guard')}</Button> : null}
        <Button variant="secondary" disabled={saving} onClick={onClose}>{__('Cancel', 'veridis-fraud-guard')}</Button>
        <Button type="submit" form="vfg-entry-form" disabled={saving}>{saving ? __('Saving…', 'veridis-fraud-guard') : created ? __('Add entry', 'veridis-fraud-guard') : __('Save changes', 'veridis-fraud-guard')}</Button>
      </>
    }>
      <form id="vfg-entry-form" className="vfg-form" onSubmit={submit}>
        {error ? <div className="vfg-form-error" role="alert">{error.message}</div> : null}
        <label><span>{__('Type', 'veridis-fraud-guard')}</span><select value={form.type} onChange={event => setForm(current => ({ ...current, type: event.target.value as BlocklistType }))}>{Object.entries(typeLabels).map(([value, label]) => <option key={value} value={value}>{label}</option>)}</select></label>
        <label><span>{__('Value', 'veridis-fraud-guard')}</span><input autoFocus value={form.value} aria-invalid={error?.field === 'value' || undefined} onChange={event => setForm(current => ({ ...current, value: event.target.value }))} placeholder={valuePlaceholder(form.type)} /><small>{valueHelp(form.type)}</small></label>
        <label><span>{__('Note', 'veridis-fraud-guard')} <em>{__('Optional', 'veridis-fraud-guard')}</em></span><textarea rows={4} maxLength={2000} value={form.note} aria-invalid={error?.field === 'note' || undefined} onChange={event => setForm(current => ({ ...current, note: event.target.value }))} /></label>
      </form>
    </Modal>
  );
}

function RowUnblockDialog({ entry, unblocking, onClose, onUnblock }: { entry: BlocklistEntry; unblocking: boolean; onClose: () => void; onUnblock: () => void }) {
  return <Modal title={__('Unblock this identifier?', 'veridis-fraud-guard')} description={__('This identifier will be removed from the blocklist.', 'veridis-fraud-guard')} onClose={onClose} footer={<>
    <Button variant="secondary" disabled={unblocking} onClick={onClose}>{__('Cancel', 'veridis-fraud-guard')}</Button>
    <Button variant="danger" className={unblocking ? 'vfg-button--busy' : ''} disabled={unblocking} aria-busy={unblocking || undefined} onClick={onUnblock}>{__('Unblock', 'veridis-fraud-guard')}</Button>
  </>}>
    <div className="vfg-unblock-value"><Badge tone={typeTones[entry.type]}>{typeLabels[entry.type]}</Badge><strong>{entry.value}</strong></div>
  </Modal>;
}

function BlocklistSkeleton() {
  return <div className="vfg-table-skeleton" role="status" aria-label={__('Loading blocklist', 'veridis-fraud-guard')}>
    <div className="vfg-table-scroll">
      <table className="vfg-table">
        <thead><tr><th>{__('Type', 'veridis-fraud-guard')}</th><th>{__('Value', 'veridis-fraud-guard')}</th><th>{__('Note', 'veridis-fraud-guard')}</th><th>{__('Updated', 'veridis-fraud-guard')}</th><th><span className="vfg-sr-only">{__('Actions', 'veridis-fraud-guard')}</span></th></tr></thead>
        <tbody aria-hidden="true">
          {Array.from({ length: 5 }, (_, index) => <tr key={index}>
            <td data-label={__('Type', 'veridis-fraud-guard')}><Skeleton style={{ width: 72, height: 24 }} /></td>
            <td data-label={__('Value', 'veridis-fraud-guard')}><Skeleton style={{ width: '78%', height: 14 }} /></td>
            <td data-label={__('Note', 'veridis-fraud-guard')}><Skeleton style={{ width: '72%', height: 12 }} /><Skeleton style={{ width: '48%', height: 10, marginTop: 6 }} /></td>
            <td data-label={__('Updated', 'veridis-fraud-guard')}><Skeleton style={{ width: 86, height: 12 }} /><Skeleton style={{ width: 74, height: 9, marginTop: 6 }} /></td>
            <td className="vfg-row-actions"><Skeleton style={{ width: 104, height: 30, marginLeft: 'auto' }} /></td>
          </tr>)}
        </tbody>
      </table>
    </div>
    <LoadingPagination />
  </div>;
}

function LoadingPagination() {
  return <div className="vfg-pagination vfg-pagination--skeleton" aria-hidden="true">
    <Skeleton style={{ width: 132, height: 12 }} />
    <div>
      <Skeleton style={{ width: 70, height: 32 }} />
      <Skeleton style={{ width: 76, height: 12 }} />
      <Skeleton style={{ width: 50, height: 32 }} />
    </div>
  </div>;
}

function valuePlaceholder(type: BlocklistType): string {
  return { email: 'name@example.com', domain: 'example.com', phone: '+40 712 345 678', ip: '203.0.113.10' }[type];
}

function valueHelp(type: BlocklistType): string {
  return {
    email: __('Email addresses are lowercased before matching.', 'veridis-fraud-guard'),
    domain: __('Enter a bare hostname. A leading www. is preserved.', 'veridis-fraud-guard'),
    phone: __('Formatting is ignored; an explicit international + is preserved.', 'veridis-fraud-guard'),
    ip: __('Enter one exact IPv4 or IPv6 address.', 'veridis-fraud-guard'),
  }[type];
}
