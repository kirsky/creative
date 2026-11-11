import {
  Bar,
  BarChart,
  CartesianGrid,
  LabelList,
  Rectangle,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from 'recharts';
import { useTranslation } from 'react-i18next';
import { formatCredits, formatNumber } from '../../i18n/format';
import { ChartTooltip } from './ChartTooltip';
import { buildProviderColors } from './providerColors';
import { ChartTable, NoData, Section } from './Section';

/** @typedef {import('./api').Dashboard['ai']} Ai */
/** @typedef {import('../../i18n/detect').Language} Language */

const ROW_HEIGHT_PX = 34;
const AXIS_HEIGHT_PX = 32;
const NAME_WIDTH_PX = 112;
/** Room right of the bars for the value label. */
const LABEL_MARGIN_PX = 76;

const TICK = { fill: 'var(--app-text-muted)', fontSize: 12 };

/** A credit amount that is more than zero ("0.00" is nothing). Read as text, no arithmetic. */
const isPositive = (/** @type {string} */ credits) => /[1-9]/.test(credits);

/**
 * The number a chart needs to draw a bar. This is the only place where credit strings become
 * numbers; every figure shown to people stays the API's string.
 * @param {string} credits
 */
const toPlot = (credits) => Number(credits);

/** @param {{credits: string}} a @param {{credits: string}} b */
const byCreditsDesc = (a, b) => toPlot(b.credits) - toPlot(a.credits);

/**
 * The colour of each provider, the same in both charts: built from the providers of both datasets.
 * @param {Ai} ai
 */
function providerColors(ai) {
  return buildProviderColors([
    ...ai.byProvider.map(({ provider }) => provider),
    ...ai.byDesigner.flatMap(({ byProvider }) => byProvider.map(({ provider }) => provider)),
  ]);
}

/**
 * @param {object} props
 * @param {Language} props.lng
 * @param {number} props.rowCount
 * @param {import('react').ReactNode} props.children the Bars, tooltip and the like
 * @param {Record<string, string | number>[]} props.data
 */
function HorizontalBars({ lng, rowCount, data, children }) {
  return (
    <div className="ai-chart" aria-hidden="true">
      <ResponsiveContainer width="100%" height={rowCount * ROW_HEIGHT_PX + AXIS_HEIGHT_PX}>
        <BarChart
          data={data}
          layout="vertical"
          margin={{ top: 4, right: LABEL_MARGIN_PX, bottom: 0, left: 0 }}
          accessibilityLayer={false}
        >
          <CartesianGrid horizontal={false} stroke="var(--app-border)" />
          <XAxis
            type="number"
            tick={TICK}
            allowDecimals={false}
            tickFormatter={(value) => formatNumber(value, lng)}
          />
          <YAxis
            type="category"
            dataKey="name"
            width={NAME_WIDTH_PX}
            tick={TICK}
            tickLine={false}
            axisLine={false}
          />
          {children}
        </BarChart>
      </ResponsiveContainer>
    </div>
  );
}

/**
 * A legend entry per provider: its colour, its name and optionally text beside it.
 * @param {object} props
 * @param {{provider: string, text?: string}[]} props.items
 * @param {Map<string, string>} props.colors
 */
function ProviderLegend({ items, colors }) {
  return (
    <ul className="chart-legend" aria-hidden="true">
      {items.map(({ provider, text }) => (
        <li key={provider} className="chart-legend__item" data-provider={provider}>
          <span
            className="chart-swatch"
            data-color={colors.get(provider)}
            style={{ backgroundColor: colors.get(provider) }}
          />
          <span>{provider}</span>
          {text !== undefined && (
            <>
              {' '}
              <strong>{text}</strong>
            </>
          )}
        </li>
      ))}
    </ul>
  );
}

/**
 * The value written right of a bar's end (the content of a LabelList whose dataKey is "row").
 * The row comes from the data, not from the label's index: the chart leaves zero-width segments
 * out, so the index of a label is not always the row of its bar.
 * @param {{labels: (string | null)[]} & Record<string, any>} props labels is the text per row,
 *   null for none; the rest is what the chart tells a label (x, y, width, height, value = row)
 */
function EndLabel({ labels, x, y, width, height, value }) {
  const text = labels[Number(value)];
  if (text === undefined || text === null) {
    return null;
  }
  return (
    <text
      x={Number(x) + Number(width) + 6}
      y={Number(y) + Number(height) / 2}
      dominantBaseline="central"
      fill="var(--app-text)"
      fontSize={12}
    >
      {text}
    </text>
  );
}

/**
 * Credits per provider: horizontal bars, biggest first, each in the provider's colour.
 * @param {{ai: Ai, colors: Map<string, string>}} props
 */
function ByProvider({ ai, colors }) {
  const { t, i18n } = useTranslation('dashboard');
  const lng = /** @type {Language} */ (i18n.language);
  const rows = [...ai.byProvider].sort(byCreditsDesc);
  const hasData = rows.some(({ credits }) => isPositive(credits));
  const labels = rows.map(({ credits }) => formatCredits(credits, lng));
  const data = rows.map(({ provider, credits }, row) => ({
    name: provider,
    row,
    credits: toPlot(credits),
  }));

  return (
    <Section title={t('ai.byProvider.title')} name="ai-provider">
      {() =>
        !hasData ? (
          <NoData text={t('noData')} />
        ) : (
          <>
            <HorizontalBars lng={lng} rowCount={rows.length} data={data}>
              <Tooltip
                cursor={{ fill: 'var(--mantine-color-default-hover)' }}
                content={({ active, payload }) => {
                  const provider = String(payload?.[0]?.payload?.name ?? '');
                  const row = rows.find((candidate) => candidate.provider === provider);
                  return active && row !== undefined ? (
                    <ChartTooltip
                      title={t('ai.credits')}
                      rows={[
                        {
                          key: provider,
                          label: provider,
                          value: formatCredits(row.credits, lng),
                          color: colors.get(provider) ?? '',
                        },
                      ]}
                    />
                  ) : null;
                }}
              />
              <Bar
                dataKey="credits"
                isAnimationActive={false}
                shape={(/** @type {any} */ props) => (
                  <Rectangle {...props} fill={colors.get(props.payload.name)} />
                )}
              >
                <LabelList
                  dataKey="row"
                  content={(props) => <EndLabel {...props} labels={labels} />}
                />
              </Bar>
            </HorizontalBars>
            <ProviderLegend
              colors={colors}
              items={rows.map(({ provider, credits }) => ({
                provider,
                text: formatCredits(credits, lng),
              }))}
            />
            <ChartTable
              caption={t('ai.byProvider.caption')}
              headers={[t('ai.provider'), t('ai.credits')]}
              rows={rows.map(({ provider, credits }) => [provider, formatCredits(credits, lng)])}
            />
          </>
        )
      }
    </Section>
  );
}

/**
 * Credits per designer: one stacked bar each, split by provider.
 * @param {{ai: Ai, colors: Map<string, string>}} props
 */
function ByDesigner({ ai, colors }) {
  const { t, i18n } = useTranslation('dashboard');
  const lng = /** @type {Language} */ (i18n.language);
  const designers = [...ai.byDesigner]
    .map((designer) => ({ ...designer, plotTotal: toPlot(designer.total) }))
    .sort((a, b) => b.plotTotal - a.plotTotal);
  const hasData = designers.some(({ total }) => isPositive(total));
  // The providers these designers used, sorted by name (a stack order that does not move with the data).
  const used = new Set(
    designers.flatMap(({ byProvider }) => byProvider.map(({ provider }) => provider)),
  );
  const providers = [...used].sort((a, b) => (a < b ? -1 : a > b ? 1 : 0));
  // Series keys are positions, not names: a provider name may contain a dot or clash with "name".
  const keyOf = (/** @type {string} */ provider) => `p${providers.indexOf(provider)}`;
  const creditsOf = (
    /** @type {typeof designers[number]} */ designer,
    /** @type {string} */ provider,
  ) => designer.byProvider.find((entry) => entry.provider === provider)?.credits ?? '0.00';

  const data = designers.map((designer, row) => ({
    name: designer.name,
    row,
    ...Object.fromEntries(providers.map((p) => [keyOf(p), toPlot(creditsOf(designer, p))])),
  }));
  // The total is written after the outermost segment a designer has (the stack's top provider
  // may be one they never used, and a zero-width segment is not drawn).
  const topKeys = designers.map((designer) => {
    const usedByDesigner = providers.filter((p) => isPositive(creditsOf(designer, p)));
    return usedByDesigner.length === 0 ? null : keyOf(usedByDesigner[usedByDesigner.length - 1]);
  });
  /** @param {string} provider the totals that go at the end of this provider's segments */
  const totalsAfter = (provider) =>
    designers.map(({ total }, row) =>
      topKeys[row] === keyOf(provider) ? formatCredits(total, lng) : null,
    );

  return (
    <Section title={t('ai.byDesigner.title')} name="ai-designer">
      {() =>
        !hasData ? (
          <NoData text={t('noData')} />
        ) : (
          <>
            <HorizontalBars lng={lng} rowCount={designers.length} data={data}>
              <Tooltip
                shared={false}
                cursor={{ fill: 'var(--mantine-color-default-hover)' }}
                content={({ active, payload }) => {
                  const name = String(payload?.[0]?.payload?.name ?? '');
                  const designer = designers.find((candidate) => candidate.name === name);
                  return active && designer !== undefined ? (
                    <ChartTooltip
                      title={designer.name}
                      rows={providers
                        .filter((provider) => isPositive(creditsOf(designer, provider)))
                        .map((provider) => ({
                          key: provider,
                          label: provider,
                          value: formatCredits(creditsOf(designer, provider), lng),
                          color: colors.get(provider) ?? '',
                        }))}
                    />
                  ) : null;
                }}
              />
              {providers.map((provider) => (
                <Bar
                  key={provider}
                  dataKey={keyOf(provider)}
                  stackId="credits"
                  fill={colors.get(provider)}
                  isAnimationActive={false}
                >
                  {topKeys.includes(keyOf(provider)) && (
                    <LabelList
                      dataKey="row"
                      content={(props) => <EndLabel {...props} labels={totalsAfter(provider)} />}
                    />
                  )}
                </Bar>
              ))}
            </HorizontalBars>
            <ProviderLegend colors={colors} items={providers.map((provider) => ({ provider }))} />
            <p className="dashboard-section__note">{t('ai.byDesigner.hint')}</p>
            <ChartTable
              caption={t('ai.byDesigner.caption')}
              headers={[t('ai.designer'), ...providers, t('ai.total')]}
              rows={designers.map((designer) => [
                designer.name,
                ...providers.map((provider) => formatCredits(creditsOf(designer, provider), lng)),
                formatCredits(designer.total, lng),
              ])}
            />
          </>
        )
      }
    </Section>
  );
}

/**
 * The AI spend: per provider and per designer side by side, one colour per provider across both.
 * @param {{ai: Ai}} props
 */
export function AiCharts({ ai }) {
  const colors = providerColors(ai);
  return (
    <div className="dashboard__row">
      <ByProvider ai={ai} colors={colors} />
      <ByDesigner ai={ai} colors={colors} />
    </div>
  );
}
