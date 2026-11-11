import { useId } from 'react';
import { useTranslation } from 'react-i18next';
import { formatCredits, formatDateTime } from '../../i18n/format';
import { sumCredits } from './credits';
import { FileList } from './FileList';

/** @typedef {import('../../i18n/detect').Language} Language */

/**
 * The Deliverables section: the files of the latest round, or an empty state before the first one.
 * @param {{rounds: import('./api').Round[]}} props
 */
export function DeliverablesPanel({ rounds }) {
  const { t } = useTranslation('task');
  const headingId = useId();
  const latest = rounds.at(-1);
  return (
    <section className="task-section" aria-labelledby={headingId}>
      <h2 id={headingId} className="task-section__title">
        {latest === undefined
          ? t('deliverables.title')
          : t('deliverables.titleRound', { round: latest.number })}
      </h2>
      {latest === undefined ? (
        <p className="task-section__empty">{t('deliverables.empty')}</p>
      ) : latest.files.length === 0 ? (
        <p className="task-section__empty">{t('deliverables.noFiles')}</p>
      ) : (
        <FileList files={latest.files} />
      )}
    </section>
  );
}

/**
 * The AI usage lines of one round and their sum.
 * @param {{lines: import('./api').AiUsageLine[]}} props
 */
function AiUsage({ lines }) {
  const { t, i18n } = useTranslation('task');
  const lng = /** @type {Language} */ (i18n.language);
  return (
    <div className="round__ai">
      <h4 className="round__label">{t('rounds.ai.title')}</h4>
      <ul className="round__ai-lines">
        {lines.map((line, index) => (
          <li key={index}>
            {t('rounds.ai.line', {
              provider: line.provider,
              credits: formatCredits(line.credits, lng),
            })}
          </li>
        ))}
      </ul>
      <p className="round__ai-total">
        {t('rounds.ai.subtotal', {
          credits: formatCredits(sumCredits(lines.map((line) => line.credits)), lng),
        })}
      </p>
    </div>
  );
}

/**
 * One round: who submitted what and when, the designer's note, the buyer's decision with its
 * reason (or that it waits for review) and the AI usage.
 * @param {{round: import('./api').Round}} props
 */
function RoundItem({ round }) {
  const { t, i18n } = useTranslation('task');
  const lng = /** @type {Language} */ (i18n.language);
  const headingId = useId();
  const { decision } = round;
  return (
    <article className="round" data-decision={decision ?? 'waiting'} aria-labelledby={headingId}>
      <h3 id={headingId} className="round__title">
        {t('rounds.round', { round: round.number })}
      </h3>
      <p className="round__meta">
        {t('rounds.submitted', {
          name: round.submittedBy.displayName,
          date: formatDateTime(round.submittedAt, lng),
        })}
      </p>
      {round.note !== null && round.note.trim() !== '' && (
        <div>
          <h4 className="round__label">{t('rounds.note')}</h4>
          <p className="round__text">{round.note}</p>
        </div>
      )}
      {round.files.length > 0 && (
        <div>
          <h4 className="round__label">{t('rounds.files')}</h4>
          <FileList files={round.files} />
        </div>
      )}
      <div className="round__decision">
        {decision === null ? (
          <p className="round__status">{t('rounds.waiting')}</p>
        ) : (
          <>
            <p className="round__status">
              {t(`rounds.decision.${decision}`, { defaultValue: decision })}
              {round.decidedAt !== null && (
                <span className="round__decided">
                  {' '}
                  {t('rounds.decidedAt', { date: formatDateTime(round.decidedAt, lng) })}
                </span>
              )}
            </p>
            {round.decisionReason !== null && round.decisionReason.trim() !== '' && (
              <div>
                <h4 className="round__label">
                  {decision === 'approved' ? t('rounds.approvalNote') : t('rounds.reason')}
                </h4>
                <p className="round__text">{round.decisionReason}</p>
              </div>
            )}
          </>
        )}
      </div>
      {round.aiUsage.length > 0 && <AiUsage lines={round.aiUsage} />}
    </article>
  );
}

/**
 * The Revision rounds section, oldest first (Round 1, Round 2, ...).
 * @param {{rounds: import('./api').Round[]}} props
 */
export function RoundsPanel({ rounds }) {
  const { t } = useTranslation('task');
  const headingId = useId();
  return (
    <section className="task-section" aria-labelledby={headingId}>
      <h2 id={headingId} className="task-section__title">
        {t('rounds.title')}
      </h2>
      {rounds.length === 0 ? (
        <p className="task-section__empty">{t('rounds.empty')}</p>
      ) : (
        <div className="task-rounds">
          {rounds.map((round) => (
            <RoundItem key={round.number} round={round} />
          ))}
        </div>
      )}
    </section>
  );
}
