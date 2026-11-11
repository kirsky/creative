import '@testing-library/jest-dom';

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
