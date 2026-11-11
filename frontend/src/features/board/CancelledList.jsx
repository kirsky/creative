import { useId } from 'react';
import { useTranslation } from 'react-i18next';
import { TaskCard } from './TaskCard';

/**
 * The cancelled tasks, below the board. They are no column: nothing can be dragged or picked up.
 * @param {{tasks: import('./api').BoardTask[]}} props
 */
export function CancelledList({ tasks }) {
  const { t } = useTranslation('board');
  const headingId = useId();
  return (
    <section className="cancelled-list" aria-labelledby={headingId}>
      <h2 id={headingId} className="cancelled-list__title">
        {t('columns.cancelled')} <span className="board-column__count">{tasks.length}</span>
      </h2>
      {tasks.length === 0 ? (
        <p className="board-column__empty">{t('empty.cancelled')}</p>
      ) : (
        <ul className="cancelled-list__items">
          {tasks.map((task) => (
            <li key={task.id}>
              <TaskCard task={task} showStatus />
            </li>
          ))}
        </ul>
      )}
    </section>
  );
}
