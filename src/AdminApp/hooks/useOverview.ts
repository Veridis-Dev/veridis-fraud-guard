import { useCallback, useEffect, useState } from '../wordpress-element';
import { getOverview } from '../api/overview';
import type { OverviewResponse } from '../types';

interface OverviewState {
  data: OverviewResponse | null;
  error: Error | null;
  loading: boolean;
}

export function useOverview() {
  const [revision, setRevision] = useState(0);
  const [state, setState] = useState<OverviewState>({ data: null, error: null, loading: true });

  useEffect(() => {
    const controller = new AbortController();
    setState(current => ({ ...current, error: null, loading: true }));

    getOverview(controller.signal)
      .then(data => setState({ data, error: null, loading: false }))
      .catch(error => {
        if (error instanceof DOMException && error.name === 'AbortError') return;
        setState({ data: null, error: error instanceof Error ? error : new Error(String(error)), loading: false });
      });

    return () => controller.abort();
  }, [revision]);

  const retry = useCallback(() => setRevision(current => current + 1), []);
  return { ...state, retry };
}
