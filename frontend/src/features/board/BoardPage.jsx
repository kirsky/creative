import {
  DndContext,
  DragOverlay,
  KeyboardSensor,
  PointerSensor,
  useSensor,
  useSensors,
} from '@dnd-kit/core';
import { useDebouncedCallback } from '@mantine/hooks';
import { Alert, Button, Title } from '@mantine/core';
import { useCallback, useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link, useSearchParams } from 'react-router';
import { ApiRequestError } from '../../api/ApiRequestError';
import { translateApiError } from '../../i18n/errors';
import { useAuth } from '../auth/useAuth';
import { useOptions } from '../tasks/api';
import { useBoard } from './api';
import './Board.scss';
import { BoardColumn } from './BoardColumn';
import { BoardFilters } from './BoardFilters';
import { CancelledList } from './CancelledList';
import { BOARD_COLUMNS, resolveDrop } from './dragDrop';
import { filtersToParams, hasFilters, parseFilters } from './filters';
import { boardCollisions, columnKeyboardCoordinates } from './keyboard';
import { TaskCard } from './TaskCard';
import { usePickUpAction } from './usePickUpAction';

/** How long the search box waits for the next keystroke before it filters. */
const SEARCH_DELAY_MS = 300;

/** A drag must move this far (px) before it starts, so a plain click still opens the task. */
const DRAG_DISTANCE_PX = 6;

/**
 * Every designer seen on the board so far. The filter options come from the loaded tasks, and a
 * board narrowed to one designer would otherwise offer that designer only.
 * @param {import('./api').BoardTask[] | undefined} tasks
 * @returns {{id: number, name: string}[]} by name
 */
function useKnownDesigners(tasks) {
  const { i18n } = useTranslation();
  const [state, setState] = useState({
    tasks: /** @type {import('./api').BoardTask[] | undefined} */ (undefined),
    known: /** @type {Map<number, string>} */ (new Map()),
  });
  let { known } = state;
  if (tasks !== undefined && tasks !== state.tasks) {
    known = new Map(state.known);
    for (const { assignee } of tasks) {
      if (assignee !== null) {
        known.set(assignee.id, assignee.displayName);
      }
    }
    setState({ tasks, known });
  }
  return useMemo(
    () =>
      [...known]
        .map(([id, name]) => ({ id, name }))
        .sort((a, b) => a.name.localeCompare(b.name, i18n.language)),
    [known, i18n.language],
  );
}

/**
 * The tasks of each of the five columns and the cancelled ones, in the order the API sent.
 * @param {import('./api').BoardTask[] | undefined} tasks
 */
function splitByStatus(tasks) {
  const columns = /** @type {Record<string, import('./api').BoardTask[]>} */ (
    Object.fromEntries(BOARD_COLUMNS.map((status) => [status, []]))
  );
  /** @type {import('./api').BoardTask[]} */
  const cancelled = [];
  for (const task of tasks ?? []) {
    if (task.status === 'cancelled') {
      cancelled.push(task);
    } else {
      columns[task.status]?.push(task);
    }
  }
  return { columns, cancelled };
}

/** The Board screen: filter bar, five columns, and the cancelled tasks on request. */
export function BoardPage() {
  const { t } = useTranslation(['board', 'errors', 'common']);
  const { user } = useAuth();
  const options = useOptions();
  const [searchParams, setSearchParams] = useSearchParams();

  // The URL is the source of truth for the filters: reading it first makes /board?designerId=7
  // (the manager dashboard links there) work. Priority and type wait for the options to be valid.
  const filters = useMemo(
    () => parseFilters(searchParams, options.data),
    [searchParams, options.data],
  );
  const board = useBoard(filters, { enabled: options.isSuccess });
  const { pickUp, pendingId } = usePickUpAction();

  const [search, setSearch] = useState(() => parseFilters(searchParams, undefined).q ?? '');
  const [showCancelled, setShowCancelled] = useState(false);
  const [activeTaskId, setActiveTaskId] = useState(/** @type {number | null} */ (null));

  const changeFilters = useCallback(
    (/** @type {import('./filters').FilterChange} */ change) =>
      setSearchParams((current) => filtersToParams(current, change), { replace: true }),
    [setSearchParams],
  );
  const applySearch = useDebouncedCallback(
    (/** @type {string} */ value) => changeFilters({ q: value }),
    SEARCH_DELAY_MS,
  );
  /** @param {string} value */
  function handleSearchChange(value) {
    setSearch(value);
    applySearch(value);
  }
  function clearFilters() {
    applySearch.cancel();
    setSearch('');
    changeFilters({ priority: undefined, creativeType: undefined, designerId: undefined, q: '' });
  }

  const tasks = board.data?.tasks;
  const { columns, cancelled } = useMemo(() => splitByStatus(tasks), [tasks]);
  const designers = useKnownDesigners(tasks);
  const tasksById = useMemo(() => new Map((tasks ?? []).map((task) => [task.id, task])), [tasks]);
  const activeTask = activeTaskId === null ? undefined : tasksById.get(activeTaskId);

  const sensors = useSensors(
    useSensor(PointerSensor, { activationConstraint: { distance: DRAG_DISTANCE_PX } }),
    useSensor(KeyboardSensor, { coordinateGetter: columnKeyboardCoordinates }),
  );

  /** @param {import('@dnd-kit/core').DragStartEvent} event */
  function handleDragStart({ active }) {
    setActiveTaskId(Number(active.id));
  }
  /** @param {import('@dnd-kit/core').DragEndEvent} event */
  function handleDragEnd({ active, over }) {
    setActiveTaskId(null);
    const task = tasksById.get(Number(active.id));
    const action = resolveDrop(task, over?.id);
    if (action.type === 'pickup' && task !== undefined) {
      void pickUp(task);
    }
  }
  function handleDragCancel() {
    setActiveTaskId(null);
  }

  /** @param {import('@dnd-kit/core').Active} active */
  const offerOf = (active) => tasksById.get(Number(active.id))?.offer ?? '';
  /** @type {import('@dnd-kit/core').Announcements} */
  const announcements = {
    onDragStart: ({ active }) => t('board:dnd.lifted', { offer: offerOf(active) }),
    onDragOver: ({ active, over }) =>
      over === null
        ? t('board:dnd.overNothing', { offer: offerOf(active) })
        : t('board:dnd.overTarget', { offer: offerOf(active) }),
    onDragEnd: ({ active, over }) =>
      resolveDrop(tasksById.get(Number(active.id)), over?.id).type === 'pickup'
        ? t('board:dnd.dropped', { offer: offerOf(active) })
        : t('board:dnd.droppedNowhere', { offer: offerOf(active) }),
    onDragCancel: ({ active }) => t('board:dnd.cancelled', { offer: offerOf(active) }),
  };

  const failure = options.isError ? options.error : board.isError ? board.error : null;
  // A 401 is being handled (the user is on the way to the login page): no error screen for it.
  const showError =
    failure !== null && !(failure instanceof ApiRequestError && failure.status === 401);
  const isLoading = !showError && (options.isPending || board.isPending);
  const noMatches = hasFilters(filters) && tasks !== undefined && tasks.length === 0;

  function retry() {
    void (options.isError ? options.refetch() : board.refetch());
  }

  return (
    <div className="board">
      <div className="board__header">
        <Title order={1}>{t('board:title')}</Title>
        {user?.role === 'buyer' && (
          <Button component={Link} to="/tasks/new">
            {t('board:newTask')}
          </Button>
        )}
      </div>

      {!options.isError && (
        <BoardFilters
          filters={filters}
          search={search}
          onSearchChange={handleSearchChange}
          onFilterChange={changeFilters}
          onClearAll={clearFilters}
          options={options.data}
          designers={designers}
          showCancelled={showCancelled}
          cancelledCount={cancelled.length}
          onShowCancelledChange={setShowCancelled}
        />
      )}

      {showError ? (
        <Alert color="red" title={t('board:error.title')}>
          <p className="board__error-text">
            {failure instanceof ApiRequestError
              ? translateApiError(t, failure)
              : t('errors:unknown')}
          </p>
          <Button variant="light" color="red" size="xs" onClick={retry}>
            {t('common:retry')}
          </Button>
        </Alert>
      ) : (
        <>
          {noMatches && <p className="board__no-matches">{t('board:filters.noMatches')}</p>}
          <DndContext
            sensors={sensors}
            collisionDetection={boardCollisions}
            onDragStart={handleDragStart}
            onDragEnd={handleDragEnd}
            onDragCancel={handleDragCancel}
            accessibility={{
              announcements,
              screenReaderInstructions: { draggable: t('board:dnd.instructions') },
            }}
          >
            <div
              className="board__columns"
              data-refreshing={board.isPlaceholderData ? 'true' : undefined}
            >
              {BOARD_COLUMNS.map((status) => (
                <BoardColumn
                  key={status}
                  status={status}
                  tasks={columns[status] ?? []}
                  isLoading={isLoading}
                  onPickUp={pickUp}
                  pickingId={pendingId}
                />
              ))}
            </div>
            <DragOverlay>
              {activeTask === undefined ? null : <TaskCard task={activeTask} isOverlay />}
            </DragOverlay>
          </DndContext>
          {showCancelled && tasks !== undefined && <CancelledList tasks={cancelled} />}
        </>
      )}
    </div>
  );
}
