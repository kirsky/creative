/**
 * The hover box of a chart: a title and rows of colour, label and value. The rows are text, so the
 * colour is never the only way to tell them apart.
 * @param {object} props
 * @param {string} props.title
 * @param {{key: string, label: string, value: string, color: string}[]} props.rows
 */
export function ChartTooltip({ title, rows }) {
  return (
    <div className="chart-tooltip">
      <p className="chart-tooltip__title">{title}</p>
      <ul className="chart-tooltip__rows">
        {rows.map((row) => (
          <li key={row.key} className="chart-tooltip__row">
            <span className="chart-swatch" style={{ backgroundColor: row.color }} />
            <span>{row.label}</span>
            <span className="chart-tooltip__value">{row.value}</span>
          </li>
        ))}
      </ul>
    </div>
  );
}
