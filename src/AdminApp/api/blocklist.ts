import { apiGet, apiRequest } from './client';
import type { BlocklistEntry, BlocklistInput, BlocklistResponse, BlocklistType } from '../types';

export function getBlocklist(query: {
  search: string;
  type: BlocklistType | '';
  page: number;
  perPage: number;
}, signal?: AbortSignal): Promise<BlocklistResponse> {
  const params = new URLSearchParams({
    page: String(query.page),
    per_page: String(query.perPage),
  });
  if (query.search) params.set('search', query.search);
  if (query.type) params.set('type', query.type);
  return apiGet<BlocklistResponse>(`blocklist?${params.toString()}`, signal);
}

export function createBlocklistEntry(input: BlocklistInput): Promise<BlocklistEntry> {
  return apiRequest<BlocklistEntry>('blocklist', { method: 'POST', body: input });
}

export function updateBlocklistEntry(id: number, input: BlocklistInput): Promise<BlocklistEntry> {
  return apiRequest<BlocklistEntry>(`blocklist/${id}`, { method: 'PATCH', body: input });
}

export function deleteBlocklistEntry(id: number): Promise<{ deleted: true; id: number }> {
  return apiRequest<{ deleted: true; id: number }>(`blocklist/${id}`, { method: 'DELETE' });
}
