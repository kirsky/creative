import { SimpleGrid } from '@mantine/core';
import { ClipboardText, Clock, Coins, Pulse, WarningCircle } from '@phosphor-icons/react';
import { useTranslation } from 'react-i18next';
import { Icon } from '../../components/Icon';
import { formatCredits, formatDuration, formatNumber } from '../../i18n/format';

/**
 * One figure: a label, the value, and a line under it.
 * @param {object} props
 * @param {string} props.name for styling and tests, for example "total"
 * @param {import('../../components/Icon').IconComponent} props.icon
 * @param {string} props.label
 * @param {string} props.value
 * @param {string} props.hint
 * @param {boolean} [props.danger]
 */
function Tile({ name, icon, label, value, hint, danger = false }) {
  return (
    <div
      className="stat-tile"
      data-testid={`tile-${name}`}
      data-danger={danger ? 'true' : undefined}
    >
      <dt className="stat-tile__label">
        <span className="stat-tile__icon">
          <Icon icon={icon} size={16} />
        </span>
        {label}
      </dt>
      <dd className="stat-tile__value">{value}</dd>
      <dd className="stat-tile__hint">{hint}</dd>
    </div>
  );
}

/**
 * The five figures at the top: total, active, overdue, average cycle time, AI credits.
 * @param {{data: import('./api').Dashboard}} props
 */
export function StatTiles({ data }) {
  const { t, i18n } = useTranslation('dashboard');
  const lng = /** @type {import('../../i18n/detect').Language} */ (i18n.language);
  const { totals, overdue, averages, ai } = data;

  return (
    <SimpleGrid
      component="dl"
      cols={{ base: 1, xs: 2, md: 3, lg: 5 }}
      spacing="xs"
      className="stat-tiles"
      aria-label={t('tiles.label')}
    >
      <Tile
        name="total"
        icon={ClipboardText}
        label={t('tiles.total.label')}
        value={formatNumber(totals.total, lng)}
        hint={t('tiles.total.cancelled', { count: totals.cancelled })}
      />
      <Tile
        name="active"
        icon={Pulse}
        label={t('tiles.active.label')}
        value={formatNumber(totals.active, lng)}
        hint={t('tiles.active.hint')}
      />
      <Tile
        name="overdue"
        icon={WarningCircle}
        label={t('tiles.overdue.label')}
        value={formatNumber(overdue.count, lng)}
        hint={overdue.count > 0 ? t('tiles.overdue.hint') : t('tiles.overdue.none')}
        danger={overdue.count > 0}
      />
      <Tile
        name="cycle"
        icon={Clock}
        label={t('tiles.cycle.label')}
        value={formatDuration(averages.cycleSeconds, t)}
        hint={t('tiles.cycle.completed', { count: averages.completedCount })}
      />
      <Tile
        name="ai"
        icon={Coins}
        label={t('tiles.ai.label')}
        value={formatCredits(ai.total, lng)}
        hint={t('tiles.ai.providers', { count: ai.byProvider.length })}
      />
    </SimpleGrid>
  );
}
