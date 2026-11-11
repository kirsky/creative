import { Alert, Anchor, Button, Skeleton, Title } from '@mantine/core';
import { useId } from 'react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';
import { ApiRequestError } from '../../api/ApiRequestError';
import { translateApiError } from '../../i18n/errors';
import { useAuth } from '../auth/useAuth';
import { TaskCard } from '../board/TaskCard';
import { usePickUpAction } from '../board/usePickUpAction';
import { useRetry } from '../task/useRetry';
import { useMyWork } from './api';
import { Counters } from './Counters';
import './MyWork.scss';

/** How many placeholder cards a section shows while the board loads. */
const SKELETON_CARDS = 2;

/**
 * One section of the page: a heading with the count, then the cards, the placeholders or a message.
 * @param {object} props
 * @param {string} props.name the section, for styling and tests: assigned or available
 * @param {string} props.title
 * @param {boolean} props.prominent the section is the main thing on the page
 * @param {boolean} props.isLoading
 * @param {number} props.count
 * @param {string} props.emptyText
 * @param {import('react').ReactNode} props.children the list items
 */
function WorkSection({ name, title, prominent, isLoading, count, emptyText, children }) {
  const headingId = useId();
  return (
    <section
      className="my-work__section"
      aria-labelledby={headingId}
      data-section={name}
      data-prominent={prominent ? 'true' : undefined}
    >
      <h2 id={headingId} className="my-work__heading">
        {title}
        {!isLoading && (
          <>
            {' '}
            <span className="my-work__count">{count}</span>
          </>
        )}
      </h2>
      {isLoading ? (
        <div className="my-work__list">
          {Array.from({ length: SKELETON_CARDS }, (_, index) => (
            <Skeleton key={index} height={96} radius="md" data-testid="card-skeleton" />
          ))}
        </div>
      ) : count === 0 ? (
        <p className="my-work__empty">{emptyText}</p>
      ) : (
        <ul className="my-work__list">{children}</ul>
      )}
    </section>
  );
}

/** The designer's landing page: counters, their own tasks, and the pool to pick from. */
export function MyWorkPage() {
  const { t } = useTranslation(['mywork', 'errors', 'common']);
  const { user } = useAuth();
  const { query, work } = useMyWork(user?.id ?? 0);
  const failure = useRetry(query);
  const { pickUp, pendingId } = usePickUpAction();

  const error = failure.error;
  // A 401 is being handled (the user is on the way to the login page): no error screen for it.
  const showError =
    work === undefined &&
    error !== null &&
    !(error instanceof ApiRequestError && error.status === 401);
  const isLoading = work === undefined && !showError;
  const focusAvailable = work !== undefined && work.assigned.length === 0;
  const isEmpty = work !== undefined && work.assigned.length === 0 && work.available.length === 0;

  const assigned = (
    <WorkSection
      key="assigned"
      name="assigned"
      title={t('mywork:assigned.title')}
      prominent={false}
      isLoading={isLoading}
      count={work?.assigned.length ?? 0}
      emptyText={t('mywork:assigned.empty')}
    >
      {work?.assigned.map((task) => (
        <li
          key={task.id}
          className="my-work__item"
          data-attention={task.status === 'revisions_required' ? 'true' : undefined}
        >
          {task.status === 'revisions_required' && (
            <p className="my-work__attention">{t('mywork:attention')}</p>
          )}
          <TaskCard task={task} showStatus />
        </li>
      ))}
    </WorkSection>
  );
  const available = (
    <WorkSection
      key="available"
      name="available"
      title={t('mywork:available.title')}
      prominent={focusAvailable}
      isLoading={isLoading}
      count={work?.available.length ?? 0}
      emptyText={t('mywork:available.empty')}
    >
      {work?.available.map((task) => (
        <li key={task.id} className="my-work__item">
          <TaskCard
            task={task}
            onPickUp={pickUp}
            isPickingUp={pendingId === task.id}
            pickUpDisabled={pendingId !== null && pendingId !== task.id}
          />
        </li>
      ))}
    </WorkSection>
  );

  return (
    <div
      className="my-work"
      data-testid="my-work"
      data-focus={focusAvailable ? 'available' : undefined}
    >
      <Title order={1}>{t('mywork:title')}</Title>

      {showError ? (
        <Alert color="red" title={t('mywork:error.title')}>
          <p className="my-work__error-text">
            {error instanceof ApiRequestError ? translateApiError(t, error) : t('errors:unknown')}
          </p>
          <Button
            variant="light"
            color="red"
            size="xs"
            loading={failure.retrying}
            onClick={() => void failure.retry()}
          >
            {t('common:retry')}
          </Button>
        </Alert>
      ) : (
        <>
          <Counters counts={work?.counts} />
          {isEmpty ? (
            <div className="my-work__nothing">
              <p className="my-work__nothing-title">{t('mywork:nothing.title')}</p>
              <p className="my-work__empty">{t('mywork:nothing.text')}</p>
            </div>
          ) : focusAvailable ? (
            [available, assigned]
          ) : (
            [assigned, available]
          )}
          <Anchor component={Link} to="/board" className="my-work__board-link">
            {t('mywork:openBoard')}
          </Anchor>
        </>
      )}
    </div>
  );
}
