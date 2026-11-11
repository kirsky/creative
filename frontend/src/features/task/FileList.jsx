import { useTranslation } from 'react-i18next';
import { formatFileSize } from '../../i18n/format';

/** @typedef {import('../../i18n/detect').Language} Language */

/**
 * The authenticated download of a stored file (it answers with Content-Disposition: attachment).
 * @param {number} fileId
 */
export function fileHref(fileId) {
  return `/api/files/${fileId}`;
}

/**
 * Files as download links with their sizes. Names are user text and are rendered as text.
 * @param {object} props
 * @param {{id: number, name: string, size: number}[]} props.files
 */
export function FileList({ files }) {
  const { t, i18n } = useTranslation('common');
  const lng = /** @type {Language} */ (i18n.language);
  return (
    <ul className="file-list">
      {files.map((file) => (
        <li key={file.id} className="file-list__item">
          <a className="file-list__link" href={fileHref(file.id)} download>
            {file.name}
          </a>{' '}
          <span className="file-list__size">{formatFileSize(file.size, lng, t)}</span>
        </li>
      ))}
    </ul>
  );
}
