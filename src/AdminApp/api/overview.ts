import { apiGet } from './client';
import type { OverviewResponse } from '../types';

export function getOverview(signal?: AbortSignal): Promise<OverviewResponse> {
  return apiGet<OverviewResponse>('overview', signal);
}
