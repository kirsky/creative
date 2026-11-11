import { sumCredits } from './credits';

describe('sumCredits', () => {
  it('adds decimals exactly', () => {
    expect(sumCredits(['0.10', '0.20'])).toBe('0.30');
    expect(sumCredits(['120.00', '40.00'])).toBe('160.00');
    expect(sumCredits(['1000000.07', '0.30'])).toBe('1000000.37');
    expect(sumCredits(['0.99', '0.01'])).toBe('1.00');
  });

  it('is zero for nothing and ignores amounts it cannot read', () => {
    expect(sumCredits([])).toBe('0.00');
    expect(sumCredits(['abc', '', '1.5', '2.00'])).toBe('2.00');
  });
});
