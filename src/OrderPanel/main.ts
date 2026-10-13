import './styles.css';

type IdentifierType = 'email' | 'phone' | 'ip' | 'domain';

interface Identifier {
  type: IdentifierType;
  value: string;
  blocked: boolean;
  actionable: boolean;
  bulk_actionable: boolean;
}

interface ActionResponse {
  result: string;
  message: string;
  added_count?: number;
  failed_count?: number;
  identifiers: Identifier[];
}

interface PanelConfig {
  restUrl: string;
  nonce: string;
  strings: {
    block: string;
    unblock: string;
    cancel: string;
    blockTitle: string;
    blockDescription: string;
    unblockTitle: string;
    unblockDescription: string;
    closeDialog: string;
    working: string;
    error: string;
    email: string;
    phone: string;
    ip: string;
    domain: string;
    blockedCountLabels: string[];
    blockCustomer: string;
    manageBlocked: string;
    customerClear: string;
    blockedCustomer: string;
    notifications: string;
    dismissNotification: string;
  };
}

declare global {
  interface Window {
    veridisFraudGuardOrder?: PanelConfig;
  }
}

const config = window.veridisFraudGuardOrder;

if (config) {
  const toast = createToastController(config);
  document.querySelectorAll<HTMLElement>('.vfg-order-panel').forEach((panel) => initialisePanel(panel, config, toast));
}

function initialisePanel(panel: HTMLElement, panelConfig: PanelConfig, toast: ToastController): void {
  const orderId = Number(panel.dataset.orderId);
  const container = panel.querySelector<HTMLElement>('[data-vfg-identifiers]');
  const details = panel.querySelector<HTMLDetailsElement>('[data-vfg-details]');
  let busy = false;

  if (!orderId || !container) return;
  const identifiersContainer = container;

  details?.addEventListener('toggle', () => {
    const primary = panel.querySelector<HTMLButtonElement>('[data-vfg-primary]');
    if (primary?.dataset.primaryAction === 'manage') {
      primary.setAttribute('aria-expanded', String(details.open));
    }
  });

  panel.addEventListener('click', (event) => {
    const target = event.target;
    if (!(target instanceof Element) || busy) return;

    const primaryButton = target.closest<HTMLButtonElement>('[data-vfg-primary]');
    if (primaryButton && details) {
      if (primaryButton.dataset.primaryAction === 'bulk-block') {
        void blockCustomer(primaryButton);
        return;
      }
      details.open = true;
      primaryButton.setAttribute('aria-expanded', 'true');
      const focusAction = primaryButton.dataset.focusAction || 'block';
      window.requestAnimationFrame(() => {
        details.querySelector<HTMLButtonElement>(`[data-vfg-action="${focusAction}"]`)?.focus();
      });
      return;
    }

    const actionButton = target.closest<HTMLButtonElement>('[data-vfg-action]');
    if (!actionButton) return;

    const type = actionButton.dataset.vfgType as IdentifierType | undefined;
    if (!type) return;

    const unblocking = actionButton.dataset.vfgAction === 'unblock';
    openIdentifierConfirmationModal(actionButton, type, panelConfig, {
      title: unblocking ? panelConfig.strings.unblockTitle : panelConfig.strings.blockTitle,
      description: unblocking ? panelConfig.strings.unblockDescription : panelConfig.strings.blockDescription,
      actionLabel: unblocking ? panelConfig.strings.unblock : panelConfig.strings.block,
      tone: unblocking ? 'danger' : 'primary',
      onConfirm: async (confirmButton) => {
        const succeeded = await updateIdentifier(type, unblocking ? 'DELETE' : 'POST', confirmButton);
        if (succeeded) {
          panel.querySelector<HTMLButtonElement>(`[data-vfg-identifier="${type}"] [data-vfg-action]`)?.focus();
        }
        return succeeded;
      },
    });
  });

  async function updateIdentifier(type: IdentifierType, method: 'POST' | 'DELETE', requestButton?: HTMLButtonElement): Promise<boolean> {
    if (busy) return false;
    const row = panel.querySelector<HTMLElement>(`[data-vfg-identifier="${type}"]`);
    const activeButton = requestButton ?? row?.querySelector<HTMLButtonElement>('[data-vfg-action="block"]');
    busy = true;
    panel.classList.add('is-busy');
    setButtonsDisabled(panel, true);
    activeButton?.classList.add('is-loading');
    activeButton?.setAttribute('aria-busy', 'true');

    try {
      const response = await fetch(buildEndpoint(panelConfig.restUrl, orderId, type), {
        method,
        credentials: 'same-origin',
        headers: {
          Accept: 'application/json',
          'X-WP-Nonce': panelConfig.nonce,
        },
      });
      const data = (await response.json()) as ActionResponse | { message?: string };
      if (!response.ok || !('identifiers' in data)) {
        throw new Error(data.message || panelConfig.strings.error);
      }

      renderIdentifiers(identifiersContainer, data.identifiers, panelConfig);
      updateOverview(panel, data.identifiers, panelConfig);
      toast.show(data.message, 'success');
      return true;
    } catch (error) {
      toast.show(error instanceof Error ? error.message : panelConfig.strings.error, 'error');
      return false;
    } finally {
      busy = false;
      panel.classList.remove('is-busy');
      activeButton?.classList.remove('is-loading');
      activeButton?.removeAttribute('aria-busy');
      setButtonsDisabled(panel, false);
    }
  }

  async function blockCustomer(activeButton: HTMLButtonElement): Promise<void> {
    busy = true;
    panel.classList.add('is-busy');
    setButtonsDisabled(panel, true);
    activeButton.classList.add('is-loading');
    activeButton.setAttribute('aria-busy', 'true');

    try {
      const response = await fetch(buildEndpoint(panelConfig.restUrl, orderId), {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          Accept: 'application/json',
          'X-WP-Nonce': panelConfig.nonce,
        },
      });
      const data = (await response.json()) as ActionResponse | { message?: string };
      if (!response.ok || !('identifiers' in data)) {
        throw new Error(data.message || panelConfig.strings.error);
      }

      renderIdentifiers(identifiersContainer, data.identifiers, panelConfig);
      updateOverview(panel, data.identifiers, panelConfig);
      toast.show(data.message, data.failed_count ? 'error' : 'success');
    } catch (error) {
      toast.show(error instanceof Error ? error.message : panelConfig.strings.error, 'error');
    } finally {
      busy = false;
      panel.classList.remove('is-busy');
      activeButton.classList.remove('is-loading');
      activeButton.removeAttribute('aria-busy');
      setButtonsDisabled(panel, false);
    }
  }

}

type ToastTone = 'info' | 'success' | 'error';

interface ToastController {
  show: (message: string, tone: ToastTone) => void;
  dismiss: () => void;
}

function createToastController(config: PanelConfig): ToastController {
  let container: HTMLDivElement | null = null;
  let toast: HTMLDivElement | null = null;
  let timer: number | undefined;
  let currentMessage = '';
  let currentTone: ToastTone | null = null;

  function durationFor(tone: ToastTone): number {
    return tone === 'error' ? 5500 : 3500;
  }

  function clearTimer(): void {
    if (timer !== undefined) {
      window.clearTimeout(timer);
      timer = undefined;
    }
  }

  function scheduleDismiss(): void {
    clearTimer();
    if (currentTone) timer = window.setTimeout(dismiss, durationFor(currentTone));
  }

  function dismiss(): void {
    clearTimer();
    toast?.remove();
    container?.remove();
    toast = null;
    container = null;
    currentMessage = '';
    currentTone = null;
  }

  function show(message: string, tone: ToastTone): void {
    const normalizedMessage = message.trim();
    if (!normalizedMessage) return;

    if (toast && currentMessage === normalizedMessage && currentTone === tone) {
      scheduleDismiss();
      return;
    }

    dismiss();
    currentMessage = normalizedMessage;
    currentTone = tone;

    container = document.createElement('div');
    container.className = 'vfg-order-toasts';
    container.setAttribute('aria-label', config.strings.notifications);

    toast = document.createElement('div');
    toast.className = `vfg-order-toast is-${tone}`;
    toast.setAttribute('role', tone === 'error' ? 'alert' : 'status');
    toast.setAttribute('aria-atomic', 'true');

    const messageElement = document.createElement('span');
    messageElement.className = 'vfg-order-toast__message';
    messageElement.textContent = normalizedMessage;

    const closeButton = document.createElement('button');
    closeButton.type = 'button';
    closeButton.className = 'vfg-order-toast__close';
    closeButton.setAttribute('aria-label', config.strings.dismissNotification);
    closeButton.textContent = '×';
    closeButton.addEventListener('click', dismiss);

    toast.addEventListener('mouseenter', clearTimer);
    toast.addEventListener('mouseleave', scheduleDismiss);
    toast.addEventListener('focusin', clearTimer);
    toast.addEventListener('focusout', (event) => {
      if (!toast?.contains(event.relatedTarget as Node | null)) scheduleDismiss();
    });

    toast.append(messageElement, closeButton);
    container.append(toast);
    document.body.append(container);
    scheduleDismiss();
  }

  window.addEventListener('pagehide', dismiss, { once: true });

  return { show, dismiss };
}

function openIdentifierConfirmationModal(button: HTMLButtonElement, type: IdentifierType, config: PanelConfig, options: {
  title: string;
  description: string;
  actionLabel: string;
  tone: 'primary' | 'danger';
  onConfirm: (button: HTMLButtonElement) => Promise<boolean>;
}): void {
  const row = button.closest<HTMLElement>('[data-vfg-identifier]');
  const value = row?.querySelector<HTMLElement>('.vfg-order-panel__value')?.textContent?.trim();
  if (!row || !value || document.querySelector('[data-vfg-order-modal]')) return;

  const titleId = `vfg-order-modal-title-${type}`;
  const descriptionId = `vfg-order-modal-description-${type}`;
  const backdrop = document.createElement('div');
  backdrop.className = 'vfg-order-modal-backdrop';
  backdrop.dataset.vfgOrderModal = '';

  const dialog = document.createElement('section');
  dialog.className = 'vfg-order-modal';
  dialog.tabIndex = -1;
  dialog.setAttribute('role', 'dialog');
  dialog.setAttribute('aria-modal', 'true');
  dialog.setAttribute('aria-labelledby', titleId);
  dialog.setAttribute('aria-describedby', descriptionId);

  const header = document.createElement('header');
  header.className = 'vfg-order-modal__header';
  const title = document.createElement('h3');
  title.id = titleId;
  title.textContent = options.title;
  const closeButton = document.createElement('button');
  closeButton.type = 'button';
  closeButton.className = 'vfg-order-modal__close';
  closeButton.setAttribute('aria-label', config.strings.closeDialog);
  closeButton.textContent = '×';
  header.append(title, closeButton);

  const body = document.createElement('div');
  body.className = 'vfg-order-modal__body';
  const identifier = document.createElement('div');
  identifier.className = 'vfg-order-modal__identifier';
  const label = document.createElement('span');
  label.className = 'vfg-order-modal__type';
  label.textContent = config.strings[type];
  const identifierValue = document.createElement('strong');
  identifierValue.textContent = value;
  identifier.append(label, identifierValue);
  const description = document.createElement('p');
  description.id = descriptionId;
  description.textContent = options.description;
  body.append(identifier, description);

  const footer = document.createElement('footer');
  footer.className = 'vfg-order-modal__footer';
  const cancelButton = makeModalButton(config.strings.cancel, 'is-secondary');
  const confirmButton = makeModalButton(options.actionLabel, options.tone === 'danger' ? 'is-danger' : 'is-primary');
  footer.append(cancelButton, confirmButton);
  dialog.append(header, body, footer);
  backdrop.append(dialog);

  let modalBusy = false;
  const focusableSelector = 'button:not([disabled]), [href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';
  const close = (restoreFocus = true) => {
    if (modalBusy) return;
    document.removeEventListener('keydown', handleKeyDown);
    backdrop.remove();
    if (restoreFocus && document.contains(button)) button.focus();
  };
  const handleKeyDown = (event: KeyboardEvent) => {
    if (event.key === 'Escape') {
      event.preventDefault();
      close();
      return;
    }
    if (event.key !== 'Tab') return;
    const focusable = Array.from(dialog.querySelectorAll<HTMLElement>(focusableSelector));
    if (focusable.length === 0) {
      event.preventDefault();
      dialog.focus();
      return;
    }
    const first = focusable[0];
    const last = focusable[focusable.length - 1];
    if (event.shiftKey && document.activeElement === first) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault();
      first.focus();
    }
  };

  closeButton.addEventListener('click', () => close());
  cancelButton.addEventListener('click', () => close());
  backdrop.addEventListener('mousedown', (event) => {
    if (event.target === backdrop) close();
  });
  confirmButton.addEventListener('click', async () => {
    if (modalBusy) return;
    modalBusy = true;
    closeButton.disabled = true;
    cancelButton.disabled = true;
    confirmButton.disabled = true;
    confirmButton.classList.add('is-loading');
    confirmButton.setAttribute('aria-busy', 'true');
    const succeeded = await options.onConfirm(confirmButton);
    if (succeeded) {
      modalBusy = false;
      close(false);
      return;
    }
    modalBusy = false;
    closeButton.disabled = false;
    cancelButton.disabled = false;
    confirmButton.disabled = false;
    confirmButton.classList.remove('is-loading');
    confirmButton.removeAttribute('aria-busy');
    confirmButton.focus();
  });

  document.addEventListener('keydown', handleKeyDown);
  document.body.append(backdrop);
  window.requestAnimationFrame(() => cancelButton.focus());
}

function makeModalButton(label: string, modifier: 'is-secondary' | 'is-primary' | 'is-danger'): HTMLButtonElement {
  const button = document.createElement('button');
  button.type = 'button';
  button.className = `vfg-order-modal__button ${modifier}`;
  const buttonLabel = document.createElement('span');
  buttonLabel.textContent = label;
  const spinner = document.createElement('span');
  spinner.className = 'vfg-order-modal__spinner';
  spinner.setAttribute('aria-hidden', 'true');
  button.append(buttonLabel, spinner);
  return button;
}

function renderIdentifiers(container: HTMLElement, identifiers: Identifier[], config: PanelConfig): void {
  const fragment = document.createDocumentFragment();

  identifiers.forEach((identifier) => {
    const row = document.createElement('div');
    row.className = 'vfg-order-panel__row';
    row.dataset.vfgIdentifier = identifier.type;
    row.dataset.vfgBlocked = identifier.blocked ? '1' : '0';
    row.dataset.vfgActionable = identifier.actionable ? '1' : '0';

    const content = document.createElement('div');
    content.className = 'vfg-order-panel__content';

    const label = document.createElement('span');
    label.className = 'vfg-order-panel__label';
    label.textContent = config.strings[identifier.type];

    const value = document.createElement('span');
    value.className = 'vfg-order-panel__value';
    value.textContent = identifier.value;
    value.title = identifier.value;
    content.append(label, value);

    const meta = document.createElement('div');
    meta.className = 'vfg-order-panel__meta';
    row.append(content, meta);

    if (identifier.actionable) {
      const action = identifier.blocked ? 'unblock' : 'block';
      meta.append(
        makeButton(identifier.blocked ? config.strings.unblock : config.strings.block, `vfg-order-panel__button vfg-order-panel__action ${identifier.blocked ? 'is-unblock' : 'is-block'}`, {
          vfgAction: action,
          vfgType: identifier.type,
        }),
      );
    }

    fragment.append(row);
  });

  container.replaceChildren(fragment);
}

function updateOverview(panel: HTMLElement, identifiers: Identifier[], config: PanelConfig): void {
  const blockedCount = identifiers.filter((identifier) => identifier.blocked).length;
  const missingCount = identifiers.filter((identifier) => identifier.bulk_actionable && !identifier.blocked).length;
  const status = panel.querySelector<HTMLElement>('[data-vfg-status]');
  const statusTitle = panel.querySelector<HTMLElement>('[data-vfg-status-title]');
  const statusText = panel.querySelector<HTMLElement>('[data-vfg-status-text]');
  const primary = panel.querySelector<HTMLButtonElement>('[data-vfg-primary]');
  const primaryLabel = primary?.querySelector<HTMLElement>('[data-vfg-primary-label]');

  panel.dataset.blockedCount = String(blockedCount);
  status?.classList.toggle('has-blocked', blockedCount > 0);
  status?.classList.toggle('is-clear', blockedCount === 0);

  if (statusTitle) {
    statusTitle.textContent = blockedCount > 0 ? config.strings.blockedCustomer : config.strings.customerClear;
  }

  if (statusText) {
    statusText.textContent = config.strings.blockedCountLabels[blockedCount] ?? '';
  }

  if (primary && primaryLabel) {
    primary.dataset.focusAction = blockedCount > 0 ? 'unblock' : 'block';
    primary.dataset.primaryAction = missingCount > 0 ? 'bulk-block' : 'manage';
    primaryLabel.textContent = missingCount > 0 ? config.strings.blockCustomer : config.strings.manageBlocked;
    if (missingCount > 0) {
      primary.removeAttribute('aria-controls');
      primary.removeAttribute('aria-expanded');
    } else {
      const details = panel.querySelector<HTMLDetailsElement>('[data-vfg-details]');
      if (details) {
        primary.setAttribute('aria-controls', details.id);
        primary.setAttribute('aria-expanded', String(details.open));
      }
    }
  }
}

function makeButton(label: string, className: string, data: Record<string, string>): HTMLButtonElement {
  const button = document.createElement('button');
  button.type = 'button';
  button.className = className;
  const buttonLabel = document.createElement('span');
  buttonLabel.dataset.vfgButtonLabel = '';
  buttonLabel.textContent = label;
  const spinner = document.createElement('span');
  spinner.className = 'vfg-order-panel__spinner';
  spinner.setAttribute('aria-hidden', 'true');
  button.append(buttonLabel, spinner);
  Object.entries(data).forEach(([key, value]) => {
    button.dataset[key] = value;
  });
  return button;
}

function setButtonsDisabled(panel: HTMLElement, disabled: boolean): void {
  panel.querySelectorAll<HTMLButtonElement>('button').forEach((button) => {
    button.disabled = disabled;
  });
}

function buildEndpoint(baseUrl: string, orderId: number, type?: IdentifierType): string {
  const url = new URL(baseUrl);
  const path = type
    ? `/orders/${orderId}/blocklist/${type}`
    : `/orders/${orderId}/blocklist`;
  if (url.searchParams.has('rest_route')) {
    url.searchParams.set('rest_route', `${url.searchParams.get('rest_route')?.replace(/\/$/, '') ?? ''}${path}`);
  } else {
    url.pathname = `${url.pathname.replace(/\/$/, '')}${path}`;
  }
  return url.toString();
}
