import assert from 'node:assert/strict';
import { mkdtempSync, mkdirSync, writeFileSync, rmSync } from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import test from 'node:test';
import { brokenLinks, markdownDestinations } from '../check-docs-links.mjs';

test('extracts inline, image and reference destinations with titles and parentheses', () => {
  assert.deepEqual(markdownDestinations([
    '[plain](one.md#heading)',
    '![image](image.png "title")',
    '[spaces](<file name.md>)',
    '[parens](file(1).md)',
    '[escaped](file\\(2\\).md)',
    '[reference][ref]',
    '[ref]: reference.md "Reference title"',
  ].join('\n')), ['one.md#heading', 'image.png', 'file name.md', 'file(1).md', 'file(2).md', 'reference.md']);
});

test('ignores fenced and inline code, including longer fences and tilde fences', () => {
  assert.deepEqual(markdownDestinations([
    '````markdown', '[bad](missing.md)', '```', '[still bad](missing.md)', '````',
    '  ~~~', '[bad](missing.md)', '  ~~~',
    '`[example](missing.md)`', '[real](exists.md)',
  ].join('\n')), ['exists.md']);
});

test('resolves relative paths, encoded names, fragments and queries; reports every missing path', () => {
  const root = mkdtempSync(path.join(os.tmpdir(), 'docs-links-'));
  try {
    mkdirSync(path.join(root, 'docs'));
    writeFileSync(path.join(root, 'file name.md'), '');
    const markdown = [
      '[file](../file%20name.md#heading)',
      '[query](../file%20name.md?raw=1#heading)',
      '[directory](../docs/)',
      '[web](https://example.com/missing)', '[http](http://example.com)',
      '[mail](mailto:test@example.com)', '[anchor](#heading)', '[site](/submit/)',
      '[missing](missing.md)', '![missing image](missing.png)', '[bad encoding](%zz.md)',
    ].join('\n');
    assert.deepEqual(brokenLinks(markdown, 'docs/source.md', root), ['missing.md', 'missing.png', '%zz.md']);
  } finally {
    rmSync(root, { recursive: true, force: true });
  }
});
