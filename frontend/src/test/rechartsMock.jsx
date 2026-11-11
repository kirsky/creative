import { cloneElement } from 'react';

/** @param {{children: import('react').ReactElement<any>}} props */
function ResponsiveContainer({ children }) {
  return (
    <div data-testid="chart" style={{ width: 600, height: 300 }}>
      {cloneElement(children, { width: 600, height: 300 })}
    </div>
  );
}

/**
 * The `recharts` module for jsdom, where ResponsiveContainer renders nothing because nothing has a
 * size: the real module with a ResponsiveContainer that gives its chart a fixed size. Use it as
 * `jest.mock('recharts', () => require('../../test/rechartsMock').rechartsMock())`.
 */
export function rechartsMock() {
  return { ...jest.requireActual('recharts'), ResponsiveContainer };
}
