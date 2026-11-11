import { Button, Checkbox, Select, TextInput } from '@mantine/core';
import { Flag, MagnifyingGlass, Shapes, UserCircle, X } from '@phosphor-icons/react';
import { useTranslation } from 'react-i18next';
import { Icon } from '../../components/Icon';
import { hasFilters } from './filters';

/**
 * The filter bar. The controls are controlled by the page, which keeps the filters in the URL.
 * @param {object} props
 * @param {import('./filters').BoardFilters} props.filters
 * @param {string} props.search the text of the search box (applied after a pause)
 * @param {(value: string) => void} props.onSearchChange
 * @param {(change: import('./filters').FilterChange) => void} props.onFilterChange
 * @param {() => void} props.onClearAll
 * @param {import('../tasks/api').Options | undefined} props.options priority and type codes
 * @param {{id: number, name: string}[]} props.designers
 * @param {boolean} props.showCancelled
 * @param {number} props.cancelledCount
 * @param {(show: boolean) => void} props.onShowCancelledChange
 */
export function BoardFilters({
  filters,
  search,
  onSearchChange,
  onFilterChange,
  onClearAll,
  options,
  designers,
  showCancelled,
  cancelledCount,
  onShowCancelledChange,
}) {
  const { t } = useTranslation(['board', 'task']);

  const priorities = (options?.priorities ?? []).map((code) => ({
    value: code,
    label: t(`task:priorities.${code}`, { defaultValue: code }),
  }));
  const creativeTypes = (options?.creativeTypes ?? []).map((code) => ({
    value: code,
    label: t(`task:creativeTypes.${code}`, { defaultValue: code }),
  }));
  const designerOptions = designers.map(({ id, name }) => ({ value: String(id), label: name }));
  // A designer from the URL who has no task on the board yet is still shown as selected.
  if (filters.designerId !== undefined && !designers.some(({ id }) => id === filters.designerId)) {
    designerOptions.push({ value: String(filters.designerId), label: `#${filters.designerId}` });
  }

  const priorityLabel = t('board:filters.priority');
  const typeLabel = t('board:filters.creativeType');
  const designerLabel = t('board:filters.designer');
  const isFiltered = hasFilters(filters);

  return (
    <div className="board-filters" role="group" aria-label={t('board:filters.label')}>
      <TextInput
        className="board-filters__search"
        aria-label={t('board:filters.search')}
        placeholder={t('board:filters.search')}
        leftSection={<Icon icon={MagnifyingGlass} size={16} />}
        value={search}
        onChange={(event) => onSearchChange(event.currentTarget.value)}
        maxLength={100}
      />
      <Select
        className="board-filters__select"
        aria-label={priorityLabel}
        placeholder={priorityLabel}
        leftSection={<Icon icon={Flag} size={16} />}
        data={priorities}
        value={filters.priority ?? null}
        onChange={(value) => onFilterChange({ priority: value ?? undefined })}
        clearable
      />
      <Select
        className="board-filters__select"
        aria-label={typeLabel}
        placeholder={typeLabel}
        leftSection={<Icon icon={Shapes} size={16} />}
        data={creativeTypes}
        value={filters.creativeType ?? null}
        onChange={(value) => onFilterChange({ creativeType: value ?? undefined })}
        clearable
      />
      <Select
        className="board-filters__select"
        aria-label={designerLabel}
        placeholder={designerLabel}
        leftSection={<Icon icon={UserCircle} size={16} />}
        data={designerOptions}
        value={filters.designerId === undefined ? null : String(filters.designerId)}
        onChange={(value) =>
          onFilterChange({ designerId: value === null ? undefined : Number(value) })
        }
        clearable
      />
      {isFiltered && (
        <Button
          variant="subtle"
          size="compact-sm"
          leftSection={<Icon icon={X} size={14} />}
          onClick={onClearAll}
        >
          {t('board:filters.clearAll')}
        </Button>
      )}
      <Checkbox
        label={t('board:filters.showCancelled', { count: cancelledCount })}
        checked={showCancelled}
        onChange={(event) => onShowCancelledChange(event.currentTarget.checked)}
      />
    </div>
  );
}
