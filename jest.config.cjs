/** @type {import('@jest/types').Config.InitialOptions} */
const config = {
  preset: 'ts-jest',
  testEnvironment: 'jsdom',
  testMatch: ['<rootDir>/resources/js/**/*.test.ts?(x)', '<rootDir>/tests-ts/**/*.test.ts?(x)'],
  setupFilesAfterEnv: ['<rootDir>/tests-ts/jest.setup.ts'],
  moduleNameMapper: {
    '^@/(.*)$': '<rootDir>/resources/js/$1',
  },
  // pnpm resolves ESM packages to node_modules/.pnpm/... paths; transform those
  // so dependencies like react-markdown/remark-gfm run under Jest's CJS runtime.
  transformIgnorePatterns: ['^((?!\\.pnpm).)*node_modules/(?!\\.pnpm/)'],
  moduleFileExtensions: ['ts', 'tsx', 'js', 'jsx', 'json', 'node'],
  transform: {
    '^.+\\.(ts|tsx|js|jsx)$': ['ts-jest', { tsconfig: { allowJs: true, module: 'commonjs', jsx: 'react-jsx' } }],
  },
};

module.exports = config;
