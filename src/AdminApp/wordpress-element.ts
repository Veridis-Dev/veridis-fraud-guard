import type * as ReactRuntime from 'react';
import type { createRoot as createReactRoot } from 'react-dom/client';

type WordPressElementRuntime = typeof ReactRuntime & {
  createRoot: typeof createReactRoot;
};

const element = (window as unknown as { wp?: { element?: WordPressElementRuntime } }).wp?.element;

if (!element) {
  throw new Error('The WordPress element runtime is unavailable.');
}

export const {
  Component,
  Fragment,
  StrictMode,
  createContext,
  createElement,
  useCallback,
  useContext,
  useEffect,
  useId,
  useMemo,
  useRef,
  useState,
} = element;

export const createRoot = element.createRoot;

type JsxProps = Record<string, unknown> | null;

function renderJsx(
  type: ReactRuntime.ElementType,
  props: JsxProps,
  key?: ReactRuntime.Key,
): ReactRuntime.ReactElement {
  const nextProps = key === undefined ? props : { ...props, key };
  return createElement(type, nextProps) as ReactRuntime.ReactElement;
}

export function jsx(
  type: ReactRuntime.ElementType,
  props: JsxProps,
  key?: ReactRuntime.Key,
): ReactRuntime.ReactElement {
  return renderJsx(type, props, key);
}

export const jsxs = jsx;

export function jsxDEV(
  type: ReactRuntime.ElementType,
  props: JsxProps,
  key?: ReactRuntime.Key,
  _isStaticChildren?: boolean,
  _source?: unknown,
  _self?: unknown,
): ReactRuntime.ReactElement {
  return renderJsx(type, props, key);
}
