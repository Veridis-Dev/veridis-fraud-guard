import type { ReactNode } from 'react';
import { useId } from '../wordpress-element';

export function Card({ title, titleIcon, action, children, className = '' }: {
  title?: ReactNode;
  titleIcon?: ReactNode;
  action?: ReactNode;
  children: ReactNode;
  className?: string;
}) {
  const headingId = useId();
  return (
    <section className={`vfg-card ${className}`.trim()} aria-labelledby={title ? headingId : undefined}>
      {title ? <header className="vfg-card-header"><h3 id={headingId}>{titleIcon}{title}</h3>{action}</header> : null}
      <div className="vfg-card-body">{children}</div>
    </section>
  );
}
