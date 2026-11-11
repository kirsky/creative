import { useDndContext, useDraggable, useDroppable } from '@dnd-kit/core';
import { Skeleton } from '@mantine/core';
import { DotsSixVertical } from '@phosphor-icons/react';
import { useId } from 'react';
import { useTranslation } from 'react-i18next';
import { Icon } from '../../components/Icon';
import { canDrag, IN_PROGRESS_COLUMN, resolveDrop } from './dragDrop';
import { TaskCard } from './TaskCard';

/** How many placeholder cards a column shows while the board loads. */
const SKELETON_CARDS = 2;

/**
 * A card a designer may pick up: dragged by pointer anywhere on it (after a few pixels, so a click
 * still opens the task) or by keyboard on its handle (Enter on the offer link must keep opening the
 * task, so the keyboard drag lives on the handle only).
 * @param {object} props
 * @param {import('./api').BoardTask} props.task
 * @param {(task: import('./api').BoardTask) => void} props.onPickUp
 * @param {boolean} props.isPickingUp
 * @param {boolean} props.pickUpDisabled
 */
function DraggableTaskCard({ task, onPickUp, isPickingUp, pickUpDisabled }) {
  const { t } = useTranslation('board');
  const { attributes, listeners, setNodeRef, setActivatorNodeRef, isDragging } = useDraggable({
    id: task.id,
    data: { task },
    disabled: isPickingUp || pickUpDisabled,
  });
  const { onPointerDown, onKeyDown } = /** @type {Record<string, any>} */ (listeners ?? {});
  return (
    <div
      ref={setNodeRef}
      className="board-column__slot"
      data-dragging={isDragging ? 'true' : undefined}
      onPointerDown={onPointerDown}
    >
      <TaskCard
        task={task}
        onPickUp={onPickUp}
        isPickingUp={isPickingUp}
        pickUpDisabled={pickUpDisabled}
        handle={
          <button
            type="button"
            ref={setActivatorNodeRef}
            className="task-card__handle"
            aria-label={t('card.dragHandle', { offer: task.offer })}
            {...attributes}
            onKeyDown={onKeyDown}
          >
            <Icon icon={DotsSixVertical} size={18} weight="bold" />
          </button>
        }
      />
    </div>
  );
}

/**
 * One column of the board. Only the in_progress column is a drop target.
 * @param {object} props
 * @param {import('./dragDrop').BoardColumnId} props.status
 * @param {import('./api').BoardTask[]} props.tasks
 * @param {boolean} props.isLoading show skeleton cards instead of the tasks
 * @param {(task: import('./api').BoardTask) => void} props.onPickUp
 * @param {number | null} props.pickingId the task being picked up
 */
export function BoardColumn({ status, tasks, isLoading, onPickUp, pickingId }) {
  const { t } = useTranslation('board');
  const headingId = useId();
  const { active } = useDndContext();
  const { setNodeRef, isOver } = useDroppable({
    id: status,
    disabled: status !== IN_PROGRESS_COLUMN,
  });
  const isTarget =
    active !== null && resolveDrop(active.data.current?.task, status).type === 'pickup';

  return (
    <section
      ref={setNodeRef}
      className="board-column"
      aria-labelledby={headingId}
      data-status={status}
      data-drop-target={isTarget ? 'true' : undefined}
      data-drop-over={isTarget && isOver ? 'true' : undefined}
    >
      <h2 id={headingId} className="board-column__title">
        {t(`columns.${status}`)}
        {!isLoading && (
          <>
            {' '}
            <span className="board-column__count">{tasks.length}</span>
          </>
        )}
      </h2>
      <div className="board-column__body">
        {isLoading ? (
          Array.from({ length: SKELETON_CARDS }, (_, index) => (
            <Skeleton key={index} height={96} radius="md" data-testid="card-skeleton" />
          ))
        ) : tasks.length === 0 ? (
          <p className="board-column__empty">{t('empty.column')}</p>
        ) : (
          <ul className="board-column__list">
            {tasks.map((task) => (
              <li key={task.id}>
                {canDrag(task) ? (
                  <DraggableTaskCard
                    task={task}
                    onPickUp={onPickUp}
                    isPickingUp={pickingId === task.id}
                    pickUpDisabled={pickingId !== null && pickingId !== task.id}
                  />
                ) : (
                  <TaskCard task={task} />
                )}
              </li>
            ))}
          </ul>
        )}
      </div>
    </section>
  );
}
