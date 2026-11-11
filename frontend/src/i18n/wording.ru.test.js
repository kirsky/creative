import ruBoard from './locales/ru/board.json';
import ruCommon from './locales/ru/common.json';
import ruDashboard from './locales/ru/dashboard.json';
import ruErrors from './locales/ru/errors.json';
import ruTask from './locales/ru/task.json';

/**
 * Wording decisions for the Russian interface, kept as tests so a later edit does not bring the
 * awkward phrasing back. The navigation label is the one the back link must match.
 */
describe('Russian wording', () => {
  it('says the missing thing "оно удалено", not "это удалено"', () => {
    expect(ruErrors.not_found).toBe(
      'Не найдено. Возможно, оно удалено или у вас нет доступа к нему.',
    );
  });

  it('calls the audience section Таргетинг', () => {
    expect(ruTask.create.sections.target).toBe('Таргетинг');
  });

  it('says a cancellation is irreversible without repeating the verb', () => {
    expect(ruTask.actions.cancelDialog.message).toBe(
      'Задача будет отменена и исчезнет с доски. Это действие необратимо.',
    );
  });

  it('labels the revision turnaround "Время на правки", on the task page and the dashboard alike', () => {
    expect(ruTask.side.revisionTurnaround).toBe('Время на правки');
    expect(ruDashboard.time.revision).toBe(ruTask.side.revisionTurnaround);
  });

  it('links back to the page the navigation calls "Моя работа"', () => {
    expect(ruCommon.nav.myWork).toBe('Моя работа');
    expect(ruTask.page.backToMyWork).toBe('Назад к моей работе');
  });

  it('uses the same word for revisions in the dashboard hint as in the columns', () => {
    expect(ruDashboard.tiles.active.hint).toBe('в работе, на проверке, нужны правки');
  });

  it('describes a lifted card as moved, never as taken', () => {
    expect(ruBoard.dnd.lifted).toBe('Задача «{{offer}}» взята для перемещения.');
    expect(ruBoard.dnd.instructions).toMatch(/^Чтобы переместить задачу с клавиатуры/);
  });

  it('says a released card with pending revisions "Ждёт правок", as the English marker does', () => {
    expect(ruBoard.card.returning).toBe('Ждёт правок (освобождена)');
  });
});
