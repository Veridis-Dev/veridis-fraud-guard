const configuredLocale = window.veridisFraudGuard?.locale || '';
const configuredTimeZone = window.veridisFraudGuard?.timeZone || '';

function supportedLocale(): string | undefined {
  const parts = configuredLocale.split('-').filter(Boolean);
  const candidates = [configuredLocale, parts.slice(0, 2).join('-'), parts[0]].filter(Boolean);

  for (const candidate of candidates) {
    try {
      if (Intl.NumberFormat.supportedLocalesOf([candidate]).length > 0) return candidate;
    } catch {
      // Try the less-specific WordPress locale candidate.
    }
  }

  return undefined;
}

const locale = supportedLocale();

export function formatInteger(value: number): string {
  return new Intl.NumberFormat(locale, { maximumFractionDigits: 0 }).format(value);
}

export function formatDecimal(value: number, fractionDigits = 1): string {
  return new Intl.NumberFormat(locale, {
    minimumFractionDigits: fractionDigits,
    maximumFractionDigits: fractionDigits,
  }).format(value);
}

export function formatUtcDate(value: string): string {
  return formatUtc(value, false);
}

export function formatUtcDateTime(value: string): string {
  return formatUtc(value, true);
}

function formatUtc(value: string, includeTime: boolean): string {
  const date = new Date(`${value.replace(' ', 'T')}Z`);
  if (Number.isNaN(date.getTime())) return value;

  const options: Intl.DateTimeFormatOptions = includeTime
    ? { dateStyle: 'medium', timeStyle: 'short' }
    : { dateStyle: 'medium' };
  const offset = /^([+-])(\d{2}):(\d{2})$/.exec(configuredTimeZone);

  if (offset) {
    const direction = offset[1] === '-' ? -1 : 1;
    const minutes = direction * (Number(offset[2]) * 60 + Number(offset[3]));
    const shifted = new Date(date.getTime() + minutes * 60_000);
    return new Intl.DateTimeFormat(locale, { ...options, timeZone: 'UTC' }).format(shifted);
  }

  try {
    return new Intl.DateTimeFormat(locale, {
      ...options,
      timeZone: configuredTimeZone || 'UTC',
    }).format(date);
  } catch {
    return new Intl.DateTimeFormat(locale, { ...options, timeZone: 'UTC' }).format(date);
  }
}
