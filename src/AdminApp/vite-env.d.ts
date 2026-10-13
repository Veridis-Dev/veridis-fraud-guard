/// <reference types="vite/client" />

interface Window {
  veridisFraudGuard?: {
    restUrl: string;
    nonce: string;
    locale: string;
    timeZone: string;
    exportUrl: string;
    version: string;
  };
}
