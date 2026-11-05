const assert = require('node:assert/strict');
const { mkdtempSync, mkdirSync, rmSync, writeFileSync } = require('node:fs');
const { createRequire } = require('node:module');
const { tmpdir } = require('node:os');
const { dirname, join } = require('node:path');
const { test } = require('node:test');

// Resolve the dependencies Jest actually loads, including pnpm's overrides and
// patch hash. A separate direct dependency could conceal an unpatched consumer.
let jestConfigRequire = require;
for (const name of ['jest', 'jest-cli', 'jest-config']) {
  jestConfigRequire = createRequire(jestConfigRequire.resolve(`${name}/package.json`));
}
const { mergeConfig } = jestConfigRequire('jest-config');
const mergePath = jestConfigRequire.resolve('deepmerge');

for (const [entry, merge] of [
  ['CommonJS', jestConfigRequire('deepmerge')],
  ['UMD', require(join(dirname(mergePath), 'umd.js'))],
]) {
  test(`deepmerge ${entry} blocks returned-prototype poisoning for all reported target shapes`, () => {
    const payload = () => JSON.parse('{"__proto__":{"isAdmin":true}}');
    const results = [
      merge(Object.create(null), payload()),
      merge({ session: null }, { session: payload() }).session,
      merge(payload(), {}),
      merge({ items: [payload()] }, { items: [] }).items[0],
    ];
    for (const result of results) {
      assert.equal(Object.getPrototypeOf(result), Object.prototype);
      assert.equal(result.isAdmin, undefined);
      assert.equal(Object.hasOwn(result, '__proto__'), false);
    }
    assert.equal({}.isAdmin, undefined);
  });
}

test('the real Jest configuration merger preserves nested values, arrays and regular expressions', () => {
  const pattern = /\.tsx?$/;
  const defaults = { globals: { nested: { first: true }, flags: ['first'] }, transform: { pattern } };
  const overrides = { globals: { nested: { second: true }, flags: ['second'] } };
  assert.deepEqual(mergeConfig(defaults, overrides), {
    globals: { nested: { first: true, second: true }, flags: ['first', 'second'] },
    transform: { pattern },
  });
  assert.deepEqual(defaults.globals.flags, ['first']);
  assert.deepEqual(overrides.globals.flags, ['second']);
  assert.equal(mergeConfig({ globals: { session: null } }, {
    globals: { session: JSON.parse('{"__proto__":{"isAdmin":true}}') },
  }).globals.session.isAdmin, undefined);
});

test('Jest glob dependency matches test files and ignore patterns through bundled and raw entries', async () => {
  const directory = mkdtempSync(join(tmpdir(), 'vora-dependency-glob-'));
  try {
    mkdirSync(join(directory, 'src'));
    mkdirSync(join(directory, 'node_modules'));
    for (const file of ['src/button.test.tsx', 'src/view.test.ts', 'src/plain.ts', 'node_modules/ignored.test.ts']) {
      writeFileSync(join(directory, file), '');
    }
    const options = { cwd: directory, nodir: true, ignore: ['node_modules/**'] };
    for (const entry of ['glob', 'glob/raw']) {
      const { glob, globSync } = jestConfigRequire(entry);
      assert.deepEqual(globSync('**/*.test.ts?(x)', options).sort(), ['src/button.test.tsx', 'src/view.test.ts']);
      assert.deepEqual((await glob('**/*.test.ts?(x)', options)).sort(), ['src/button.test.tsx', 'src/view.test.ts']);
      const controller = new AbortController();
      controller.abort();
      await assert.rejects(glob('**/*', { ...options, signal: controller.signal }), { name: 'AbortError' });
    }
  } finally {
    rmSync(directory, { recursive: true, force: true });
  }
});
