/**
 * @typedef {object} FieldError
 * @property {string} field
 * @property {string} code
 * @property {Record<string, unknown>} params
 */

/**
 * Every failed API call. The API sends codes, never sentences; the UI translates them.
 * `status` is 0 when there was no HTTP response at all (network failure).
 */
export class ApiRequestError extends Error {
  /**
   * @param {number} status HTTP status
   * @param {string} code API error code, or 'unknown' / 'payload_too_large' / 'network_error'
   * @param {FieldError[]} [fields]
   * @param {Record<string, unknown>} [params]
   */
  constructor(status, code, fields = [], params = {}) {
    super(`${code} (HTTP ${status})`);
    this.name = 'ApiRequestError';
    this.status = status;
    this.code = code;
    this.fields = fields;
    this.params = params;
  }
}
