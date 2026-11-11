import {
  PROVIDER_PALETTE,
  SEEDED_PROVIDERS,
  buildProviderColors,
  providerColor,
} from './providerColors';

describe('PROVIDER_PALETTE', () => {
  it('has no colour twice', () => {
    expect(new Set(PROVIDER_PALETTE).size).toBe(PROVIDER_PALETTE.length);
  });

  it('takes its first colour from the brand palette', () => {
    expect(PROVIDER_PALETTE).toContain('var(--mantine-color-aurora-6)');
  });
});

describe('providerColor', () => {
  it('gives a provider a colour from the palette', () => {
    expect(PROVIDER_PALETTE).toContain(providerColor('Runway'));
  });

  it('is the same wherever the provider is asked for, whatever else is on the page', () => {
    // A period without Midjourney must not move Runway to another colour.
    const all = buildProviderColors(['Runway', 'Midjourney', 'Kling']);
    const week = buildProviderColors(['Runway', 'Kling']);
    const other = buildProviderColors(['Adobe Firefly', 'Runway']);
    expect(week.get('Runway')).toBe(all.get('Runway'));
    expect(week.get('Kling')).toBe(all.get('Kling'));
    expect(other.get('Runway')).toBe(all.get('Runway'));
    expect(providerColor('Runway')).toBe(all.get('Runway'));
  });

  it('does not depend on the order or the repeats of the names', () => {
    const a = buildProviderColors(['Runway', 'Kling', 'Midjourney']);
    const b = buildProviderColors(['Midjourney', 'Runway', 'Runway', 'Kling']);
    expect([...a.entries()].sort()).toEqual([...b.entries()].sort());
  });

  it('ignores case and surrounding spaces in the name', () => {
    expect(providerColor('  runway ')).toBe(providerColor('Runway'));
    expect(providerColor('MIDJOURNEY')).toBe(providerColor('Midjourney'));
  });

  it('gives the six seeded providers six different colours', () => {
    expect(SEEDED_PROVIDERS).toEqual([
      'Midjourney',
      'Runway',
      'Kling',
      'ElevenLabs',
      'OpenAI',
      'Adobe Firefly',
    ]);
    const colors = SEEDED_PROVIDERS.map((name) => providerColor(name));
    expect(new Set(colors).size).toBe(6);
  });

  it('uses Mantine colour variables, so light and dark both work', () => {
    for (const color of PROVIDER_PALETTE) {
      expect(color).toMatch(/^var\(--mantine-color-[a-z]+-\d\)$/);
    }
    expect(new Set(PROVIDER_PALETTE).size).toBe(PROVIDER_PALETTE.length);
  });
});

describe('buildProviderColors', () => {
  it('maps every distinct name once', () => {
    const colors = buildProviderColors(['Runway', 'Kling', 'Runway']);
    expect([...colors.keys()].sort()).toEqual(['Kling', 'Runway']);
  });

  it('is empty without providers', () => {
    expect(buildProviderColors([]).size).toBe(0);
  });
});
