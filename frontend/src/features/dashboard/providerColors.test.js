import { PROVIDER_PALETTE, buildProviderColors } from './providerColors';

describe('buildProviderColors', () => {
  it('gives every provider one colour from the palette', () => {
    const colors = buildProviderColors(['Runway', 'Kling']);
    expect(colors.get('Kling')).toBe(PROVIDER_PALETTE[0]);
    expect(colors.get('Runway')).toBe(PROVIDER_PALETTE[1]);
  });

  it('does not depend on the order or the repeats of the names', () => {
    const a = buildProviderColors(['Runway', 'Kling', 'Midjourney']);
    const b = buildProviderColors(['Midjourney', 'Runway', 'Runway', 'Kling']);
    expect([...a]).toEqual([...b]);
  });

  it('keeps a provider colour when the names come from two datasets', () => {
    const byProvider = ['Runway', 'Midjourney'];
    const byDesigner = ['Kling', 'Runway'];
    const shared = buildProviderColors([...byProvider, ...byDesigner]);
    // The same name resolves to the same colour whichever chart asks.
    expect(shared.get('Runway')).toBe(
      buildProviderColors([...byDesigner, ...byProvider]).get('Runway'),
    );
    expect(new Set([...shared.values()]).size).toBe(3);
  });

  it('wraps around when there are more providers than colours', () => {
    const names = Array.from(
      { length: PROVIDER_PALETTE.length + 2 },
      (_, i) => `P${String(i).padStart(2, '0')}`,
    );
    const colors = buildProviderColors(names);
    expect(colors.get('P00')).toBe(PROVIDER_PALETTE[0]);
    expect(colors.get(`P${String(PROVIDER_PALETTE.length).padStart(2, '0')}`)).toBe(
      PROVIDER_PALETTE[0],
    );
    expect(colors.size).toBe(names.length);
  });

  it('uses Mantine colour variables, so light and dark both work', () => {
    for (const color of PROVIDER_PALETTE) {
      expect(color).toMatch(/^var\(--mantine-color-[a-z]+-\d\)$/);
    }
  });

  it('is empty without providers', () => {
    expect(buildProviderColors([]).size).toBe(0);
  });
});
