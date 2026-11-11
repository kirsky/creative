import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';
import { formatDateTime, formatDuration, formatNumber } from '../../i18n/format';
import { NoData, Section } from './Section';

/**
 * The overdue tasks the API sent (at most 50, earliest deadline first): the offer links to the
 * task, then who has it, its status and how late it is. When there are more, says so.
 * @param {{overdue: import('./api').Dashboard['overdue']}} props
 */
export function OverdueList({ overdue }) {
  const { t, i18n } = useTranslation(['dashboard', 'board', 'common']);
  const lng = /** @type {import('../../i18n/detect').Language} */ (i18n.language);
  const { count, tasks } = overdue;

  return (
    <Section title={t('dashboard:overdue.title')} name="overdue" danger={count > 0}>
      {() =>
        tasks.length === 0 ? (
          <NoData text={t('dashboard:noData')} />
        ) : (
          <>
            <ul className="overdue-list">
              {tasks.map((task) => {
                const lateSeconds = Math.max(0, (Date.now() - Date.parse(task.deadlineAt)) / 1000);
                return (
                  <li key={task.id} className="overdue-list__item">
                    <Link to={`/tasks/${task.id}`} className="overdue-list__offer">
                      {task.offer}
                    </Link>
                    <span className="overdue-list__assignee">
                      {task.assignee?.displayName ?? t('dashboard:overdue.unassigned')}
                    </span>
                    <span className="overdue-list__status">
                      {t(`board:card.status.${task.status}`, { defaultValue: task.status })}
                    </span>
                    <span
                      className="overdue-list__late"
                      title={formatDateTime(task.deadlineAt, lng)}
                    >
                      {t('dashboard:overdue.late', { duration: formatDuration(lateSeconds, t) })}
                    </span>
                  </li>
                );
              })}
            </ul>
            {count > tasks.length && (
              <p className="dashboard-section__note">
                {t('dashboard:overdue.showing', {
                  shown: formatNumber(tasks.length, lng),
                  total: formatNumber(count, lng),
                })}
              </p>
            )}
          </>
        )
      }
    </Section>
  );
}
