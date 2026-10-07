// Emit a committed runtime manifest without reading uncommitted/untracked files.
'use strict';
const { execFileSync } = require('node:child_process');
const crypto = require('node:crypto');
const path = require('node:path');
const root = path.resolve(process.argv[2] || path.join(__dirname, '..'));
const git = args => execFileSync('git', args, { cwd: root, maxBuffer: 64 * 1024 * 1024 });
const sha = git(['rev-parse', '--verify', (process.argv[3] || 'HEAD') + '^{commit}']).toString().trim();
if (!/^[a-f0-9]{40}$/.test(sha)) throw new Error('Invalid source commit');
const files = [];
for (const record of git(['ls-tree', '-r', '-z', sha]).toString().split('\0').filter(Boolean)) {
  const match = /^(\d+) (\w+) ([a-f0-9]+)\t([\s\S]+)$/.exec(record);
  if (!match) throw new Error('Invalid Git tree record');
  const [, mode, type, blob, name] = match;
  if (/^(?:\.github|build|tests|docs|tools)(?:\/|$)/.test(name) || ['RoxyEdit.md', '.gitignore'].includes(name) || name.split('/').includes('.DS_Store')) continue;
  if (type !== 'blob' || !['100644', '100755'].includes(mode) || name.startsWith('/') || name.split('/').some(part => ['..', '.', ''].includes(part))) throw new Error('Unsupported runtime path: ' + name);
  const bytes = git(['cat-file', 'blob', blob]);
  const text = /\.(?:php|js|css|json|md|txt|yml|yaml|html|svg)$/i.test(name);
  const canonical = text ? Buffer.from(bytes.toString('utf8').replace(/\r\n/g, '\n')) : bytes;
  files.push({ path: name, sha256: crypto.createHash('sha256').update(bytes).digest('hex'), canonical_sha256: crypto.createHash('sha256').update(canonical).digest('hex'), text });
}
files.sort((a, b) => a.path < b.path ? -1 : a.path > b.path ? 1 : 0);
process.stdout.write(JSON.stringify({ format_version: 1, source_sha: sha, files }, null, 2) + '\n');
