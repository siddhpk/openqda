/**
 * Base64 for binary co-editing updates (the live channel and the server
 * carry them as base64 strings).
 * @module
 */

/**
 * @param bytes {Uint8Array}
 * @return {string}
 */
export const bytesToB64 = (bytes) => {
  let binary = '';
  const chunk = 0x8000;
  for (let i = 0; i < bytes.length; i += chunk) {
    binary += String.fromCharCode.apply(null, bytes.subarray(i, i + chunk));
  }
  return btoa(binary);
};

/**
 * @param b64 {string}
 * @return {Uint8Array}
 */
export const b64ToBytes = (b64) => {
  const binary = atob(b64);
  const bytes = new Uint8Array(binary.length);
  for (let i = 0; i < binary.length; i++) bytes[i] = binary.charCodeAt(i);
  return bytes;
};
