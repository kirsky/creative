/** Reloads the page. Its own module so tests can replace it: jsdom cannot navigate. */
export function reloadPage() {
  window.location.reload();
}
