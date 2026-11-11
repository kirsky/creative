import i18n from '../../i18n';
import { ANNA, EVENT_FIXTURES, makeEvent } from '../../test/taskDetail';
import { EVENT_TYPES, renderEvent, renderEventDetails } from './eventText';

const LANGUAGES = /** @type {const} */ (['en', 'ru']);

describe('renderEvent', () => {
  it('covers exactly the nine event types of the API', () => {
    expect(EVENT_TYPES).toEqual([
      'task_created',
      'task_picked_up',
      'task_released',
      'round_submitted',
      'revisions_requested',
      'task_approved',
      'task_cancelled',
      'brief_edited',
      'ai_usage_changed',
    ]);
    expect(EVENT_FIXTURES.map((event) => event.type)).toEqual(EVENT_TYPES);
  });

  describe.each(LANGUAGES)('in %s', (lng) => {
    const t = i18n.getFixedT(lng);

    it.each(EVENT_FIXTURES.map((event) => [event.type, event]))(
      '%s is a translated sentence with the actor and no raw key',
      (_type, event) => {
        const text = renderEvent(t, /** @type {any} */ (event), lng);
        expect(text.length).toBeGreaterThan(0);
        expect(text).toContain('Anna K.');
        expect(text).not.toMatch(/\b(task|common):\w|events\.|\{\{/);
      },
    );

    it('falls back to a translated generic line for an unknown type', () => {
      const text = renderEvent(t, makeEvent('task_teleported', { x: 1 }), lng);
      expect(text).toContain('Anna K.');
      expect(text).not.toMatch(/task_teleported|task:|events\./);
    });
  });

  it('writes the English sentences', () => {
    const t = i18n.getFixedT('en');
    /** @param {string} type @param {Record<string, unknown>} [payload] */
    const text = (type, payload) => renderEvent(t, makeEvent(type, payload), 'en');
    expect(text('task_created')).toBe('Anna K. created the task');
    expect(text('task_picked_up')).toBe('Anna K. picked up the task');
    expect(text('task_released')).toBe('Anna K. released the task');
    expect(text('round_submitted', { round: 2, fileCount: 3 })).toBe(
      'Anna K. submitted round 2 (3 files)',
    );
    expect(text('round_submitted', { round: 1, fileCount: 1 })).toBe(
      'Anna K. submitted round 1 (1 file)',
    );
    expect(text('revisions_requested', { round: 1, reason: 'Shorter hook' })).toBe(
      'Anna K. requested revisions on round 1: Shorter hook',
    );
    expect(text('task_approved', { round: 2, note: 'Great' })).toBe('Anna K. approved round 2');
    expect(text('task_cancelled', { reason: 'Offer paused' })).toBe(
      'Anna K. cancelled the task: Offer paused',
    );
    expect(text('task_cancelled', { reason: null })).toBe('Anna K. cancelled the task');
    expect(text('task_cancelled')).toBe('Anna K. cancelled the task');
    expect(text('brief_edited', { changes: [] })).toBe('Anna K. edited the brief');
    expect(
      text('ai_usage_changed', {
        round: 2,
        lines: [
          { provider: 'Runway', credits: '120.00' },
          { provider: 'Midjourney', credits: '40.00' },
        ],
      }),
    ).toBe('Anna K. updated AI usage for round 2: Runway 120.00, Midjourney 40.00');
  });

  it('writes the Russian plural of the file count', () => {
    const t = i18n.getFixedT('ru');
    /** @param {number} fileCount */
    const text = (fileCount) =>
      renderEvent(t, makeEvent('round_submitted', { round: 2, fileCount }), 'ru');
    expect(text(1)).toContain('1 файл)');
    expect(text(3)).toContain('3 файла)');
    expect(text(5)).toContain('5 файлов)');
  });

  it('copes with payloads that miss their fields', () => {
    const t = i18n.getFixedT('en');
    for (const event of EVENT_FIXTURES) {
      const text = renderEvent(t, { ...event, payload: /** @type {any} */ (null) }, 'en');
      expect(text).toContain('Anna K.');
      expect(text).not.toMatch(/undefined|null|NaN|task:/);
    }
  });

  it('keeps markup in user text as plain text', () => {
    const t = i18n.getFixedT('en');
    const text = renderEvent(
      t,
      makeEvent('revisions_requested', { round: 1, reason: '<script>alert(1)</script> 🎰 Лото' }),
      'en',
    );
    expect(text).toBe('Anna K. requested revisions on round 1: <script>alert(1)</script> 🎰 Лото');
  });

  it('uses the language of the translator when none is passed', () => {
    const text = renderEvent(i18n.getFixedT('ru'), makeEvent('task_created'), undefined);
    expect(text).toBe('Anna K.: задача создана');
  });

  it('names the actor as the API sent it', () => {
    const t = i18n.getFixedT('en');
    const event = makeEvent(
      'task_created',
      {},
      { actor: { ...ANNA, displayName: 'Анна <b>К</b>' } },
    );
    expect(renderEvent(t, event, 'en')).toBe('Анна <b>К</b> created the task');
  });
});

describe('renderEventDetails', () => {
  const edited = (/** @type {unknown[]} */ changes) => makeEvent('brief_edited', { changes });

  it('is empty for every event but brief_edited', () => {
    const t = i18n.getFixedT('en');
    for (const event of EVENT_FIXTURES.filter(({ type }) => type !== 'brief_edited')) {
      expect(renderEventDetails(t, event, 'en')).toEqual([]);
    }
  });

  it('shows field: old → new for each change, with translated labels and values', () => {
    const t = i18n.getFixedT('en');
    const lines = renderEventDetails(
      t,
      edited([
        { field: 'offer', old: 'Casino Royal', new: 'Casino Roma' },
        { field: 'geo', old: ['DE'], new: ['AT', 'DE', 'EU'] },
        { field: 'creativeType', old: 'video', new: 'carousel' },
        { field: 'quantity', old: 3, new: 5 },
        { field: 'priority', old: 'normal', new: 'urgent' },
        { field: 'deadline', old: '2026-11-12T10:00:00Z', new: '2026-11-14T10:00:00Z' },
      ]),
      'en',
    );
    expect(lines).toEqual([
      'Offer: Casino Royal → Casino Roma',
      'GEO: Germany → Austria, Germany, Europe',
      'Creative type: Video → Carousel',
      'Quantity: 3 → 5',
      'Priority: Normal → Urgent',
      expect.stringMatching(/^Deadline: .*2026.* → .*2026/),
    ]);
  });

  it('translates the labels and the country names into Russian', () => {
    const t = i18n.getFixedT('ru');
    const lines = renderEventDetails(
      t,
      edited([
        { field: 'geo', old: ['DE'], new: ['AT'] },
        { field: 'priority', old: 'low', new: 'high' },
      ]),
      'ru',
    );
    expect(lines).toEqual(['ГЕО: Германия → Австрия', 'Приоритет: Низкий → Высокий']);
  });

  it('cuts a long description to 80 characters with an ellipsis', () => {
    const t = i18n.getFixedT('en');
    const long = 'a'.repeat(200);
    const [line] = renderEventDetails(
      t,
      edited([{ field: 'description', old: 'short', new: long }]),
      'en',
    );
    expect(line).toBe(`Description: short → ${'a'.repeat(80)}…`);
  });

  it('does not cut a description of 80 characters or less, nor split an emoji', () => {
    const t = i18n.getFixedT('en');
    const exactly80 = 'б'.repeat(80);
    const emoji = '🎰'.repeat(81);
    const lines = renderEventDetails(
      t,
      edited([{ field: 'description', old: exactly80, new: emoji }]),
      'en',
    );
    expect(lines).toEqual([`Description: ${exactly80} → ${'🎰'.repeat(80)}…`]);
  });

  it('shows a dash for an empty value and a generic label for an unknown field', () => {
    const t = i18n.getFixedT('en');
    const lines = renderEventDetails(
      t,
      edited([
        { field: 'description', old: '', new: 'Text' },
        { field: 'shoe_size', old: 1, new: 2 },
        { field: 'priority', old: 'mystery', new: null },
      ]),
      'en',
    );
    expect(lines[0]).toBe('Description: — → Text');
    expect(lines[1]).not.toMatch(/shoe_size|task:|fields\./);
    expect(lines[1]).toMatch(/1 → 2$/);
    expect(lines[2]).toBe('Priority: mystery → —');
  });

  it('ignores malformed change lists', () => {
    const t = i18n.getFixedT('en');
    expect(renderEventDetails(t, makeEvent('brief_edited', { changes: 'x' }), 'en')).toEqual([]);
    expect(renderEventDetails(t, makeEvent('brief_edited', {}), 'en')).toEqual([]);
    expect(renderEventDetails(t, edited([null, 5, { old: 1 }]), 'en')).toEqual([]);
  });
});
