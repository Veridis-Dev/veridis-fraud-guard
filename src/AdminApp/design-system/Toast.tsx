import type { ReactNode } from 'react';
import { __ } from '@wordpress/i18n';
import { createContext, useCallback, useContext, useEffect, useRef, useState } from '../wordpress-element';
import { Button } from './Button';
import type { Tone } from '../types';

type ToastTone = Extract<Tone, 'success' | 'info' | 'warning' | 'error'>;
interface ToastMessage { id: number; message: string; tone: ToastTone }

const ToastContext = createContext<((message: string, tone?: ToastTone) => void) | null>(null);

export function ToastProvider({ children }: { children: ReactNode }) {
  const [messages, setMessages] = useState<ToastMessage[]>([]);
  const sequence = useRef(0);
  const notify = useCallback((message: string, tone: ToastTone = 'info') => {
    setMessages(current => {
      const withoutDuplicate = current.filter(toast => toast.message !== message || toast.tone !== tone);
      return [...withoutDuplicate.slice(-1), { id: ++sequence.current, message, tone }];
    });
  }, []);
  const dismiss = useCallback((id: number) => {
    setMessages(current => current.filter(message => message.id !== id));
  }, []);

  return (
    <ToastContext.Provider value={notify}>
      {children}
      <div className="vfg-toasts" aria-label={__('Notifications', 'veridis-fraud-guard')}>
        {messages.map(message => <ToastItem key={message.id} toast={message} onDismiss={dismiss} />)}
      </div>
    </ToastContext.Provider>
  );
}

function ToastItem({ toast, onDismiss }: { toast: ToastMessage; onDismiss: (id: number) => void }) {
  const [paused, setPaused] = useState(false);

  useEffect(() => {
    if (paused) return;
    const duration = toast.tone === 'error' ? 5500 : toast.tone === 'warning' ? 4500 : 3500;
    const timer = window.setTimeout(() => onDismiss(toast.id), duration);
    return () => window.clearTimeout(timer);
  }, [onDismiss, paused, toast.id, toast.tone]);

  return (
    <div
      className={`vfg-toast vfg-tone--${toast.tone}`}
      role={toast.tone === 'error' ? 'alert' : 'status'}
      onMouseEnter={() => setPaused(true)}
      onMouseLeave={() => setPaused(false)}
      onFocus={() => setPaused(true)}
      onBlur={event => { if (!event.currentTarget.contains(event.relatedTarget)) setPaused(false); }}
    >
      <span>{toast.message}</span>
      <Button variant="ghost" aria-label={__('Dismiss notification', 'veridis-fraud-guard')} onClick={() => onDismiss(toast.id)}>×</Button>
    </div>
  );
}

export function useToast() {
  const notify = useContext(ToastContext);
  if (!notify) throw new Error('useToast requires ToastProvider');
  return notify;
}
