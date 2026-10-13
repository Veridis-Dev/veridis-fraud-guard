import { __, _x } from '@wordpress/i18n';
import type { FormEvent, ReactElement } from 'react';
import { useEffect, useMemo, useRef, useState } from '../wordpress-element';
import { getCheckoutSettings, saveCheckoutSettings } from '../api/settings';
import { ApiError } from '../api/client';
import { Badge } from '../design-system/Badge';
import { Button } from '../design-system/Button';
import { Card } from '../design-system/Card';
import { EmptyState } from '../design-system/EmptyState';
import { Notice } from '../design-system/Notice';
import { Skeleton } from '../design-system/Skeleton';
import { useToast } from '../design-system/Toast';
import type { CheckoutMode } from '../types';

type SettingsIconName = 'shield' | 'message' | CheckoutMode;

const modeOptions: Array<{ value: CheckoutMode; label: string; description: string; icon: SettingsIconName }> = [
  {
    value: 'off',
    label: __('Off', 'veridis-fraud-guard'),
    description: __('Protection is disabled. Checkout proceeds without Fraud Guard intervention.', 'veridis-fraud-guard'),
    icon: 'off',
  },
  {
    value: 'block',
    label: _x('Block', 'checkout protection mode or intervention', 'veridis-fraud-guard'),
    description: __('Reject checkout when an email, phone, IP address, or email domain matches the blocklist.', 'veridis-fraud-guard'),
    icon: 'block',
  },
  {
    value: 'hold',
    label: __('Hold', 'veridis-fraud-guard'),
    description: __('Place matched COD orders on hold. Other payment methods continue and receive an internal warning.', 'veridis-fraud-guard'),
    icon: 'hold',
  },
  {
    value: 'warn',
    label: __('Warn', 'veridis-fraud-guard'),
    description: __('Accept matched orders normally and flag them internally for merchant attention.', 'veridis-fraud-guard'),
    icon: 'warn',
  },
];

export function SettingsPage() {
  const notify = useToast();
  const modeSavingRef = useRef(false);
  const messageSavingRef = useRef(false);
  const [reloadKey, setReloadKey] = useState(0);
  const [savedMode, setSavedMode] = useState<CheckoutMode | null>(null);
  const [selectedMode, setSelectedMode] = useState<CheckoutMode>('off');
  const [savedMessage, setSavedMessage] = useState<string | null>(null);
  const [message, setMessage] = useState('');
  const [messageMaxLength, setMessageMaxLength] = useState(500);
  const [loading, setLoading] = useState(true);
  const [savingMode, setSavingMode] = useState(false);
  const [savingMessage, setSavingMessage] = useState(false);
  const [loadError, setLoadError] = useState<ApiError | null>(null);
  const [modeSaveError, setModeSaveError] = useState('');
  const [messageSaveError, setMessageSaveError] = useState('');

  useEffect(() => {
    const controller = new AbortController();
    setLoading(true);
    setLoadError(null);
    setModeSaveError('');
    setMessageSaveError('');

    void getCheckoutSettings(controller.signal)
      .then(settings => {
        const { mode } = settings;
        setSavedMode(mode);
        setSelectedMode(mode);
        setSavedMessage(settings.message);
        setMessage(settings.message);
        setMessageMaxLength(settings.message_max_length);
      })
      .catch(error => {
        if (controller.signal.aborted) return;
        setLoadError(error instanceof ApiError
          ? error
          : new ApiError(__('Checkout protection settings could not be loaded.', 'veridis-fraud-guard'), 0));
      })
      .finally(() => {
        if (!controller.signal.aborted) setLoading(false);
      });

    return () => controller.abort();
  }, [reloadKey]);

  const modeDirty = savedMode !== null && selectedMode !== savedMode;
  const messageDirty = savedMessage !== null && message !== savedMessage;
  const messageLength = Array.from(message).length;
  const messageEmpty = message.trim() === '';
  const messageTooLong = messageLength > messageMaxLength;
  const selectedLabel = useMemo(
    () => modeOptions.find(option => option.value === selectedMode)?.label ?? selectedMode,
    [selectedMode],
  );

  async function saveMode(event: FormEvent<HTMLFormElement>): Promise<void> {
    event.preventDefault();
    if (!modeDirty || modeSavingRef.current) return;

    modeSavingRef.current = true;
    setSavingMode(true);
    setModeSaveError('');
    try {
      const response = await saveCheckoutSettings({ mode: selectedMode });
      setSavedMode(response.mode);
      setSelectedMode(response.mode);
      notify(__('Checkout protection mode saved.', 'veridis-fraud-guard'), 'success');
    } catch (error) {
      const message = error instanceof Error
        ? error.message
        : __('Checkout protection mode could not be saved. Please try again.', 'veridis-fraud-guard');
      setModeSaveError(message);
      notify(message, 'error');
    } finally {
      modeSavingRef.current = false;
      setSavingMode(false);
    }
  }

  async function saveMessage(event: FormEvent<HTMLFormElement>): Promise<void> {
    event.preventDefault();
    if (!messageDirty || messageEmpty || messageTooLong || messageSavingRef.current) return;

    messageSavingRef.current = true;
    setSavingMessage(true);
    setMessageSaveError('');
    try {
      const response = await saveCheckoutSettings({ message });
      setSavedMessage(response.message);
      setMessage(response.message);
      notify(__('Customer message saved.', 'veridis-fraud-guard'), 'success');
    } catch (error) {
      const errorMessage = error instanceof Error
        ? error.message
        : __('The customer message could not be saved. Please try again.', 'veridis-fraud-guard');
      setMessageSaveError(errorMessage);
      notify(errorMessage, 'error');
    } finally {
      messageSavingRef.current = false;
      setSavingMessage(false);
    }
  }

  return (
    <>
      <div className="vfg-page-heading">
        <div>
          <p className="vfg-eyebrow">{__('CONFIGURATION', 'veridis-fraud-guard')}</p>
          <h2>{__('Settings', 'veridis-fraud-guard')}</h2>
          <p>{__('Configure how Fraud Guard responds when checkout details match the blocklist.', 'veridis-fraud-guard')}</p>
        </div>
      </div>

      {loading ? <SettingsLoading /> : loadError || savedMode === null || savedMessage === null ? (
        <Card className="vfg-settings-card">
          <EmptyState
            title={__('Checkout protection settings could not be loaded', 'veridis-fraud-guard')}
            description={loadError?.message || __('An unexpected error interrupted the request.', 'veridis-fraud-guard')}
            action={<Button onClick={() => setReloadKey(key => key + 1)}>{__('Try again', 'veridis-fraud-guard')}</Button>}
          />
        </Card>
      ) : (
        <div className="vfg-settings-stack">
          <form onSubmit={saveMode}>
            <Card
              className="vfg-settings-card vfg-settings-card--protection"
              title={__('Checkout protection', 'veridis-fraud-guard')}
              titleIcon={<span className="vfg-settings-title-icon" aria-hidden="true"><SettingsIcon name="shield" /></span>}
              action={modeDirty ? <Badge tone="warning">{__('Unsaved changes', 'veridis-fraud-guard')}</Badge> : undefined}
            >
              <p className="vfg-settings-intro">{__('Choose the store response when checkout identifiers exactly match the Fraud Guard blocklist.', 'veridis-fraud-guard')}</p>

              <fieldset className="vfg-mode-options" disabled={savingMode}>
                <legend className="vfg-sr-only">{__('Checkout protection mode', 'veridis-fraud-guard')}</legend>
                {modeOptions.map(option => {
                  const descriptionId = `vfg-checkout-mode-${option.value}-description`;
                  return (
                    <label className={`vfg-mode-option is-${option.value}${selectedMode === option.value ? ' is-selected' : ''}`} key={option.value}>
                      <span className="vfg-mode-icon" aria-hidden="true"><SettingsIcon name={option.icon} /></span>
                      <span className="vfg-mode-copy">
                        <strong>{option.label}</strong>
                        <span id={descriptionId}>{option.description}</span>
                      </span>
                      <input
                        type="radio"
                        name="checkout-protection-mode"
                        value={option.value}
                        checked={selectedMode === option.value}
                        aria-describedby={descriptionId}
                        onChange={() => {
                          setSelectedMode(option.value);
                          setModeSaveError('');
                        }}
                      />
                    </label>
                  );
                })}
              </fieldset>

              {modeSaveError ? <Notice title={__('Mode was not saved', 'veridis-fraud-guard')} tone="error">{modeSaveError}</Notice> : null}

              <div className="vfg-settings-footer" aria-live="polite">
                <div>
                  <strong>{modeDirty ? __('Unsaved selection', 'veridis-fraud-guard') : __('Current effective mode', 'veridis-fraud-guard')}</strong>
                  <span>{selectedLabel}</span>
                </div>
                <Button type="submit" disabled={!modeDirty || savingMode} aria-busy={savingMode || undefined}>
                  {savingMode ? __('Saving…', 'veridis-fraud-guard') : __('Save mode', 'veridis-fraud-guard')}
                </Button>
              </div>
            </Card>
          </form>

          <form onSubmit={saveMessage}>
            <Card
              className="vfg-settings-card vfg-settings-card--message"
              title={__('Customer message', 'veridis-fraud-guard')}
              titleIcon={<span className="vfg-settings-title-icon" aria-hidden="true"><SettingsIcon name="message" /></span>}
              action={messageDirty ? <Badge tone="warning">{__('Unsaved changes', 'veridis-fraud-guard')}</Badge> : undefined}
            >
              <p className="vfg-settings-intro">{__('This neutral message appears only when Block mode rejects checkout. It should not reveal why the customer was blocked.', 'veridis-fraud-guard')}</p>

              <label className="vfg-message-field" htmlFor="vfg-checkout-customer-message">
                <strong>{__('Checkout rejection message', 'veridis-fraud-guard')}</strong>
                <textarea
                  id="vfg-checkout-customer-message"
                  value={message}
                  rows={4}
                  maxLength={messageMaxLength}
                  disabled={savingMessage}
                  aria-invalid={messageEmpty || messageTooLong || undefined}
                  aria-describedby="vfg-checkout-customer-message-help vfg-checkout-customer-message-count"
                  onChange={event => {
                    setMessage(event.target.value);
                    setMessageSaveError('');
                  }}
                />
              </label>

              <div className="vfg-message-meta">
                <span id="vfg-checkout-customer-message-help">
                  {messageEmpty ? __('Enter a customer-facing message before saving.', 'veridis-fraud-guard') : __('Plain text only. Fraud Guard never adds matched identifiers to this message.', 'veridis-fraud-guard')}
                </span>
                <span id="vfg-checkout-customer-message-count">{messageLength} / {messageMaxLength}</span>
              </div>

              {messageSaveError ? <Notice title={__('Message was not saved', 'veridis-fraud-guard')} tone="error">{messageSaveError}</Notice> : null}

              <div className="vfg-settings-footer" aria-live="polite">
                <div>
                  <strong>{messageDirty ? __('Unsaved message', 'veridis-fraud-guard') : __('Customer message saved', 'veridis-fraud-guard')}</strong>
                  <span>{__('Used only for rejected Block-mode checkouts.', 'veridis-fraud-guard')}</span>
                </div>
                <Button type="submit" disabled={!messageDirty || messageEmpty || messageTooLong || savingMessage} aria-busy={savingMessage || undefined}>
                  {savingMessage ? __('Saving…', 'veridis-fraud-guard') : __('Save message', 'veridis-fraud-guard')}
                </Button>
              </div>
            </Card>
          </form>
        </div>
      )}
    </>
  );
}

function SettingsLoading() {
  return (
    <div className="vfg-settings-stack" aria-busy="true" aria-label={__('Loading checkout protection settings', 'veridis-fraud-guard')}>
      <Card
        className="vfg-settings-card vfg-settings-card--protection"
        title={<Skeleton style={{ width: 150, height: 16 }} />}
        titleIcon={<Skeleton className="vfg-settings-title-skeleton" />}
      >
        <div className="vfg-settings-loading" aria-hidden="true">
          <Skeleton style={{ width: '88%', height: 12 }} />
          <div className="vfg-mode-options vfg-mode-options--skeleton">
            {Array.from({ length: 4 }, (_, index) => (
              <div className="vfg-mode-option" key={index}>
                <Skeleton style={{ width: 34, height: 34 }} />
                <span className="vfg-mode-copy">
                  <Skeleton style={{ width: 52, height: 13 }} />
                  <Skeleton style={{ width: '92%', height: 10, marginTop: 5 }} />
                  <Skeleton style={{ width: '72%', height: 10, marginTop: 2 }} />
                </span>
                <Skeleton style={{ width: 16, height: 16, borderRadius: '50%' }} />
              </div>
            ))}
          </div>
          <div className="vfg-settings-footer">
            <div>
              <Skeleton style={{ width: 112, height: 10 }} />
              <Skeleton style={{ width: 42, height: 12, marginTop: 4 }} />
            </div>
            <Skeleton style={{ width: 108, height: 38 }} />
          </div>
        </div>
      </Card>
      <Card
        className="vfg-settings-card vfg-settings-card--message"
        title={<Skeleton style={{ width: 126, height: 16 }} />}
        titleIcon={<Skeleton className="vfg-settings-title-skeleton" />}
      >
        <div className="vfg-settings-loading" aria-hidden="true">
          <Skeleton style={{ width: '94%', height: 12 }} />
          <Skeleton style={{ width: '72%', height: 12, marginTop: 7 }} />
          <div className="vfg-message-field vfg-message-field--skeleton">
            <Skeleton style={{ width: 142, height: 11 }} />
            <Skeleton style={{ width: '100%', height: 104 }} />
          </div>
          <div className="vfg-message-meta">
            <Skeleton style={{ width: '68%', height: 10 }} />
            <Skeleton style={{ width: 48, height: 10 }} />
          </div>
          <div className="vfg-settings-footer">
            <div>
              <Skeleton style={{ width: 132, height: 10 }} />
              <Skeleton style={{ width: 208, maxWidth: '64vw', height: 12, marginTop: 4 }} />
            </div>
            <Skeleton style={{ width: 108, height: 38 }} />
          </div>
        </div>
      </Card>
    </div>
  );
}

function SettingsIcon({ name }: { name: SettingsIconName }) {
  const paths: Record<SettingsIconName, ReactElement> = {
    shield: <><path d="M12 3 5.5 5.7v4.8c0 4.1 2.7 7.8 6.5 9.5 3.8-1.7 6.5-5.4 6.5-9.5V5.7L12 3Z" /><path d="m9.2 11.7 1.8 1.8 3.9-4" /></>,
    message: <><path d="M5 5.5h14a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H9l-5 3v-13a2 2 0 0 1 2-2Z" /><path d="M8 10h8M8 13.5h5" /></>,
    off: <><path d="M12 3v8" /><path d="M7.1 5.7a8 8 0 1 0 9.8 0" /></>,
    block: <><circle cx="12" cy="12" r="8.5" /><path d="m6 18 12-12" /></>,
    hold: <><circle cx="12" cy="12" r="8.5" /><path d="M9.5 8.5v7M14.5 8.5v7" /></>,
    warn: <><path d="M5 20V5.5A2.5 2.5 0 0 1 7.5 3H18l-2.5 4L18 11H7.5" /></>,
  };

  return <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">{paths[name]}</svg>;
}
