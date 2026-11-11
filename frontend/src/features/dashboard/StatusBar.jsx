import { Bar, BarChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import { useTranslation } from 'react-i18next';
import { formatNumber } from '../../i18n/format';
import { ChartTooltip } from './ChartTooltip';
import { ChartTable, NoData, Section } from './Section';

/** The statuses of the bar, in board order; cancelled tasks are a note beside it, not a segment. */
const BAR_STATUSES = ['new', 'in_progress', 'under_review', 'revisions_required', 'completed'];

/** Segment colours: Mantine variables, so light and dark both work. */
const STATUS_COLORS = /** @type {Record<string, string>} */ ({
  new: 'var(--mantine-color-gray-5)',
  in_progress: 'var(--mantine-color-blue-5)',
  under_review: 'var(--mantine-color-violet-5)',
  revisions_required: 'var(--mantine-color-orange-5)',
  completed: 'var(--mantine-color-teal-5)',
  cancelled: 'var(--mantine-color-gray-7)',
});

const BAR_HEIGHT_PX = 44;

/**
 * The tasks by status as one stacked horizontal bar, a legend with the counts, and the cancelled
 * ones as a note. Without tasks it says there is no data instead of drawing an empty bar.
 * @param {{totals: import('./api').Dashboard['totals']}} props
 */
export function StatusBar({ totals }) {
  const { t, i18n } = useTranslation(['dashboard', 'board']);
  const lng = /** @type {import('../../i18n/detect').Language} */ (i18n.language);
  const label = (/** @type {string} */ status) => t(`board:card.status.${status}`);
  const rows = BAR_STATUSES.map((status) => ({
    status,
    label: label(status),
    count: totals.byStatus[/** @type {keyof typeof totals.byStatus} */ (status)] ?? 0,
  }));
  const hasTasks = rows.some(({ count }) => count > 0);
  const data = [
    { name: 'tasks', ...Object.fromEntries(rows.map((row) => [row.status, row.count])) },
  ];

  return (
    <Section title={t('dashboard:status.title')} name="status">
      {() => (
        <>
          {!hasTasks ? (
            <NoData text={t('dashboard:noData')} />
          ) : (
            <>
              <div className="status-bar" aria-hidden="true">
                <ResponsiveContainer width="100%" height={BAR_HEIGHT_PX}>
                  <BarChart
                    data={data}
                    layout="vertical"
                    margin={{ top: 0, right: 0, bottom: 0, left: 0 }}
                    accessibilityLayer={false}
                  >
                    <XAxis type="number" hide domain={[0, 'dataMax']} />
                    <YAxis type="category" dataKey="name" hide />
                    <Tooltip
                      shared={false}
                      cursor={false}
                      content={({ active, payload }) => {
                        const status = String(payload?.[0]?.dataKey ?? '');
                        return active && status !== '' ? (
                          <ChartTooltip
                            title={t('dashboard:status.title')}
                            rows={[
                              {
                                key: status,
                                label: label(status),
                                value: formatNumber(Number(payload?.[0]?.value ?? 0), lng),
                                color: STATUS_COLORS[status],
                              },
                            ]}
                          />
                        ) : null;
                      }}
                    />
                    {rows.map((row) => (
                      <Bar
                        key={row.status}
                        dataKey={row.status}
                        stackId="status"
                        fill={STATUS_COLORS[row.status]}
                        isAnimationActive={false}
                      />
                    ))}
                  </BarChart>
                </ResponsiveContainer>
              </div>
              <ul className="chart-legend" data-testid="status-legend" aria-hidden="true">
                {rows.map((row) => (
                  <li key={row.status} className="chart-legend__item" data-status={row.status}>
                    <span
                      className="chart-swatch"
                      data-color={STATUS_COLORS[row.status]}
                      style={{ backgroundColor: STATUS_COLORS[row.status] }}
                    />
                    <span>{row.label}</span> <strong>{formatNumber(row.count, lng)}</strong>
                  </li>
                ))}
              </ul>
              <ChartTable
                caption={t('dashboard:status.caption')}
                headers={[t('dashboard:status.status'), t('dashboard:status.tasks')]}
                rows={[...rows, { label: label('cancelled'), count: totals.cancelled }].map(
                  (row) => [row.label, formatNumber(row.count, lng)],
                )}
              />
            </>
          )}
          {totals.cancelled > 0 && (
            <p className="dashboard-section__note">
              {t('dashboard:status.cancelledNote', { count: totals.cancelled })}
            </p>
          )}
        </>
      )}
    </Section>
  );
}
