import { render, screen } from '@testing-library/react';
import { SquaresFour } from '@phosphor-icons/react';
import { BrandMark } from './BrandMark';
import { Icon } from './Icon';

describe('Icon', () => {
  it('is decorative by default: hidden from assistive technology, 16 px', () => {
    const { container } = render(<Icon icon={SquaresFour} />);
    const svg = /** @type {SVGElement} */ (container.querySelector('svg'));
    expect(svg).toHaveAttribute('aria-hidden', 'true');
    expect(svg).toHaveAttribute('width', '16');
    expect(screen.queryByRole('img')).not.toBeInTheDocument();
  });

  it('with a label it is an image with that accessible name', () => {
    render(<Icon icon={SquaresFour} label="Board" />);
    const image = screen.getByRole('img', { name: 'Board' });
    expect(image).not.toHaveAttribute('aria-hidden');
  });

  it('passes the weight through', () => {
    const regular = render(<Icon icon={SquaresFour} />).container.innerHTML;
    const fill = render(<Icon icon={SquaresFour} weight="fill" />).container.innerHTML;
    expect(fill).not.toBe(regular);
  });

  it('passes the size through', () => {
    const { container } = render(<Icon icon={SquaresFour} size={24} />);
    expect(container.querySelector('svg')).toHaveAttribute('width', '24');
  });
});

describe('BrandMark', () => {
  it('is decorative', () => {
    const { container } = render(<BrandMark />);
    expect(container.firstChild).toHaveAttribute('aria-hidden', 'true');
  });
});
