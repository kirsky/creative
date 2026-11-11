import { render, screen, within } from '@testing-library/react';
import { Counters } from './Counters';

describe('Counters', () => {
  it('shows each count with its label and a decorative icon', () => {
    render(<Counters counts={{ in_progress: 2, revisions_required: 1, available: 7 }} />);
    for (const [key, value, label] of [
      ['in_progress', '2', 'In progress'],
      ['revisions_required', '1', 'Revisions required'],
      ['available', '7', 'Available'],
    ]) {
      const item = screen.getByTestId(`counter-${key}`);
      expect(within(item).getByText(label)).toBeInTheDocument();
      expect(item).toHaveTextContent(value);
      expect(item.querySelector('svg[aria-hidden="true"]')).not.toBeNull();
    }
  });

  it('shows a dash, not a zero, while the counts load', () => {
    render(<Counters counts={undefined} />);
    expect(screen.getByTestId('counter-available')).toHaveTextContent('—');
  });
});
