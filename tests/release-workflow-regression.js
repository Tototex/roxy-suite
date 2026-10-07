// Executes the exact embedded release guard/manifest programs with private fixtures.
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const crypto = require('node:crypto');
const assert = require('node:assert/strict');
const { spawnSync } = require('node:child_process');
const source = fs.readFileSync(path.join(__dirname, '..', '.github', 'workflows', 'release-on-version-push.yml'), 'utf8').replace(/\r\n/g, '\n');
const AsyncFunction = Object.getPrototypeOf(async function () {}).constructor;
let checks = 0;
const check = (ok, label) => { assert.ok(ok, label); checks++; };
const script = (name) => {
  const segment = source.split(`      - name: ${name}\n`)[1]?.split('\n      - name: ')[0];
  assert.ok(segment, `Missing step ${name}`);
  return segment.split('          script: |\n')[1].split('\n').map(line => line.slice(12)).join('\n')
    .replaceAll('${{ steps.version.outputs.tag }}', 'v1.2.3');
};
(async () => {
  for (const name of ['Reject an already-used version tag', 'Create Git tag']) {
    const program = new AsyncFunction('github', 'context', script(name));
    let creates = 0;
    const context = { repo: { owner: 'fixture', repo: 'private' }, sha: 'a'.repeat(40) };
    const github = { rest: { git: {
      getRef: async () => ({ data: {} }),
      createRef: async () => { creates++; }
    } } };
    await assert.rejects(program(github, context), /Refusing to reuse/);
    check(creates === 0, `${name} refuses existing tag without mutation`);
    github.rest.git.getRef = async () => { const error = new Error('not found'); error.status = 404; throw error; };
    await program(github, context);
    check(creates === (name === 'Create Git tag' ? 1 : 0), `${name} handles absent tag`);
    github.rest.git.getRef = async () => { const error = new Error('auth unavailable'); error.status = 403; throw error; };
    await assert.rejects(program(github, context), /auth unavailable/);
    check(true, `${name} does not treat authorization failure as missing tag`);
    if (name === 'Create Git tag') {
      github.rest.git.getRef = async () => { const error = new Error('not found'); error.status = 404; throw error; };
      github.rest.git.createRef = async () => { const error = new Error('tag created concurrently'); error.status = 422; throw error; };
      await assert.rejects(program(github, context), /tag created concurrently/);
      check(true, 'tag-creation race fails before release publishing');
    }
  }
  const temp = fs.mkdtempSync(path.join(os.tmpdir(), 'roxy-release-fixture-'));
  check(source.includes("--exclude='/tools/'"), 'maintenance/conversion tools are excluded from public runtime releases');
  check(source.includes("--exclude='/.gitignore'"), 'repository ignore metadata is excluded from public runtime releases');
  try {
    fs.mkdirSync(path.join(temp, 'build', 'roxy-suite', 'includes'), { recursive: true });
    fs.writeFileSync(path.join(temp, 'build', 'roxy-suite', 'z.php'), 'fixture-z');
    fs.writeFileSync(path.join(temp, 'build', 'roxy-suite', 'includes', 'a.php'), 'fixture-a');
    fs.writeFileSync(path.join(temp, 'build', 'fixture.zip'), 'fixture archive bytes—not a ZIP packaging test');
    const embedded = source.split("          node <<'NODE'\n")[1]?.split('\n          NODE')[0]
      .split('\n').map(line => line.slice(10)).join('\n');
    assert.ok(embedded);
    const result = spawnSync(process.execPath, ['-e', embedded], { cwd: temp, encoding: 'utf8', env: {
      ...process.env, SOURCE_SHA: 'a'.repeat(40), RELEASE_VERSION: '1.2.3', ZIP_NAME: 'fixture.zip', MANIFEST_NAME: 'fixture.manifest.json'
    } });
    assert.equal(result.status, 0, result.stderr);
    const manifest = JSON.parse(fs.readFileSync(path.join(temp, 'build', 'fixture.manifest.json'), 'utf8'));
    check(manifest.source_sha === 'a'.repeat(40) && manifest.version === '1.2.3', 'manifest binds source SHA and version');
    check(manifest.files.map(file => file.path).join(',') === 'roxy-suite/includes/a.php,roxy-suite/z.php', 'manifest paths are sorted and package-relative');
    const hash = bytes => crypto.createHash('sha256').update(bytes).digest('hex');
    check(manifest.files.every(file => file.sha256 === hash(fs.readFileSync(path.join(temp, 'build', file.path)))), 'each packaged-file hash matches bytes');
    check(manifest.archive.sha256 === hash(fs.readFileSync(path.join(temp, 'build', 'fixture.zip'))), 'archive hash matches fixture bytes');
    check(source.includes("--exclude='/tests/'") && source.includes("--exclude='/docs/'"), 'runtime package excludes tests and audit documents');
    check(source.includes('zip -X') && source.includes('LC_ALL=C sort') && source.includes('SOURCE_DATE_EPOCH'), 'package declares deterministic ordering and timestamps');
  } finally { fs.rmSync(temp, { recursive: true, force: true }); }
  console.log(`PASS: ${checks} release guard/manifest checks; no hosted release or actual ZIP build executed.`);
})().catch(error => { console.error(error); process.exitCode = 1; });
