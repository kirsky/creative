import { useTranslation } from 'react-i18next';

/** The counters, in order. */
const COUNTERS = /** @type {const} */ (['in_progress', 'revisions_required', 'available']);

/**
 * The three summary counters. While the board loads they show a dash: a 0 would be a claim.
 * @param {{counts: Record<'in_progress' | 'revisions_required' | 'available', number> | undefined}} props
 */
export function Counters({ counts }) {
  const { t } = useTranslation('mywork');
  return (
    <ul className="my-work-counters">
      {COUNTERS.map((key) => (
        <li
          key={key}
          className="my-work-counters__item"
          data-counter={key}
          data-testid={`counter-${key}`}
          data-loading={counts === undefined ? 'true' : undefined}
          data-attention={
            key === 'revisions_required' && (counts?.[key] ?? 0) > 0 ? 'true' : undefined
          }
        >
          <span className="my-work-counters__value">
            {counts === undefined ? '—' : counts[key]}
          </span>
          <span className="my-work-counters__label">{t(`counters.${key}`)}</span>
        </li>
      ))}
    </ul>
  );
}
