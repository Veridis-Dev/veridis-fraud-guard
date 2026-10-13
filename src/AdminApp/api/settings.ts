import { __ } from '@wordpress/i18n';
import { apiGet, apiRequest, ApiError } from './client';
import { checkoutModes, type CheckoutSettingsInput, type CheckoutSettingsResponse } from '../types';

export async function getCheckoutSettings(signal?: AbortSignal): Promise<CheckoutSettingsResponse> {
  return validateResponse(await apiGet<CheckoutSettingsResponse>('settings/checkout-protection', signal));
}

export async function saveCheckoutSettings(settings: CheckoutSettingsInput): Promise<CheckoutSettingsResponse> {
  return validateResponse(await apiRequest<CheckoutSettingsResponse>('settings/checkout-protection', {
    method: 'POST',
    body: settings,
  }));
}

function validateResponse(response: CheckoutSettingsResponse): CheckoutSettingsResponse {
  if (
    !checkoutModes.includes(response.mode)
    || typeof response.message !== 'string'
    || !Number.isInteger(response.message_max_length)
    || response.message_max_length < 1
  ) {
    throw new ApiError(__('The server returned invalid checkout settings.', 'veridis-fraud-guard'), 0);
  }
  return response;
}
