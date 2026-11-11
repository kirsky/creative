const { default: JSDOMEnvironment } = require('jest-environment-jsdom');

/**
 * jsdom lacks the fetch family that react-router's data router builds on. Node has them, so
 * hand them over. AbortController has to come from Node too: Node's Request rejects jsdom's.
 */
class JSDOMEnvironmentWithFetchApi extends JSDOMEnvironment {
  constructor(...args) {
    super(...args);
    Object.assign(this.global, {
      Request,
      Response,
      Headers,
      TextEncoder,
      TextDecoder,
      AbortController,
      AbortSignal,
    });
  }
}

module.exports = JSDOMEnvironmentWithFetchApi;
