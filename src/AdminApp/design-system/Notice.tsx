import type { ReactNode } from 'react';
import type { Tone } from '../types';

export function Notice({ title, children, tone = 'info' }: {
  title: string;
  children: ReactNode;
  tone?: Extract<Tone, 'info' | 'warning' | 'error'>;
}) {
  return (
    <div className={`vfg-notice vfg-tone--${tone}`} role={tone === 'error' ? 'alert' : 'note'}>
      <strong>{title}</strong>
      <p>{children}</p>
    </div>
  );
}
