import { __, _n, _x, sprintf } from '@wordpress/i18n';
import type { ReactElement } from 'react';
import { useOverview } from '../hooks/useOverview';
import { Badge } from '../design-system/Badge';
import { Button } from '../design-system/Button';
import { Card } from '../design-system/Card';
import { EmptyState } from '../design-system/EmptyState';
import { checkoutModes, type ActivityEvent, type CheckoutMode, type Tone } from '../types';
import { OverviewSkeleton } from './OverviewSkeleton';
import { ActivityMatchSummary } from './ActivityMatchSummary';
import { formatInteger, formatUtcDateTime } from '../format';

type DashboardIconName = 'shield' | 'block' | 'hold' | 'warn' | 'calendar' | 'trend' | 'email' | 'phone' | 'ip' | 'domain' | 'list' | 'add';

const checkoutEventLabels = {
  block: _x('Block', 'checkout protection mode or intervention', 'veridis-fraud-guard'),
  hold: __('Hold', 'veridis-fraud-guard'),
  warn: __('Warn', 'veridis-fraud-guard'),
};

const checkoutOutcomeLabels = {
  rejected: __('Rejected', 'veridis-fraud-guard'),
  held: __('Held', 'veridis-fraud-guard'),
  flagged: __('Flagged', 'veridis-fraud-guard'),
  unknown: __('Activity', 'veridis-fraud-guard'),
};

const checkoutSourceLabels = {
  classic: __('Classic checkout', 'veridis-fraud-guard'),
  blocks: __('Checkout Blocks', 'veridis-fraud-guard'),
  unknown: __('Unknown source', 'veridis-fraud-guard'),
};

const checkoutModePresentation: Record<CheckoutMode, { label: string; description: string; tone: Tone }> = {
  off: {
    label: __('Off', 'veridis-fraud-guard'),
    description: __('Protection is disabled. Checkout proceeds without Fraud Guard intervention.', 'veridis-fraud-guard'),
    tone: 'neutral',
  },
  block: {
    label: _x('Block', 'checkout protection mode or intervention', 'veridis-fraud-guard'),
    description: __('Reject checkout when an email, phone, IP address, or email domain matches the blocklist.', 'veridis-fraud-guard'),
    tone: 'error',
  },
  hold: {
    label: __('Hold', 'veridis-fraud-guard'),
    description: __('Place matched COD orders on hold. Other payment methods continue and receive an internal warning.', 'veridis-fraud-guard'),
    tone: 'warning',
  },
  warn: {
    label: __('Warn', 'veridis-fraud-guard'),
    description: __('Accept matched orders normally and flag them internally for merchant attention.', 'veridis-fraud-guard'),
    tone: 'info',
  },
};

export function Overview({ onNavigate, onAddEntry }: {
  onNavigate: (section: 'blocklist' | 'settings' | 'activity') => void;
  onAddEntry: () => void;
}) {
  const { data, error, loading, retry } = useOverview();

  if (loading) return <OverviewSkeleton />;

  if (error || !data) {
    return (
      <EmptyState
        title={__('The overview could not be loaded', 'veridis-fraud-guard')}
        description={error?.message || __('An unexpected error interrupted the request.', 'veridis-fraud-guard')}
        action={<Button onClick={retry}>{__('Try again', 'veridis-fraud-guard')}</Button>}
      />
    );
  }

  const distribution = [
    { label: __('Email', 'veridis-fraud-guard'), count: data.blocklist.email, type: 'email' as const },
    { label: __('Phone', 'veridis-fraud-guard'), count: data.blocklist.phone, type: 'phone' as const },
    { label: __('IP', 'veridis-fraud-guard'), count: data.blocklist.ip, type: 'ip' as const },
    { label: __('Domain', 'veridis-fraud-guard'), count: data.blocklist.domain, type: 'domain' as const },
  ];
  const largestGroup = Math.max(1, ...distribution.map(item => item.count));
  const reportedMode: unknown = data.checkout_protection?.mode;
  const protectionMode = isCheckoutMode(reportedMode) ? reportedMode : null;
  const protection = protectionMode === null ? null : checkoutModePresentation[protectionMode];
  const storageUnavailable = data.checkout_protection?.storage?.status === 'unavailable';
  const statusClass = storageUnavailable ? 'storage-unavailable' : protectionMode ?? 'unavailable';

  return (
    <>
      <div className="vfg-page-heading">
        <div>
          <p className="vfg-eyebrow">{__('STORE PROTECTION', 'veridis-fraud-guard')}</p>
          <h2>{__('Dashboard', 'veridis-fraud-guard')}</h2>
          <p>{__('Monitor protection status and manage the identifiers blocked for your store.', 'veridis-fraud-guard')}</p>
        </div>
      </div>

      <section className="vfg-kpi-strip" aria-label={__('Protection summary', 'veridis-fraud-guard')}>
        <Kpi icon="shield" tone="blue" label={__('Blocked entries', 'veridis-fraud-guard')} value={formatInteger(data.blocklist.total)} detail={__('Active blocklist', 'veridis-fraud-guard')} available />
        <Kpi icon="block" tone="red" label={__('Blocked / flagged attempts', 'veridis-fraud-guard')} value={formatInteger(data.activity.counts.total)} detail={__('Checkout interventions', 'veridis-fraud-guard')} available />
        <Kpi icon="calendar" tone="green" label={__('Today', 'veridis-fraud-guard')} value={formatInteger(data.activity.counts.today)} detail={__('Site calendar day', 'veridis-fraud-guard')} available />
        <Kpi icon="trend" tone="slate" label={__('Last 7 days', 'veridis-fraud-guard')} value={formatInteger(data.activity.counts.last_7_days)} detail={__('Including today', 'veridis-fraud-guard')} available />
      </section>

      <div className="vfg-dashboard-layout">
        <div className="vfg-dashboard-main">
          <Card className={`vfg-status-card is-${statusClass}`}>
            <div className="vfg-today-status">
              <span className="vfg-status-icon" aria-hidden="true"><DashboardIcon name="shield" /></span>
              <div>
                <span className="vfg-status-heading">{__('Protection status', 'veridis-fraud-guard')}</span>
                <strong>{storageUnavailable ? __('Blocklist storage unavailable', 'veridis-fraud-guard') : protection ? sprintf(
                  /* translators: %s: current checkout protection mode label. */
                  __('%s mode', 'veridis-fraud-guard'),
                  protection.label,
                ) : __('Protection mode unavailable', 'veridis-fraud-guard')}</strong>
                <p>{storageUnavailable
                  ? protection
                    ? sprintf(
                      /* translators: %s: current checkout protection mode label. */
                      __('Checkout remains available, but %s mode may not match blocked identifiers until storage access recovers.', 'veridis-fraud-guard'),
                      protection.label,
                    )
                    : __('Checkout remains available, but blocklist matching may be unavailable until storage access recovers.', 'veridis-fraud-guard')
                  : protection?.description ?? __('The current checkout protection mode was not returned. Reload the Dashboard and try again.', 'veridis-fraud-guard')}</p>
              </div>
              <Badge tone={storageUnavailable ? 'error' : protection?.tone ?? 'neutral'}>{storageUnavailable ? __('Needs attention', 'veridis-fraud-guard') : protection?.label ?? __('Unavailable', 'veridis-fraud-guard')}</Badge>
            </div>
          </Card>

          <Card
            className="vfg-recent-card"
            title={__('Recent blocked or flagged attempts', 'veridis-fraud-guard')}
            action={<span className="vfg-card-meta">{sprintf(
              /* translators: %s: localized number of recent checkout events. */
              _n('%s recent event', '%s recent events', data.activity.recent.length, 'veridis-fraud-guard'),
              formatInteger(data.activity.recent.length),
            )}</span>}
          >
            {data.activity.recent.length > 0 ? (
              <ol className="vfg-activity-list">
                {data.activity.recent.map(event => <DashboardActivityRow event={event} key={event.id} />)}
              </ol>
            ) : (
              <div className="vfg-recent-empty">
                <span className="vfg-recent-empty-icon" aria-hidden="true"><DashboardIcon name="trend" /></span>
                <div>
                  <strong>{__('No checkout interventions recorded', 'veridis-fraud-guard')}</strong>
                  <p>{__('Blocked, held, and flagged checkout attempts will appear here when they occur.', 'veridis-fraud-guard')}</p>
                </div>
              </div>
            )}
          </Card>
        </div>

        <aside className="vfg-dashboard-side" aria-label={__('Blocklist overview and actions', 'veridis-fraud-guard')}>
          <Card title={__('Blocklist distribution', 'veridis-fraud-guard')} action={<span className="vfg-card-meta">{sprintf(
            /* translators: %s: localized total number of blocklist entries. */
            __('%s total', 'veridis-fraud-guard'),
            formatInteger(data.blocklist.total),
          )}</span>}>
            <div className="vfg-distribution-list">
              {distribution.map(item => (
                <div className={`vfg-distribution-row is-${item.type}`} key={item.type}>
                  <div className="vfg-distribution-meta">
                    <span><i aria-hidden="true"><DashboardIcon name={item.type} /></i>{item.label}</span>
                    <strong>{formatInteger(item.count)}</strong>
                  </div>
                  <div className="vfg-distribution-track" aria-hidden="true"><span style={{ width: item.count > 0 ? `${Math.max(4, (item.count / largestGroup) * 100)}%` : '0%' }} /></div>
                </div>
              ))}
            </div>
          </Card>

          <Card title={__('Quick actions', 'veridis-fraud-guard')}>
            <div className="vfg-quick-actions">
              <Button className="vfg-dashboard-action" onClick={() => onNavigate('blocklist')}><DashboardIcon name="list" />{__('Manage blocklist', 'veridis-fraud-guard')}</Button>
              <Button className="vfg-dashboard-action" variant="secondary" onClick={onAddEntry}><DashboardIcon name="add" />{__('Add blocklist entry', 'veridis-fraud-guard')}</Button>
            </div>
          </Card>
        </aside>
      </div>
    </>
  );
}

function isCheckoutMode(value: unknown): value is CheckoutMode {
  return typeof value === 'string' && checkoutModes.some(mode => mode === value);
}

function DashboardActivityRow({ event }: { event: ActivityEvent }) {
  const eventType = event.event_type === 'block' || event.event_type === 'hold' || event.event_type === 'warn' ? event.event_type : 'block';
  const outcome = event.outcome === 'rejected' || event.outcome === 'held' || event.outcome === 'flagged' ? event.outcome : 'unknown';
  const source = event.source === 'classic' || event.source === 'blocks' ? event.source : 'unknown';
  return (
    <li className={`vfg-activity-row is-${eventType}`}>
      <span className="vfg-activity-event-icon" aria-hidden="true"><DashboardIcon name={eventType} /></span>
      <div className="vfg-activity-event-main">
        <div className="vfg-activity-event-heading">
          <strong>{checkoutEventLabels[eventType]}</strong>
          <span>{checkoutOutcomeLabels[outcome]}</span>
        </div>
        <p>{checkoutSourceLabels[source]}<span aria-hidden="true"> · </span><ActivityMatchSummary event={event} /></p>
      </div>
      <div className="vfg-activity-event-context">
        {event.order ? event.order.edit_url ? <a href={event.order.edit_url}>{formatOrderNumber(event.order.number)}</a> : <span>{formatOrderNumber(event.order.number)}</span> : null}
        <time dateTime={`${event.created_at.replace(' ', 'T')}Z`}>{formatUtcDateTime(event.created_at)}</time>
      </div>
    </li>
  );
}

function Kpi({ icon, tone, label, value, detail, available = false }: { icon: DashboardIconName; tone: 'blue' | 'red' | 'green' | 'slate'; label: string; value: string; detail: string; available?: boolean }) {
  return (
    <div className={`vfg-kpi is-${tone}${available ? ' is-available' : ''}`}>
      <span className="vfg-kpi-icon" aria-hidden="true"><DashboardIcon name={icon} /></span>
      <div>
        <span className="vfg-kpi-label">{label}</span>
        <div className="vfg-kpi-value"><strong>{value}</strong><small>{detail}</small></div>
      </div>
    </div>
  );
}

function DashboardIcon({ name }: { name: DashboardIconName }) {
  const paths: Record<DashboardIconName, ReactElement> = {
    shield: <><path d="M12 3 5.5 5.7v4.8c0 4.1 2.7 7.8 6.5 9.5 3.8-1.7 6.5-5.4 6.5-9.5V5.7L12 3Z" /><path d="m9.2 11.7 1.8 1.8 3.9-4" /></>,
    block: <><circle cx="12" cy="12" r="8.5" /><path d="m6 18 12-12" /></>,
    hold: <><circle cx="12" cy="12" r="8.5" /><path d="M9.5 8.5v7M14.5 8.5v7" /></>,
    warn: <><path d="M5 20V5.5A2.5 2.5 0 0 1 7.5 3H18l-2.5 4L18 11H7.5" /></>,
    calendar: <><rect x="4" y="5.5" width="16" height="14" rx="2" /><path d="M8 3.5v4M16 3.5v4M4 10h16" /></>,
    trend: <><path d="M4 17 9 12l3 3 7-8" /><path d="M14 7h5v5" /></>,
    email: <><rect x="3.5" y="5.5" width="17" height="13" rx="2" /><path d="m5 7 7 5 7-5" /></>,
    phone: <path d="M7.1 4.5 9.4 8 7.8 9.6c1.2 2.5 3.2 4.5 5.7 5.7l1.6-1.6 3.5 2.3-.7 3c-.2.8-.9 1.3-1.7 1.2C9.7 19.4 4.6 14.3 3.8 7.8c-.1-.8.4-1.5 1.2-1.7l2.1-.5Z" />,
    ip: <><circle cx="12" cy="12" r="8.5" /><path d="M3.8 12h16.4M12 3.5c2 2.3 3 5.1 3 8.5s-1 6.2-3 8.5c-2-2.3-3-5.1-3-8.5s1-6.2 3-8.5Z" /></>,
    domain: <><path d="M3.5 7.5h17v11h-17zM7 7.5v-3h10v3" /><path d="M7 11h3M14 11h3M7 15h3M14 15h3" /></>,
    list: <><path d="M9 6h11M9 12h11M9 18h11" /><circle cx="4.5" cy="6" r="1" /><circle cx="4.5" cy="12" r="1" /><circle cx="4.5" cy="18" r="1" /></>,
    add: <><path d="M12 5v14M5 12h14" /><circle cx="12" cy="12" r="9" /></>,
  };
  return <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">{paths[name]}</svg>;
}

function formatOrderNumber(orderNumber: string): string {
  return sprintf(
    /* translators: %s: WooCommerce order number. */
    __('Order #%s', 'veridis-fraud-guard'),
    orderNumber,
  );
}
