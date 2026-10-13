import { apiGet } from './client';
import type { ActivityFilter, ActivityResponse } from '../types';

export function getActivity(query: {
  eventType: ActivityFilter;
  page: number;
  perPage: number;
}, signal?: AbortSignal): Promise<ActivityResponse> {
  const params = new URLSearchParams({
    page: String(query.page),
    per_page: String(query.perPage),
  });
  if (query.eventType) params.set('event_type', query.eventType);

  return apiGet<ActivityResponse>(`activity?${params.toString()}`, signal);
}
