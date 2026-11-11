/**
 * Runs once in the Jest parent process, before any worker starts, so every worker inherits it.
 * Tests of local time formatting (including the daylight-saving change) need a zone that has one.
 */
module.exports = async () => {
  process.env.TZ = 'Europe/Berlin';
};
