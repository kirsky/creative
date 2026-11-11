/** The file limit shown to users as "99 MB" (MAX_UPLOAD_BYTES of the server). */
export const MAX_FILE_BYTES = 99_000_000;
/** At most this many references (links and files together) fit in one task. */
export const MAX_REFERENCES = 50;

/**
 * One file in the uploader's list.
 * @typedef {object} UploadItem
 * @property {number} key identifies the item in the list
 * @property {string} name the name on the user's disk
 * @property {number} size bytes
 * @property {'queued' | 'uploading' | 'uploaded' | 'error'} status
 * @property {File} [file] kept until the upload has finished
 * @property {number} [id] the id of the stored file, once uploaded
 * @property {import('../../api/ApiRequestError').ApiRequestError} [error] why it failed
 */

/** @param {UploadItem[]} items */
export function isUploading(items) {
  return items.some((item) => item.status === 'queued' || item.status === 'uploading');
}

/** @param {UploadItem[]} items @returns {number[]} ids of the files that can be attached */
export function uploadedFileIds(items) {
  return items.flatMap((item) =>
    item.status === 'uploaded' && item.id !== undefined ? [item.id] : [],
  );
}

/** @param {UploadItem[]} items */
export function failedFileCount(items) {
  return items.filter((item) => item.status === 'error').length;
}

/** How many references the files take: errored files are not attached, so they take no room. */
export function attachableFileCount(/** @type {UploadItem[]} */ items) {
  return items.filter((item) => item.status !== 'error').length;
}
