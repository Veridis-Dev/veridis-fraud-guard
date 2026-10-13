import type { CSSProperties } from 'react';

export function Skeleton({ className = '', style }: { className?: string; style?: CSSProperties }) {
  return <span className={`vfg-skeleton ${className}`.trim()} style={style} aria-hidden="true" />;
}
