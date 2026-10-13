import { __, _n, sprintf } from '@wordpress/i18n';
import { Fragment } from '../wordpress-element';
import { formatInteger } from '../format';
import type { ActivityEvent, BlocklistType } from '../types';

const identifierLabels: Record<BlocklistType, string> = {
  email: __('Email', 'veridis-fraud-guard'),
  domain: __('Email domain', 'veridis-fraud-guard'),
  phone: __('Phone', 'veridis-fraud-guard'),
  ip: __('IP address', 'veridis-fraud-guard'),
};

export function ActivityMatchSummary({ event, manual = false }: { event: ActivityEvent; manual?: boolean }) {
  const occurrenceSummary = !manual && event.occurrence_count > 1 ? <>
    <span aria-hidden="true"> · </span>
    <span>{sprintf(
      /* translators: %s: localized number of similar checkout attempts represented by this Activity record. */
      _n('%s similar attempt', '%s similar attempts', event.occurrence_count, 'veridis-fraud-guard'),
      formatInteger(event.occurrence_count),
    )}</span>
  </> : null;

  if (!manual && event.matched_identifiers.length > 0) {
    return (
      <span className="vfg-activity-matches">
        {__('Matched:', 'veridis-fraud-guard')}{' '}
        {event.matched_identifiers.map((identifier, index) => (
          <Fragment key={`${identifier.type}-${identifier.value}`}>
            {index > 0 ? <span aria-hidden="true"> · </span> : null}
            <span className="vfg-activity-match">
              <span>{identifierLabels[identifier.type]}:</span>{' '}
              <bdi>{identifier.value}</bdi>
            </span>
          </Fragment>
        ))}
        {occurrenceSummary}
      </span>
    );
  }

  const prefix = manual ? __('Identifier:', 'veridis-fraud-guard') : __('Matched:', 'veridis-fraud-guard');
  return <span>{prefix} {event.matched_types.map(type => identifierLabels[type]).join(', ')}{occurrenceSummary}</span>;
}
