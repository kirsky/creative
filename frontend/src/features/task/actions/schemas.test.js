import {
  aiUsageSchema,
  optionalTextSchema,
  requiredTextSchema,
  textProblem,
  toApiLines,
} from './schemas';

describe('aiUsageSchema', () => {
  it('accepts no lines', () => {
    expect(aiUsageSchema.safeParse([]).success).toBe(true);
  });

  it('rejects a control character in a provider', () => {
    const result = aiUsageSchema.safeParse([{ provider: 'Run\u0007way', credits: '1' }]);
    expect(result.success).toBe(false);
  });

  it('accepts the biggest amount and refuses the next one', () => {
    expect(aiUsageSchema.safeParse([{ provider: 'A', credits: '9999999.99' }]).success).toBe(true);
    expect(aiUsageSchema.safeParse([{ provider: 'A', credits: '10000000' }]).success).toBe(false);
  });

  it('accepts 20 lines and refuses 21', () => {
    const line = { provider: 'A', credits: '1' };
    expect(aiUsageSchema.safeParse(Array.from({ length: 20 }, () => line)).success).toBe(true);
    expect(aiUsageSchema.safeParse(Array.from({ length: 21 }, () => line)).success).toBe(false);
  });
});

describe('text schemas', () => {
  it('count characters as a person does and judge the trimmed text', () => {
    expect(optionalTextSchema.safeParse('🎰'.repeat(2000)).success).toBe(true);
    expect(optionalTextSchema.safeParse('🎰'.repeat(2001)).success).toBe(false);
    expect(optionalTextSchema.safeParse(`  ${'x'.repeat(2000)}  `).success).toBe(true);
    expect(optionalTextSchema.safeParse('   ').success).toBe(true);
    expect(requiredTextSchema.safeParse('   ').success).toBe(false);
  });

  it('reports the problem as an issue message', () => {
    expect(textProblem('ok')).toBeNull();
    expect(textProblem('x'.repeat(2001))).toBe('too_long:{"max":2000}');
  });
});

describe('toApiLines', () => {
  it('trims the provider and leaves the credits as they are', () => {
    expect(toApiLines([{ provider: '  Runway ', credits: '120.50' }])).toEqual([
      { provider: 'Runway', credits: '120.50' },
    ]);
  });
});
