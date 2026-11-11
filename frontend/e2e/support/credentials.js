/**
 * The demo accounts' passwords, read from E2E_CREDENTIALS (the stdout of
 * `composer demo:credentials:rotate`: one `username<TAB>password` line per account), held in
 * memory only. Error messages name line numbers and usernames, never a password.
 */

/** The accounts of the demo scenario (backend/src/Demo/DemoScenario.php). */
export const ACCOUNTS = Object.freeze({
  dmitry: { displayName: 'Dmitry P.', role: 'buyer' },
  elena: { displayName: 'Elena V.', role: 'buyer' },
  anna: { displayName: 'Anna K.', role: 'designer' },
  igor: { displayName: 'Igor S.', role: 'designer' },
  maria: { displayName: 'Maria T.', role: 'designer' },
  manager: { displayName: 'Olga M.', role: 'manager' },
});

/** @typedef {keyof typeof ACCOUNTS} Username */

/**
 * @param {string | undefined} text
 * @returns {Map<string, string>} username -> password
 */
export function parseCredentials(text) {
  if (text === undefined || text.trim() === '') {
    throw new Error('E2E_CREDENTIALS is empty: run the suite with `make e2e`, which provides it.');
  }
  /** @type {Map<string, string>} */
  const credentials = new Map();
  text
    .split(/\r?\n/)
    .filter((line) => line !== '')
    .forEach((line, index) => {
      const fields = line.split('\t');
      if (fields.length !== 2 || fields[0] === '' || fields[1] === '') {
        throw new Error(
          `E2E_CREDENTIALS line ${index + 1} is not "username<TAB>password" (${fields.length} fields).`,
        );
      }
      credentials.set(fields[0], fields[1]);
    });
  const missing = Object.keys(ACCOUNTS).filter((username) => !credentials.has(username));
  if (missing.length > 0) {
    throw new Error(`E2E_CREDENTIALS has no password for: ${missing.join(', ')}.`);
  }
  return credentials;
}

/** @type {Map<string, string> | null} */
let cache = null;

/**
 * @param {Username} username
 * @returns {string}
 */
export function passwordOf(username) {
  cache ??= parseCredentials(process.env.E2E_CREDENTIALS);
  const password = cache.get(username);
  if (password === undefined) {
    throw new Error(`No password for ${username}.`);
  }
  return password;
}
