import './BrandMark.scss';

/**
 * The logo mark: a rounded square in the brand gradient. Decorative; the app's name is written
 * next to it.
 * @param {{size?: number}} props size in pixels
 */
export function BrandMark({ size = 24 }) {
  return (
    <span
      className="brand-mark"
      aria-hidden="true"
      style={{ width: `${size}px`, height: `${size}px` }}
    />
  );
}
