import { VisuallyHidden } from '@mantine/core';
import { useId } from 'react';

/**
 * A titled block of the dashboard: a region named by its h2.
 * @param {object} props
 * @param {string} props.title
 * @param {string} props.name for styling and tests, for example "status"
 * @param {boolean} [props.danger] outline in the danger colour
 * @param {(headingId: string) => import('react').ReactNode} props.children gets the id of the
 *   heading, so a table in it can be named by it
 */
export function Section({ title, name, danger = false, children }) {
  const headingId = useId();
  return (
    <section
      className="dashboard-section"
      aria-labelledby={headingId}
      data-section={name}
      data-danger={danger ? 'true' : undefined}
    >
      <h2 id={headingId} className="dashboard-section__title">
        {title}
      </h2>
      {children(headingId)}
    </section>
  );
}

/**
 * The numbers of a chart as a table that only assistive technology and tests see: the chart
 * itself is a picture, this is what it says.
 * @param {object} props
 * @param {string} props.caption
 * @param {string[]} props.headers the first column holds the row names
 * @param {string[][]} props.rows
 */
export function ChartTable({ caption, headers, rows }) {
  return (
    <VisuallyHidden component="div">
      <table>
        <caption>{caption}</caption>
        <thead>
          <tr>
            {headers.map((header, index) => (
              <th key={index} scope="col">
                {header}
              </th>
            ))}
          </tr>
        </thead>
        <tbody>
          {rows.map((cells, rowIndex) => (
            <tr key={rowIndex}>
              {cells.map((cell, index) => (
                <td key={index}>{cell}</td>
              ))}
            </tr>
          ))}
        </tbody>
      </table>
    </VisuallyHidden>
  );
}

/**
 * The text that stands in for a chart or a table that has nothing to show.
 * @param {{text: string}} props
 */
export function NoData({ text }) {
  return <p className="dashboard-section__empty">{text}</p>;
}
