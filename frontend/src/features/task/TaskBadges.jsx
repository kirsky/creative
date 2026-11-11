import { Badge } from '@mantine/core';
import { useTranslation } from 'react-i18next';
import { Icon } from '../../components/Icon';
import { PRIORITY_COLORS } from '../board/TaskCard';
import { PRIORITY_ICON, STATUS_ICONS } from './taskIcons';

/**
 * The status of a task, worded and coloured as on the board's cancelled list.
 * @param {{status: string}} props
 */
export function StatusBadge({ status }) {
  const { t } = useTranslation('board');
  return (
    <Badge
      variant="outline"
      color="gray"
      data-status={status}
      leftSection={STATUS_ICONS[status] && <Icon icon={STATUS_ICONS[status]} size={13} />}
    >
      {t(`card.status.${status}`, { defaultValue: status })}
    </Badge>
  );
}

/**
 * The priority of a task, coloured as on the board's cards.
 * @param {{priority: string}} props
 */
export function PriorityBadge({ priority }) {
  const { t } = useTranslation('task');
  return (
    <Badge
      variant="light"
      color={PRIORITY_COLORS[priority] ?? 'gray'}
      leftSection={<Icon icon={PRIORITY_ICON} size={13} weight="fill" />}
    >
      {t(`priorities.${priority}`, { defaultValue: priority })}
    </Badge>
  );
}
