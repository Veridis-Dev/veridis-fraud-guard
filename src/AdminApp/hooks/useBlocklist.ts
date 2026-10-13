import { useCallback, useEffect, useRef, useState } from '../wordpress-element';
import { getBlocklist } from '../api/blocklist';
import type { BlocklistResponse, BlocklistType } from '../types';

export function useBlocklist(query: { search: string; type: BlocklistType | ''; page: number; perPage: number }) {
  const [revision, setRevision] = useState(0);
  const [result, setResult] = useState<{ data: BlocklistResponse; key: string } | null>(null);
  const [failure, setFailure] = useState<{ error: Error; key: string } | null>(null);
  const [loading, setLoading] = useState(true);
  const requestSequence = useRef(0);
  const requestedKey = useRef<string | null>(null);
  const queryKey = `${query.search}\u0000${query.type}\u0000${query.page}\u0000${query.perPage}`;

  useEffect(() => {
    const controller = new AbortController();
    const requestId = ++requestSequence.current;
    const requestKey = queryKey;
    requestedKey.current = requestKey;
    setLoading(true);
    setFailure(null);

    getBlocklist(query, controller.signal)
      .then(response => {
        if (requestSequence.current !== requestId) return;
        setResult({ data: response, key: requestKey });
        setLoading(false);
      })
      .catch(requestError => {
        if (
          requestSequence.current !== requestId
          || (requestError instanceof DOMException && requestError.name === 'AbortError')
        ) return;
        setFailure({
          error: requestError instanceof Error ? requestError : new Error(String(requestError)),
          key: requestKey,
        });
        setLoading(false);
      });

    return () => {
      controller.abort();
      if (requestSequence.current === requestId) requestSequence.current += 1;
    };
  }, [query.page, query.perPage, query.search, query.type, queryKey, revision]);

  const refresh = useCallback(() => setRevision(current => current + 1), []);
  const data = result?.key === queryKey ? result.data : null;
  const queryChanged = requestedKey.current !== queryKey;
  const error = !queryChanged && failure?.key === queryKey ? failure.error : null;

  return {
    data,
    latestData: result?.data ?? null,
    error,
    initialLoading: data === null && (queryChanged || loading),
    isFetching: data !== null && (queryChanged || loading),
    refresh,
  };
}
