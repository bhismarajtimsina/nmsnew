/// <reference types="vite/client" />
interface ImportMetaEnv {
  VITE_AUTH0_DOMAIN: string;
  VITE_AUTH0_CLIENT_ID: string;
  /** Base URL of the CyberSathy-NMS API for the typed client (src/api/client.ts); empty means same origin. */
  VITE_CS_API_BASE?: string;
}
