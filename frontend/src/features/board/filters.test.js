import { makeOptions } from '../../test/renderApp';
import { boardPath, filtersToParams, hasFilters, parseFilters } from './filters';

const options = makeOptions();

/** @param {string} search */
const parse = (search, withOptions = true) =>
  parseFilters(new URLSearchParams(search), withOptions ? options : undefined);

describe('parseFilters', () => {
  it('reads every filter from the URL', () => {
    expect(parse('?priority=high&creativeType=video&designerId=3&q=Casino')).toEqual({
      priority: 'high',
      creativeType: 'video',
      designerId: 3,
      q: 'Casino',
    });
  });

  it('is empty without parameters', () => {
    expect(parse('')).toEqual({});
  });

  it('ignores a priority or creative type the options do not know', () => {
    expect(parse('?priority=asap&creativeType=hologram')).toEqual({});
    expect(parse('?priority=HIGH')).toEqual({});
  });

  it.each(['0', '-1', '1.5', 'abc', '', '007', '1e3', '99999999999'])(
    'ignores designerId=%s',
    (value) => {
      expect(parse(`?designerId=${value}`)).toEqual({});
    },
  );

  it('trims q and ignores a blank or over-long one', () => {
    expect(parse('?q=%20%20Лото%20')).toEqual({ q: 'Лото' });
    expect(parse('?q=%20%20')).toEqual({});
    expect(parse(`?q=${'x'.repeat(101)}`)).toEqual({});
    expect(parse(`?q=${'x'.repeat(100)}`)).toEqual({ q: 'x'.repeat(100) });
  });

  it('takes only designerId and q before the options are known', () => {
    expect(parse('?priority=high&creativeType=video&designerId=3&q=a', false)).toEqual({
      designerId: 3,
      q: 'a',
    });
  });

  it('ignores parameters it does not know', () => {
    expect(parse('?foo=bar&includeCancelled=0')).toEqual({});
  });
});

describe('filtersToParams', () => {
  it('writes a filter change into the existing parameters and keeps the unknown ones', () => {
    const next = filtersToParams(new URLSearchParams('?priority=high&foo=bar'), {
      priority: undefined,
      designerId: 7,
    });
    expect(next.toString()).toBe('foo=bar&designerId=7');
  });

  it('removes a blank q', () => {
    expect(filtersToParams(new URLSearchParams('?q=a'), { q: '  ' }).toString()).toBe('');
  });
});

describe('boardPath', () => {
  it('always asks for the cancelled tasks', () => {
    expect(boardPath({})).toBe('/api/tasks?includeCancelled=1');
  });

  it('puts the filters before includeCancelled in a fixed order and encodes q', () => {
    expect(
      boardPath({ q: 'Лото клуб', designerId: 3, creativeType: 'video', priority: 'high' }),
    ).toBe(
      '/api/tasks?priority=high&creativeType=video&designerId=3&q=%D0%9B%D0%BE%D1%82%D0%BE+%D0%BA%D0%BB%D1%83%D0%B1&includeCancelled=1',
    );
  });
});

describe('hasFilters', () => {
  it('tells whether anything narrows the board', () => {
    expect(hasFilters({})).toBe(false);
    expect(hasFilters({ q: 'a' })).toBe(true);
    expect(hasFilters({ designerId: 2 })).toBe(true);
  });
});
