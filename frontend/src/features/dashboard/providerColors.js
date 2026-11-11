/**
 * The colours of the AI providers in the charts: Mantine variables, so light and dark both work.
 * More providers than colours reuse the palette from the start.
 */
export const PROVIDER_PALETTE = [
  'var(--mantine-color-blue-6)',
  'var(--mantine-color-orange-6)',
  'var(--mantine-color-teal-6)',
  'var(--mantine-color-grape-6)',
  'var(--mantine-color-yellow-6)',
  'var(--mantine-color-cyan-6)',
  'var(--mantine-color-pink-6)',
  'var(--mantine-color-lime-6)',
];

/**
 * One colour per provider name. The names are de-duplicated and sorted by code point (not by
 * locale, so the colours do not change with the language), then take the palette in turn. Build
 * it once from the names of every dataset on the page: the same provider then has the same colour
 * in every chart.
 * @param {Iterable<string>} providers
 * @returns {Map<string, string>}
 */
export function buildProviderColors(providers) {
  const names = [...new Set(providers)].sort((a, b) => (a < b ? -1 : a > b ? 1 : 0));
  return new Map(
    names.map((name, index) => [name, PROVIDER_PALETTE[index % PROVIDER_PALETTE.length]]),
  );
}
