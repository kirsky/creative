import { useTranslation } from 'react-i18next';
import { formatDuration } from '../../i18n/format';
import { Section } from './Section';

/**
 * Where the time goes: the four averages, as elapsed time ("—" when nothing was measured).
 * @param {{averages: import('./api').Dashboard['averages']}} props
 */
export function TimeBreakdown({ averages }) {
  const { t } = useTranslation('dashboard');
  const rows = [
    ['queueWait', averages.queueWaitSeconds],
    ['cycle', averages.cycleSeconds],
    ['review', averages.reviewSecondsPerRound],
    ['revision', averages.revisionTurnaroundSeconds],
  ];
  return (
    <Section title={t('time.title')} name="time">
      {(headingId) => (
        <>
          <div className="dashboard-section__scroll">
            <table className="dashboard-table" aria-labelledby={headingId}>
              <tbody>
                {rows.map(([key, seconds]) => (
                  <tr key={key}>
                    <td>{t(`time.${key}`)}</td>
                    <td className="dashboard-table__number">
                      <strong>{formatDuration(/** @type {number | null} */ (seconds), t)}</strong>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          <p className="dashboard-section__note">{t('time.note')}</p>
        </>
      )}
    </Section>
  );
}
