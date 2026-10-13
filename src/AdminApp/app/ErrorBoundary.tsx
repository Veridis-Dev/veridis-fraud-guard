import type { ErrorInfo, ReactNode } from 'react';
import { __ } from '@wordpress/i18n';
import { Component } from '../wordpress-element';
import { Button } from '../design-system/Button';
import { EmptyState } from '../design-system/EmptyState';

export class ErrorBoundary extends Component<{ children: ReactNode }, { failed: boolean }> {
  state = { failed: false };

  static getDerivedStateFromError() {
    return { failed: true };
  }

  componentDidCatch(error: Error, info: ErrorInfo) {
    if (import.meta.env.DEV) console.error(error, info);
  }

  render() {
    if (!this.state.failed) return this.props.children;
    return (
      <EmptyState
        title={__('Something interrupted Fraud Guard', 'veridis-fraud-guard')}
        description={__('Reload the page to start the administration application again.', 'veridis-fraud-guard')}
        action={<Button onClick={() => window.location.reload()}>{__('Reload page', 'veridis-fraud-guard')}</Button>}
      />
    );
  }
}
