import { queryKeys } from './queryKeys';

describe('queryKeys', () => {
  it('builds the agreed keys', () => {
    expect(queryKeys.me()).toEqual(['me']);
    expect(queryKeys.options()).toEqual(['options']);
    expect(queryKeys.board({ q: 'x' })).toEqual(['board', { q: 'x' }]);
    expect(queryKeys.task(7)).toEqual(['task', 7]);
    expect(queryKeys.taskHistory(7)).toEqual(['task', 7, 'history']);
    expect(queryKeys.dashboard('30d')).toEqual(['dashboard', '30d']);
    expect(queryKeys.aiProviders()).toEqual(['aiProviders']);
  });
});
