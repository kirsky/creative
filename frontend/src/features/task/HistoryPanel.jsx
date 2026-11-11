import { Accordion, Alert, Button, Skeleton } from '@mantine/core';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { ApiRequestError } from '../../api/ApiRequestError';
import { translateApiError } from '../../i18n/errors';
import { formatDateTime } from '../../i18n/format';
import { useTaskHistory } from './api';
import { renderEvent, renderEventDetails } from './eventText';

/** @typedef {import('../../i18n/detect').Language} Language */

const ITEM = 'history';

/** How many placeholder lines the timeline shows while it loads. */
const SKELETON_LINES = 3;

/**
 * The vertical timeline of events, oldest first.
 * @param {{events: import('./api').TaskEvent[], label: string}} props
 */
function Timeline({ events, label }) {
  const { t, i18n } = useTranslation(['task', 'common']);
  const lng = /** @type {Language} */ (i18n.language);
  return (
    <ol className="timeline" aria-label={label}>
      {events.map((event) => {
        const details = renderEventDetails(t, event, lng);
        return (
          <li key={event.id} className="timeline__item" data-event-type={event.type}>
            <time className="timeline__time" dateTime={event.at}>
              {formatDateTime(event.at, lng)}
            </time>
            <p className="timeline__text" data-testid="event-text">
              {renderEvent(t, event, lng)}
            </p>
            {details.length > 0 && (
              <ul className="timeline__details">
                {details.map((line, index) => (
                  <li key={index}>{line}</li>
                ))}
              </ul>
            )}
          </li>
        );
      })}
    </ol>
  );
}

/**
 * The activity history, collapsed at the bottom of the page. The events are requested the first
 * time it is expanded and not before; collapsing it again keeps what was loaded.
 * @param {{taskId: number}} props
 */
export function HistoryPanel({ taskId }) {
  const { t } = useTranslation(['task', 'errors', 'common']);
  const [opened, setOpened] = useState(false);
  const [requested, setRequested] = useState(false);
  const history = useTaskHistory(taskId, { enabled: requested });

  /** @param {string | null} value */
  function handleChange(value) {
    setOpened(value === ITEM);
    if (value === ITEM) {
      setRequested(true);
    }
  }

  return (
    <section className="task-section task-history" aria-label={t('task:history.title')}>
      <Accordion variant="contained" order={2} value={opened ? ITEM : null} onChange={handleChange}>
        <Accordion.Item value={ITEM}>
          <Accordion.Control>{t('task:history.title')}</Accordion.Control>
          <Accordion.Panel>
            {history.isError ? (
              <Alert color="red" title={t('task:history.error')}>
                <p className="task-history__error-text">
                  {history.error instanceof ApiRequestError
                    ? translateApiError(t, history.error)
                    : t('errors:unknown')}
                </p>
                <Button
                  variant="light"
                  color="red"
                  size="xs"
                  onClick={() => void history.refetch()}
                >
                  {t('common:retry')}
                </Button>
              </Alert>
            ) : history.data === undefined ? (
              requested && (
                <div role="status" aria-label={t('common:loading')}>
                  {Array.from({ length: SKELETON_LINES }, (_, index) => (
                    <Skeleton key={index} height={16} mt="xs" />
                  ))}
                </div>
              )
            ) : history.data.events.length === 0 ? (
              <p className="task-section__empty">{t('task:history.empty')}</p>
            ) : (
              <Timeline events={history.data.events} label={t('task:history.title')} />
            )}
          </Accordion.Panel>
        </Accordion.Item>
      </Accordion>
    </section>
  );
}
