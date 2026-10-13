import { __, _x, sprintf } from '@wordpress/i18n';
import { useState } from '../wordpress-element';
import type { ReactElement, ReactNode } from 'react';
import { CSV_IMPORT_MAX_ROWS } from '../csvLimits';
import { Card } from '../design-system/Card';
import { formatInteger } from '../format';

type HelpIconName = 'shield' | 'modes' | 'identifiers' | 'orders' | 'activity' | 'transfer' | 'faq' | 'support' | 'off' | 'block' | 'hold' | 'warn';

export function HelpPage() {
  const [openFaq, setOpenFaq] = useState<string | null>(null);

  const toggleFaq = (id: string, open: boolean) => {
    setOpenFaq(current => open ? id : current === id ? null : current);
  };

  return (
    <>
      <div className="vfg-page-heading">
        <div>
          <p className="vfg-eyebrow">{__('GUIDANCE', 'veridis-fraud-guard')}</p>
          <h2>{__('Help', 'veridis-fraud-guard')}</h2>
          <p>{__('A practical guide to exact-match checkout protection, blocklist operations, Activity, and CSV transfers.', 'veridis-fraud-guard')}</p>
        </div>
      </div>

      <div className="vfg-help-page">
        <HelpCard icon="shield" title={__('How Fraud Guard works', 'veridis-fraud-guard')}>
          <p className="vfg-help-intro">{__('At checkout, Fraud Guard reads the billing email and phone, the customer IP detected by WooCommerce, and the domain after the email address’s @ sign. Each usable identifier is normalized, then compared with active Blocklist entries of the same type.', 'veridis-fraud-guard')}</p>
          <div className="vfg-help-flow" aria-label={__('Fraud Guard checkout flow', 'veridis-fraud-guard')}>
            <span>{__('Read checkout identifiers', 'veridis-fraud-guard')}</span>
            <i aria-hidden="true">→</i>
            <span>{__('Normalize and match exactly', 'veridis-fraud-guard')}</span>
            <i aria-hidden="true">→</i>
            <span>{__('Apply the selected mode', 'veridis-fraud-guard')}</span>
          </div>
          <div className="vfg-help-scope" role="note">
            <strong>{__('Exact-match protection', 'veridis-fraud-guard')}</strong>
            <p>{__('A match on any supported identifier can trigger protection, and every simultaneous match is recorded. Fraud Guard Free does not calculate a fraud score, infer related identities, use shared intelligence, or perform wildcard, CIDR, or fuzzy matching.', 'veridis-fraud-guard')}</p>
          </div>
        </HelpCard>

        <HelpCard icon="modes" title={__('Checkout protection modes', 'veridis-fraud-guard')}>
          <p className="vfg-help-intro">{__('The selected mode applies to both Classic Checkout and Checkout Blocks. Merchants retain control of order review, status changes, fulfillment, and refunds.', 'veridis-fraud-guard')}</p>
          <div className="vfg-help-modes">
            <Mode name="off" title={__('Off', 'veridis-fraud-guard')}>{__('Fraud Guard does not intervene at checkout. The Blocklist remains available for administration.', 'veridis-fraud-guard')}</Mode>
            <Mode name="block" title={_x('Block', 'checkout protection mode or intervention', 'veridis-fraud-guard')}>{__('Rejects a matched checkout before the order can proceed and shows the customer-facing message configured in Settings.', 'veridis-fraud-guard')}</Mode>
            <Mode name="hold" title={__('Hold', 'veridis-fraud-guard')}>{__('Places a matched Cash on Delivery (COD) order on hold and adds an internal note. Matched non-COD orders continue with their payment and status unchanged, but receive an internal warning note.', 'veridis-fraud-guard')}</Mode>
            <Mode name="warn" title={__('Warn', 'veridis-fraud-guard')}>{__('Accepts the matched order normally, leaves payment and status unchanged, and adds an internal warning for merchant review.', 'veridis-fraud-guard')}</Mode>
          </div>
        </HelpCard>

        <div className="vfg-help-layout">
          <HelpCard icon="identifiers" title={__('Supported identifiers', 'veridis-fraud-guard')}>
            <ul className="vfg-help-identifiers">
              <Identifier type={__('Email', 'veridis-fraud-guard')} example="buyer@example.com">{__('Compared as a complete address without letter-case differences.', 'veridis-fraud-guard')}</Identifier>
              <Identifier type={__('Phone', 'veridis-fraud-guard')} example="+40712345678">{__('Spaces, parentheses, dots, and hyphens are removed. The leading + is significant; 7 to 15 digits are required.', 'veridis-fraud-guard')}</Identifier>
              <Identifier type={__('IP address', 'veridis-fraud-guard')} example="203.0.113.10">{__('One exact IPv4 or IPv6 address, stored in canonical form. Ranges, CIDR, and wildcards are not accepted.', 'veridis-fraud-guard')}</Identifier>
              <Identifier type={__('Email domain', 'veridis-fraud-guard')} example="example.com">{__('Compared with the complete domain derived from the billing email, without letter-case or a trailing dot.', 'veridis-fraud-guard')}</Identifier>
            </ul>
            <p className="vfg-help-note">{__('Blocking a domain affects addresses on that exact domain. It does not automatically identify temporary or disposable email providers.', 'veridis-fraud-guard')}</p>
          </HelpCard>

          <HelpCard icon="orders" title={__('Blocklist and WooCommerce orders', 'veridis-fraud-guard')}>
            <ul className="vfg-help-points">
              <li><strong>{__('Add and edit', 'veridis-fraud-guard')}</strong><span>{__('Save a supported identifier and an optional administrative note. Editing changes the active entry used by future checkout checks.', 'veridis-fraud-guard')}</span></li>
              <li><strong>{__('Duplicates', 'veridis-fraud-guard')}</strong><span>{__('Type and normalized value must be unique. Equivalent formatting is treated as the same entry and existing notes are not overwritten.', 'veridis-fraud-guard')}</span></li>
              <li><strong>{__('Unblock', 'veridis-fraud-guard')}</strong><span>{__('Removes the active entry from future matching. It does not change an existing order status or erase historical Activity.', 'veridis-fraud-guard')}</span></li>
              <li><strong>{__('Order controls', 'veridis-fraud-guard')}</strong><span>{__('Authorized merchants can block all usable identifiers or manage email, domain, phone, and IP individually from the WooCommerce order panel.', 'veridis-fraud-guard')}</span></li>
              <li><strong>{__('Order indicators', 'veridis-fraud-guard')}</strong><span>{__('The orders list and order panel show current Blocklist matches. Held and warned orders also show the applicable internal checkout indicator; these controls do not otherwise change order status.', 'veridis-fraud-guard')}</span></li>
            </ul>
          </HelpCard>

          <HelpCard icon="activity" title={__('Activity', 'veridis-fraud-guard')}>
            <p className="vfg-help-intro">{__('Activity separates checkout interventions from administrative Blocklist changes.', 'veridis-fraud-guard')}</p>
            <dl className="vfg-help-definitions">
              <div><dt>{__('Checkout', 'veridis-fraud-guard')}</dt><dd>{__('Block, Hold, and Warn events include their outcome, Classic or Checkout Blocks source, time, available order link, and every matched identifier.', 'veridis-fraud-guard')}</dd></div>
              <div><dt>{__('Repeated attempts', 'veridis-fraud-guard')}</dt><dd>{__('Identical blocked attempts from one checkout source on the same day share one timeline record. The record shows how many similar attempts occurred, and summary totals count every attempt.', 'veridis-fraud-guard')}</dd></div>
              <div><dt>{__('Manual actions', 'veridis-fraud-guard')}</dt><dd>{__('Added, Edited, and Unblocked events record changes made in the admin or from an order.', 'veridis-fraud-guard')}</dd></div>
              <div><dt>{__('Filters', 'veridis-fraud-guard')}</dt><dd>{__('Use All, Checkout, Block, Hold, Warn, or Manual actions to narrow the timeline.', 'veridis-fraud-guard')}</dd></div>
              <div><dt>{__('Identifier privacy', 'veridis-fraud-guard')}</dt><dd>{__('Authorized WooCommerce administrators see full matched values. Exact checkout values expire after 90 days by default; event metadata and matched types remain.', 'veridis-fraud-guard')}</dd></div>
            </dl>
            <p className="vfg-help-note">{__('Events created before exact-value storage, or after retention or privacy erasure, continue to show the available matched identifier types.', 'veridis-fraud-guard')}</p>
          </HelpCard>

          <HelpCard icon="transfer" title={__('Import / Export', 'veridis-fraud-guard')}>
            <div className="vfg-help-csv-format">
              <code>type,value,note</code>
              <span>{__('Required: type and value · Optional: note', 'veridis-fraud-guard')}</span>
            </div>
            <ul className="vfg-help-points vfg-help-points--compact">
              <li><strong>{__('Type tokens', 'veridis-fraud-guard')}</strong><span><code>email</code>, <code>phone</code>, <code>ip</code>, and <code>domain</code>.</span></li>
              <li><strong>{__('Import', 'veridis-fraud-guard')}</strong><span>{sprintf(
                /* translators: %s: localized maximum number of CSV data rows. */
                __('Download the CSV template, then import a UTF-8 comma-separated file up to 10 MB and %s data rows.', 'veridis-fraud-guard'),
                formatInteger(CSV_IMPORT_MAX_ROWS),
              )}</span></li>
              <li><strong>{__('Partial results', 'veridis-fraud-guard')}</strong><span>{__('Valid rows are imported while normalized duplicates are skipped and invalid or failed rows are reported. Row diagnostics show up to the first 100 results needing attention.', 'veridis-fraud-guard')}</span></li>
              <li><strong>{__('Export', 'veridis-fraud-guard')}</strong><span>{__('Downloads every active Blocklist entry and note in a re-importable CSV. Activity, settings, order data, internal IDs, and timestamps are excluded.', 'veridis-fraud-guard')}</span></li>
              <li><strong>{__('Spreadsheet safety', 'veridis-fraud-guard')}</strong><span>{__('Values that could be interpreted as formulas are escaped for spreadsheet use and restored when re-imported by Fraud Guard.', 'veridis-fraud-guard')}</span></li>
            </ul>
            <p className="vfg-help-note is-warning">{__('Exports contain personal identifiers. Keep downloaded files access-controlled, transfer them securely, and delete copies when they are no longer required.', 'veridis-fraud-guard')}</p>
          </HelpCard>
        </div>

        <HelpCard icon="faq" title={__('Troubleshooting and FAQ', 'veridis-fraud-guard')}>
          <div className="vfg-help-faq">
            <div>
              <Faq id="order-rejected" question={__('Why was an order rejected?', 'veridis-fraud-guard')} open={openFaq === 'order-rejected'} onToggle={open => toggleFaq('order-rejected', open)}>{__('Block mode was active and at least one checkout identifier exactly matched an active Blocklist entry. Review the Block event in Activity to see the recorded match.', 'veridis-fraud-guard')}</Faq>
              <Faq id="cod-hold" question={__('Why was a COD order placed on hold?', 'veridis-fraud-guard')} open={openFaq === 'cod-hold'} onToggle={open => toggleFaq('cod-hold', open)}>{__('Hold mode was active, the payment method was Cash on Delivery, and an identifier matched. Review the Fraud Guard order note and Hold event before fulfillment.', 'veridis-fraud-guard')}</Faq>
              <Faq id="non-cod-hold" question={__('Why did another payment method not trigger Hold?', 'veridis-fraud-guard')} open={openFaq === 'non-cod-hold'} onToggle={open => toggleFaq('non-cod-hold', open)}>{__('Hold changes status only for WooCommerce’s COD payment method. A matched non-COD order continues unchanged and receives an internal warning note.', 'veridis-fraud-guard')}</Faq>
              <Faq id="identifier-no-match" question={__('Why does a previously blocked identifier no longer match?', 'veridis-fraud-guard')} open={openFaq === 'identifier-no-match'} onToggle={open => toggleFaq('identifier-no-match', open)}>{__('Confirm that the entry is still active, the protection mode is not Off, and the checkout value normalizes to the same exact value. An edited or unblocked entry no longer protects its former value.', 'veridis-fraud-guard')}</Faq>
            </div>
            <div>
              <Faq id="historical-types" question={__('Why does an old Activity event show only identifier types?', 'veridis-fraud-guard')} open={openFaq === 'historical-types'} onToggle={open => toggleFaq('historical-types', open)}>{__('The event may predate exact-value storage, or its stored values may have expired after the default 90-day period or been removed through a privacy erasure request.', 'veridis-fraud-guard')}</Faq>
              <Faq id="csv-results" question={__('Why did a CSV import report duplicates or invalid rows?', 'veridis-fraud-guard')} open={openFaq === 'csv-results'} onToggle={open => toggleFaq('csv-results', open)}>{__('Duplicates already exist after normalization, including earlier rows in the same file. Invalid rows can contain an unsupported type token, malformed value, unexpected column, or missing required data.', 'veridis-fraud-guard')}</Faq>
              <Faq id="admin-event" question={__('Why is a Blocklist change not counted as a checkout attempt?', 'veridis-fraud-guard')} open={openFaq === 'admin-event'} onToggle={open => toggleFaq('admin-event', open)}>{__('Add, Edit, and Unblock are administrative events. Dashboard checkout totals include only Block, Hold, and Warn interventions.', 'veridis-fraud-guard')}</Faq>
            </div>
          </div>
        </HelpCard>

        <HelpCard icon="support" title={__('Support and documentation', 'veridis-fraud-guard')}>
          <div className="vfg-help-support">
            <div>
              <p>{__('Use the official Fraud Guard resources for detailed guidance, release history, product information, and support.', 'veridis-fraud-guard')}</p>
            </div>
            <nav className="vfg-help-support-links" aria-label={__('Fraud Guard resources', 'veridis-fraud-guard')}>
              <a className="vfg-button vfg-button--secondary" href="https://veridis.dev/products/fraud-guard/docs/" target="_blank" rel="noopener noreferrer">{__('Documentation', 'veridis-fraud-guard')}</a>
              <a className="vfg-button vfg-button--secondary" href="https://veridis.dev/products/fraud-guard/support/" target="_blank" rel="noopener noreferrer">{__('Support', 'veridis-fraud-guard')}</a>
              <a className="vfg-button vfg-button--secondary" href="https://veridis.dev/products/fraud-guard/changelog/" target="_blank" rel="noopener noreferrer">{__('Changelog', 'veridis-fraud-guard')}</a>
              <a className="vfg-button vfg-button--secondary" href="https://veridis.dev/products/fraud-guard/" target="_blank" rel="noopener noreferrer">{__('Product page', 'veridis-fraud-guard')}</a>
            </nav>
          </div>
        </HelpCard>
      </div>
    </>
  );
}

function HelpCard({ icon, title, children }: { icon: HelpIconName; title: string; children: ReactNode }) {
  return (
    <Card className={`vfg-help-card is-${icon}`} title={title} titleIcon={<span className="vfg-help-title-icon" aria-hidden="true"><HelpIcon name={icon} /></span>}>
      {children}
    </Card>
  );
}

function Mode({ name, title, children }: { name: 'off' | 'block' | 'hold' | 'warn'; title: string; children: ReactNode }) {
  return <div className={`vfg-help-mode is-${name}`}><span aria-hidden="true"><HelpIcon name={name} /></span><div><strong>{title}</strong><p>{children}</p></div></div>;
}

function Identifier({ type, example, children }: { type: string; example: string; children: ReactNode }) {
  return <li><span>{type}</span><div><code>{example}</code><p>{children}</p></div></li>;
}

function Faq({ id, question, open, onToggle, children }: { id: string; question: string; open: boolean; onToggle: (open: boolean) => void; children: ReactNode }) {
  const answerId = `vfg-help-faq-${id}`;
  return (
    <details open={open} onToggle={event => onToggle(event.currentTarget.open)}>
      <summary aria-controls={answerId} aria-expanded={open}>{question}</summary>
      <p id={answerId}>{children}</p>
    </details>
  );
}

function HelpIcon({ name }: { name: HelpIconName }) {
  const paths: Record<HelpIconName, ReactElement> = {
    shield: <><path d="M12 3 5.5 5.7v4.8c0 4.1 2.7 7.8 6.5 9.5 3.8-1.7 6.5-5.4 6.5-9.5V5.7L12 3Z" /><path d="m9.2 11.7 1.8 1.8 3.9-4" /></>,
    modes: <><path d="M4 7h16M4 17h16" /><circle cx="9" cy="7" r="2" /><circle cx="15" cy="17" r="2" /></>,
    identifiers: <><rect x="3.5" y="5" width="17" height="14" rx="2" /><path d="M7 9h4M7 13h10M7 16h7" /></>,
    orders: <><path d="M6 4h12v16H6z" /><path d="M9 8h6M9 12h6M9 16h4" /></>,
    activity: <><path d="M5 4.5v15M5 8h4M5 12h7M5 16h10" /></>,
    transfer: <><path d="M7 7h11m0 0-3-3m3 3-3 3M17 17H6m0 0 3 3m-3-3 3-3" /></>,
    faq: <><circle cx="12" cy="12" r="9" /><path d="M9.7 9a2.4 2.4 0 1 1 3.2 2.3c-.9.4-.9 1-.9 1.7M12 17h.01" /></>,
    support: <><path d="M5 5h14v11H9l-4 3z" /><path d="M9 9h6M9 12h4" /></>,
    off: <><path d="M12 3v8" /><path d="M7.1 5.7a8 8 0 1 0 9.8 0" /></>,
    block: <><circle cx="12" cy="12" r="8.5" /><path d="m6 18 12-12" /></>,
    hold: <><circle cx="12" cy="12" r="8.5" /><path d="M9.5 8.5v7M14.5 8.5v7" /></>,
    warn: <><path d="M5 20V5.5A2.5 2.5 0 0 1 7.5 3H18l-2.5 4L18 11H7.5" /></>,
  };

  return <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">{paths[name]}</svg>;
}
