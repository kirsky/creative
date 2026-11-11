import { Paperclip } from '@phosphor-icons/react';
import { Icon } from '../../components/Icon';
import { Button, CloseButton, FileButton, Input, Loader, Text } from '@mantine/core';
import { useEffect, useRef } from 'react';
import { useTranslation } from 'react-i18next';
import { ApiRequestError } from '../../api/ApiRequestError';
import { translateApiError, translateFieldError } from '../../i18n/errors';
import { isLanguage } from '../../i18n/detect';
import { formatFileSize } from '../../i18n/format';
import { uploadFile } from './api';
import { MAX_FILE_BYTES, MAX_REFERENCES } from './files';
import './FileUploader.scss';

/**
 * @param {import('i18next').TFunction} t
 * @param {ApiRequestError} error
 */
function describeError(t, error) {
  // Not an API code: the list is full, so the file was not even tried.
  return error.code === 'too_many'
    ? translateFieldError(t, { code: 'too_many', params: error.params })
    : translateApiError(t, error);
}

/**
 * Attaches files to the brief. A file is uploaded the moment it is picked, one at a time (one
 * request per file), and its row shows waiting, uploading, uploaded or why it failed. A file over
 * 99 MB or beyond the room left is refused without a request. Removing a row only detaches the
 * file on this side (an upload in flight is cancelled; a stored file stays on the server).
 *
 * The list lives in the parent (it needs the ids and the "still uploading" state), so `onChange`
 * is a React state setter: it must accept an updater function, because uploads finish later.
 * @param {{
 *   value: import('./files').UploadItem[],
 *   onChange: import('react').Dispatch<import('react').SetStateAction<import('./files').UploadItem[]>>,
 *   maxFiles?: number,
 * }} props `maxFiles` is how many files (not counting failed ones) may be in the list
 */
export function FileUploader({ value, onChange, maxFiles = MAX_REFERENCES }) {
  const { t, i18n } = useTranslation('task');
  const language = isLanguage(i18n.language) ? i18n.language : 'en';
  const nextKey = useRef(1);
  const queue = useRef(Promise.resolve());
  const controllers = useRef(/** @type {Map<number, AbortController>} */ (new Map()));
  const removed = useRef(/** @type {Set<number>} */ (new Set()));
  const closed = useRef(false);
  const resetPicker = useRef(/** @type {(() => void) | null} */ (null));

  useEffect(() => {
    closed.current = false;
    const running = controllers.current;
    return () => {
      closed.current = true;
      for (const controller of running.values()) {
        controller.abort();
      }
    };
  }, []);

  /**
   * @param {number} key
   * @param {Partial<import('./files').UploadItem>} patch
   */
  function patchItem(key, patch) {
    onChange((items) => items.map((item) => (item.key === key ? { ...item, ...patch } : item)));
  }

  /** @param {import('./files').UploadItem} item */
  async function upload(item) {
    const { key, file } = item;
    if (closed.current || removed.current.has(key) || file === undefined) {
      return;
    }
    const controller = new AbortController();
    controllers.current.set(key, controller);
    patchItem(key, { status: 'uploading' });
    try {
      const stored = await uploadFile(file, { signal: controller.signal });
      patchItem(key, { status: 'uploaded', id: stored.id, file: undefined });
    } catch (error) {
      if (controller.signal.aborted) {
        return;
      }
      patchItem(key, {
        status: 'error',
        error: error instanceof ApiRequestError ? error : new ApiRequestError(0, 'unknown'),
        file: undefined,
      });
    } finally {
      controllers.current.delete(key);
    }
  }

  /** @param {File[] | File | null} picked */
  function addFiles(picked) {
    resetPicker.current?.();
    const files = picked === null ? [] : Array.isArray(picked) ? picked : [picked];
    let room = maxFiles - value.filter((item) => item.status !== 'error').length;
    /** @type {import('./files').UploadItem[]} */
    const added = files.map((file) => {
      const base = { key: nextKey.current++, name: file.name, size: file.size };
      if (file.size > MAX_FILE_BYTES) {
        return {
          ...base,
          status: 'error',
          error: new ApiRequestError(413, 'file_too_large', [], { maxBytes: MAX_FILE_BYTES }),
        };
      }
      if (room <= 0) {
        return {
          ...base,
          status: 'error',
          error: new ApiRequestError(0, 'too_many', [], { max: maxFiles }),
        };
      }
      room -= 1;
      return { ...base, status: 'queued', file };
    });
    if (added.length === 0) {
      return;
    }
    onChange((items) => [...items, ...added]);
    // One after another: a slow connection is not split between many large uploads.
    for (const item of added.filter((candidate) => candidate.status === 'queued')) {
      queue.current = queue.current.then(() => upload(item));
    }
  }

  /** @param {number} key */
  function remove(key) {
    removed.current.add(key);
    controllers.current.get(key)?.abort();
    onChange((items) => items.filter((item) => item.key !== key));
  }

  return (
    <Input.Wrapper
      labelElement="div"
      label={t('create.references.filesLabel')}
      description={t('create.references.fileHint', { maxMb: MAX_FILE_BYTES / 1_000_000 })}
    >
      <div className="file-uploader">
        <FileButton onChange={addFiles} multiple resetRef={resetPicker}>
          {(props) => (
            <Button
              {...props}
              variant="default"
              leftSection={<Icon icon={Paperclip} />}
              className="file-uploader__attach"
            >
              {t('create.references.attach')}
            </Button>
          )}
        </FileButton>
        {value.length > 0 && (
          <ul className="file-uploader__list" aria-live="polite">
            {value.map((item) => (
              <li key={item.key} className="file-uploader__item">
                <div className="file-uploader__body">
                  <div className="file-uploader__summary">
                    <span className="file-uploader__name">{item.name}</span>
                    <Text span size="xs" c="dimmed">
                      {formatFileSize(item.size, language, t)}
                    </Text>
                    {item.status !== 'error' && (
                      <Text span size="xs" c={item.status === 'uploaded' ? 'teal' : 'dimmed'}>
                        {item.status === 'uploading' && <Loader size={12} />}
                        {t(`create.references.status.${item.status}`)}
                      </Text>
                    )}
                  </div>
                  {item.status === 'error' && item.error !== undefined && (
                    <Text size="sm" c="red">
                      {describeError(t, item.error)}
                    </Text>
                  )}
                </div>
                <CloseButton
                  size="sm"
                  aria-label={t('create.references.removeFile', { name: item.name })}
                  onClick={() => remove(item.key)}
                />
              </li>
            ))}
          </ul>
        )}
      </div>
    </Input.Wrapper>
  );
}
