/**
 * The colours of the AI providers in the charts: Mantine variables, so light and dark both work.
 * Nine of them, so the six seeded providers (see SEEDED_PROVIDERS) all get a different one.
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
  'var(--mantine-color-indigo-6)',
];

/** The providers the system starts with. A test checks that their colours differ. */
export const SEEDED_PROVIDERS = [
  'Midjourney',
  'Runway',
  'Kling',
  'ElevenLabs',
  'OpenAI',
  'Adobe Firefly',
];

const FNV_OFFSET = 0x811c9dc5;
const FNV_PRIME = 0x01000193;

/**
 * 32-bit FNV-1a of the UTF-8 bytes of a text.
 * @param {string} text
 */
function fnv1a(text) {
  let hash = FNV_OFFSET;
  for (const byte of new TextEncoder().encode(text)) {
    hash ^= byte;
    hash = Math.imul(hash, FNV_PRIME) >>> 0;
  }
  return hash;
}

/**
 * The colour of a provider, from its name alone: the hash of the trimmed, lower-cased name picks a
 * palette slot. It therefore does not change with the period, the dataset or the other providers
 * on the page, which an index into "the providers present now" would. Two providers can share a
 * colour (the palette is smaller than the set of possible names); the seeded ones do not.
 * @param {string} provider
 */
export function providerColor(provider) {
  return PROVIDER_PALETTE[fnv1a(provider.trim().toLowerCase()) % PROVIDER_PALETTE.length];
}

/**
 * The colour of each provider name given, for lookups by the exact name the API sent. Build it
 * once from the names of every dataset on the page: both charts then share one mapping.
 * @param {Iterable<string>} providers
 * @returns {Map<string, string>}
 */
export function buildProviderColors(providers) {
  return new Map([...new Set(providers)].map((name) => [name, providerColor(name)]));
}
