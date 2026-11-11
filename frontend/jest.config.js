/** @type {import('jest').Config} */
module.exports = {
  testEnvironment: '<rootDir>/src/test/jsdomEnvironment.js',
  roots: ['<rootDir>/src', '<rootDir>/scripts'],
  setupFilesAfterEnv: ['<rootDir>/jest.setup.js'],
  moduleNameMapper: {
    '\\.(css|scss)$': '<rootDir>/src/test/styleStub.js',
  },
  clearMocks: true,
};
