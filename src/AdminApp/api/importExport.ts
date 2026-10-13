import { __ } from '@wordpress/i18n';
import { apiRequest, ApiError } from './client';
import type { BlocklistImportResult } from '../types';

export async function importBlocklist(file: File): Promise<BlocklistImportResult> {
  const formData = new FormData();
  formData.append('file', file, file.name);

  try {
    return await apiRequest<BlocklistImportResult>('blocklist/import', { method: 'POST', formData });
  } catch (error) {
    if (error instanceof ApiError) throw error;
    throw new ApiError(__('The CSV could not be uploaded. Check your connection and try again.', 'veridis-fraud-guard'), 0);
  }
}
