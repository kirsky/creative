import { useId } from 'react';
import { useTranslation } from 'react-i18next';
import { fileHref } from './FileList';
import { formatFileSize } from '../../i18n/format';

/** @typedef {import('../../i18n/detect').Language} Language */

/**
 * The address of a reference when it is safe to link: http or https only. Anything else
 * (javascript:, data:, a relative path, text) is not a link.
 * @param {string} url
 * @returns {string | null}
 */
function safeHref(url) {
  try {
    const parsed = new URL(url);
    return parsed.protocol === 'http:' || parsed.protocol === 'https:' ? parsed.href : null;
  } catch {
    return null;
  }
}

/**
 * One reference of the brief.
 * @param {{reference: import('./api').Reference}} props
 */
function ReferenceItem({ reference }) {
  const { t, i18n } = useTranslation('common');
  const lng = /** @type {Language} */ (i18n.language);
  if (reference.type === 'file') {
    return (
      <li className="task-brief__reference">
        <a href={fileHref(reference.fileId)} download>
          {reference.name}
        </a>{' '}
        <span className="file-list__size">{formatFileSize(reference.size, lng, t)}</span>
      </li>
    );
  }
  const href = safeHref(reference.url);
  return (
    <li className="task-brief__reference">
      {href === null ? (
        <span className="task-brief__plain-url">{reference.url}</span>
      ) : (
        <a href={href} target="_blank" rel="noopener noreferrer">
          {reference.url}
        </a>
      )}
    </li>
  );
}

/**
 * The Brief section: the description as plain text (line breaks kept by the style) and the
 * references. Everything the buyer wrote is rendered as text, never as markup.
 * @param {{task: import('./api').TaskDetail}} props
 */
export function BriefPanel({ task }) {
  const { t } = useTranslation('task');
  const headingId = useId();
  return (
    <section className="task-section" aria-labelledby={headingId}>
      <h2 id={headingId} className="task-section__title">
        {t('brief.title')}
      </h2>
      {task.description.trim() === '' ? (
        <p className="task-section__empty">{t('brief.noDescription')}</p>
      ) : (
        <p className="task-brief__description">{task.description}</p>
      )}
      <h3 className="task-section__subtitle">{t('brief.references')}</h3>
      {task.references.length === 0 ? (
        <p className="task-section__empty">{t('brief.noReferences')}</p>
      ) : (
        <ul className="task-brief__references">
          {task.references.map((reference, index) => (
            <ReferenceItem key={index} reference={reference} />
          ))}
        </ul>
      )}
    </section>
  );
}
