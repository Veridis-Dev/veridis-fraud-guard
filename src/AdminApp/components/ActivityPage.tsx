import { __, _n, _x, sprintf } from '@wordpress/i18n';
import type { ReactElement } from 'react';
import { useEffect, useMemo, useState } from '../wordpress-element';
import { useActivity } from '../hooks/useActivity';
import { Button } from '../design-system/Button';
import { Card } from '../design-system/Card';
import { EmptyState } from '../design-system/EmptyState';
import { Skeleton } from '../design-system/Skeleton';
import type { ActivityEvent, ActivityEventType, ActivityFilter } from '../types';
import { ActivityMatchSummary } from './ActivityMatchSummary';
import { formatInteger, formatUtcDateTime } from '../format';

const eventLabels: Record<ActivityEventType, string> = {
  block: _x('Block', 'checkout protection mode or intervention', 'veridis-fraud-guard'),
  hold: __('Hold', 'veridis-fraud-guard'),
  warn: __('Warn', 'veridis-fraud-guard'),
  blocklist: __('Manual action', 'veridis-fraud-guard'),
};

const outcomeLabels = {
  rejected: __('Rejected', 'veridis-fraud-guard'),
  held: __('Held', 'veridis-fraud-guard'),
  flagged: __('Flagged', 'veridis-fraud-guard'),
  added: __('Added', 'veridis-fraud-guard'),
  edited: __('Edited', 'veridis-fraud-guard'),
  unblocked: __('Unblocked', 'veridis-fraud-guard'),
  unknown: __('Activity', 'veridis-fraud-guard'),
};

const sourceLabels = {
  classic: __('Classic checkout', 'veridis-fraud-guard'),
  blocks: __('Checkout Blocks', 'veridis-fraud-guard'),
  admin: __('Admin', 'veridis-fraud-guard'),
  order_panel: __('Order panel', 'veridis-fraud-guard'),
  unknown: __('Unknown source', 'veridis-fraud-guard'),
};

const filters: Array<{ value: ActivityFilter; label: string }> = [
  { value: '', label: __('All', 'veridis-fraud-guard') },
  { value: 'checkout', label: __('Checkout', 'veridis-fraud-guard') },
  { value: 'block', label: _x('Block', 'checkout protection mode or intervention', 'veridis-fraud-guard') },
  { value: 'hold', label: __('Hold', 'veridis-fraud-guard') },
  { value: 'warn', label: __('Warn', 'veridis-fraud-guard') },
  { value: 'blocklist', label: __('Manual actions', 'veridis-fraud-guard') },
];

export function ActivityPage() {
  const [eventType, setEventType] = useState<ActivityFilter>('');
  const [page, setPage] = useState(1);
  const query = useMemo(() => ({ eventType, page, perPage: 10 }), [eventType, page]);
  const { data, latestData, error, initialLoading, isFetching, refresh } = useActivity(query);
  const summaryData = data ?? latestData;
  const isUpdating = isFetching || (initialLoading && latestData !== null);

  useEffect(() => {
    if (data && page > data.pagination.total_pages) setPage(data.pagination.total_pages);
  }, [data, page]);

  const start = data && data.pagination.total_items > 0 ? (data.pagination.page - 1) * data.pagination.per_page + 1 : 0;
  const end = data ? Math.min(data.pagination.page * data.pagination.per_page, data.pagination.total_items) : 0;

  return (
    <>
      <div className="vfg-page-heading">
        <div>
          <p className="vfg-eyebrow">{__('STORE ACTIVITY', 'veridis-fraud-guard')}</p>
          <h2>{__('Activity', 'veridis-fraud-guard')}</h2>
          <p>{__('Review checkout interventions and manual blocklist actions recorded by Fraud Guard.', 'veridis-fraud-guard')}</p>
        </div>
      </div>

      {initialLoading && !latestData ? <ActivitySummarySkeleton /> : (
        <section className="vfg-kpi-strip vfg-activity-summary" aria-label={__('Activity summary', 'veridis-fraud-guard')}>
          <ActivityKpi icon="activity" tone="slate" label={__('Total events', 'veridis-fraud-guard')} description={__('All checkout and manual administrative events.', 'veridis-fraud-guard')} value={summaryData?.counts.total ?? '—'} />
          <ActivityKpi icon="block" tone="red" label={__('Rejected', 'veridis-fraud-guard')} description={__('Checkout attempts rejected by Block mode.', 'veridis-fraud-guard')} value={summaryData?.counts.block ?? '—'} />
          <ActivityKpi icon="hold" tone="amber" label={__('Held', 'veridis-fraud-guard')} description={__('Checkout orders placed on hold by Hold mode.', 'veridis-fraud-guard')} value={summaryData?.counts.hold ?? '—'} />
          <ActivityKpi icon="warn" tone="blue" label={__('Flagged', 'veridis-fraud-guard')} description={__('Checkout orders flagged without rejection, including non-COD matches in Hold mode.', 'veridis-fraud-guard')} value={summaryData?.counts.warn ?? '—'} />
        </section>
      )}

      <div className="vfg-activity-toolbar">
        <div className="vfg-activity-filters" role="group" aria-label={__('Filter activity by event type', 'veridis-fraud-guard')}>
          {filters.map(filter => (
            <button
              key={filter.value || 'all'}
              type="button"
              className={eventType === filter.value ? 'is-active' : ''}
              aria-pressed={eventType === filter.value}
              onClick={() => { setEventType(filter.value); setPage(1); }}
            >
              {filter.label}
            </button>
          ))}
        </div>
        <div className={`vfg-toolbar-status${isUpdating || (error && data) ? ' is-active' : ''}${error && data ? ' is-error' : ''}`} role={error && data ? 'alert' : 'status'} aria-live="polite">
          {isUpdating ? <><span className="vfg-toolbar-spinner" aria-hidden="true" />{__('Updating…', 'veridis-fraud-guard')}</> : error && data ? __('Could not refresh', 'veridis-fraud-guard') : null}
        </div>
      </div>

      <Card className="vfg-activity-card" title={__('Recent activity', 'veridis-fraud-guard')} action={data ? <span className="vfg-card-meta">{sprintf(
        /* translators: %s: localized number of stored Activity records. */
        _n('%s record', '%s records', data.pagination.total_items, 'veridis-fraud-guard'),
        formatInteger(data.pagination.total_items),
      )}</span> : initialLoading ? <Skeleton style={{ width: 54, height: 11 }} /> : undefined}>
        {error && !data ? (
          <EmptyState title={__('Activity could not be loaded', 'veridis-fraud-guard')} description={error.message} action={<Button onClick={refresh}>{__('Try again', 'veridis-fraud-guard')}</Button>} />
        ) : initialLoading ? <ActivityListSkeleton /> : data && data.items.length > 0 ? (
          <>
            <ol className="vfg-activity-list">
              {data.items.map(event => <ActivityRow key={event.id} event={event} />)}
            </ol>
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
            title={eventType ? sprintf(
              /* translators: %s: selected activity filter label. */
              __('No %s activity', 'veridis-fraud-guard'),
              filters.find(filter => filter.value === eventType)?.label ?? eventType,
            ) : __('No activity yet', 'veridis-fraud-guard')}
            description={eventType ? __('No persisted events match this filter.', 'veridis-fraud-guard') : __('Checkout interventions and manual blocklist actions will appear here when they occur.', 'veridis-fraud-guard')}
            action={eventType ? <Button variant="secondary" onClick={() => { setEventType(''); setPage(1); }}>{__('Show all activity', 'veridis-fraud-guard')}</Button> : undefined}
          />
        )}
      </Card>
    </>
  );
}

function ActivityRow({ event }: { event: ActivityEvent }) {
  const isManual = event.event_type === 'blocklist';
  return (
    <li className={`vfg-activity-row is-${event.event_type}`}>
      <span className="vfg-activity-event-icon" aria-hidden="true"><ActivityIcon name={event.event_type} /></span>
      <div className="vfg-activity-event-main">
        <div className="vfg-activity-event-heading">
          <strong>{isManual ? outcomeLabels[event.outcome] : eventLabels[event.event_type]}</strong>
          <span>{isManual ? eventLabels.blocklist : outcomeLabels[event.outcome]}</span>
        </div>
        <p>{sourceLabels[event.source]}<span aria-hidden="true"> · </span><ActivityMatchSummary event={event} manual={isManual} /></p>
      </div>
      <div className="vfg-activity-event-context">
        {event.order ? event.order.edit_url ? <a href={event.order.edit_url}>{formatOrderNumber(event.order.number)}</a> : <span>{formatOrderNumber(event.order.number)}</span> : null}
        <time dateTime={`${event.created_at.replace(' ', 'T')}Z`}>{formatUtcDateTime(event.created_at)}</time>
      </div>
    </li>
  );
}

function ActivityKpi({ icon, tone, label, description, value }: { icon: ActivityIconName; tone: 'slate' | 'red' | 'amber' | 'blue'; label: string; description: string; value: number | string }) {
  const displayValue = typeof value === 'number' ? formatInteger(value) : value;
  return (
    <div className={`vfg-kpi vfg-activity-kpi is-${tone}`} title={description} aria-label={`${label}: ${displayValue}. ${description}`}>
      <span className="vfg-kpi-icon" aria-hidden="true"><ActivityIcon name={icon} /></span>
      <div><span className="vfg-kpi-label">{label}</span><div className="vfg-kpi-value"><strong>{displayValue}</strong></div></div>
    </div>
  );
}

function ActivitySummarySkeleton() {
  return <div className="vfg-kpi-strip vfg-activity-summary" aria-label={__('Loading activity summary', 'veridis-fraud-guard')}>{Array.from({ length: 4 }, (_, index) => <div className="vfg-kpi" key={index}><Skeleton style={{ width: 36, height: 36 }} /><div><Skeleton style={{ width: 82, height: 11 }} /><Skeleton style={{ width: 38, height: 24, marginTop: 8 }} /></div></div>)}</div>;
}

function ActivityListSkeleton() {
  return <div className="vfg-activity-skeleton" role="status" aria-label={__('Loading activity', 'veridis-fraud-guard')}>
    {Array.from({ length: 5 }, (_, index) => <div className="vfg-activity-skeleton-row" aria-hidden="true" key={index}><Skeleton style={{ width: 36, height: 36 }} /><div><Skeleton style={{ width: 110, height: 14 }} /><Skeleton style={{ width: '70%', height: 11, marginTop: 7 }} /></div><div className="vfg-activity-context-skeleton"><Skeleton style={{ width: 76, height: 11 }} /><Skeleton style={{ width: 100, height: 10, marginTop: 6 }} /></div></div>)}
    <div className="vfg-pagination vfg-pagination--skeleton" aria-hidden="true">
      <Skeleton style={{ width: 132, height: 12 }} />
      <div>
        <Skeleton style={{ width: 70, height: 32 }} />
        <Skeleton style={{ width: 76, height: 12 }} />
        <Skeleton style={{ width: 50, height: 32 }} />
      </div>
    </div>
  </div>;
}

function formatOrderNumber(orderNumber: string): string {
  return sprintf(
    /* translators: %s: WooCommerce order number. */
    __('Order #%s', 'veridis-fraud-guard'),
    orderNumber,
  );
}

type ActivityIconName = ActivityEventType | 'activity';

function ActivityIcon({ name }: { name: ActivityIconName }) {
  const paths: Record<ActivityIconName, ReactElement> = {
    activity: <><path d="M5 4.5v15M5 8h4M5 12h7M5 16h10" /></>,
    block: <><circle cx="12" cy="12" r="8.5" /><path d="m6 18 12-12" /></>,
    hold: <><circle cx="12" cy="12" r="8.5" /><path d="M9.5 8.5v7M14.5 8.5v7" /></>,
    warn: <><path d="M5 20V5.5A2.5 2.5 0 0 1 7.5 3H18l-2.5 4L18 11H7.5" /></>,
    blocklist: <><path d="M8 6h11M8 12h11M8 18h11" /><circle cx="4" cy="6" r="1" /><circle cx="4" cy="12" r="1" /><circle cx="4" cy="18" r="1" /></>,
  };
  return <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">{paths[name]}</svg>;
}
