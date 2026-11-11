import '@testing-library/jest-dom';
import i18n from './src/i18n';

// Every test starts in English with no stored language choice.
beforeEach(async () => {
  window.localStorage.clear();
  await i18n.changeLanguage('en');
});

// jsdom lacks these browser APIs, which Mantine uses.
Object.defineProperty(window, 'matchMedia', {
  writable: true,
  value: (/** @type {string} */ query) => ({
    matches: false,
    media: query,
    onchange: null,
    addListener: () => {},
    removeListener: () => {},
    addEventListener: () => {},
    removeEventListener: () => {},
    dispatchEvent: () => false,
  }),
});

class ResizeObserverStub {
  observe() {}
  unobserve() {}
  disconnect() {}
}
window.ResizeObserver = ResizeObserverStub;
window.HTMLElement.prototype.scrollIntoView = () => {};

// The test environment hands jsdom Node's AbortController (see jsdomEnvironment.js), and jsdom's
// addEventListener refuses a signal that is not its own, which Mantine's swipeable toasts pass.
// Stand in for the option: listen without it and remove the listener when the signal aborts.
const nativeAddEventListener = EventTarget.prototype.addEventListener;
EventTarget.prototype.addEventListener = function addEventListener(
  /** @type {string} */ type,
  /** @type {any} */ listener,
  /** @type {any} */ options,
) {
  try {
    return nativeAddEventListener.call(this, type, listener, options);
  } catch (error) {
    if (!(error instanceof TypeError) || typeof options !== 'object' || !options?.signal) {
      throw error;
    }
    const { signal, ...rest } = options;
    if (signal.aborted) {
      return undefined;
    }
    nativeAddEventListener.call(this, type, listener, rest);
    signal.addEventListener('abort', () => this.removeEventListener(type, listener, rest), {
      once: true,
    });
    return undefined;
  }
};
