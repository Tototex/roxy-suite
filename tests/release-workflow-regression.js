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
    .replaceAll('${{ steps.version.outputs.tag }}', 'v1.2.3')
    .replaceAll('${{ steps.version.outputs.zip_name }}', 'roxy-suite-1.2.3.zip')
    .replaceAll('${{ steps.version.outputs.manifest_name }}', 'roxy-suite-1.2.3.manifest.json');
};
(async () => {
  {
    const program = new AsyncFunction('github', 'context', 'core', script('Check target release assets'));
    const context = { repo: { owner: 'fixture', repo: 'private' } };
    const outputs = {};
    const core = { setOutput: (key, value) => { outputs[key] = value; } };
    const github = { rest: {
      repos: { getReleaseByTag: async () => ({ data: { assets: [
        { name: 'roxy-suite-1.2.3.zip', state: 'uploaded', size: 100 },
        { name: 'roxy-suite-1.2.3.manifest.json', state: 'uploaded', size: 50 }
      ] } }) },
      git: { getRef: async () => ({ data: { object: { sha: context.sha } } }) }
    } };
    await program(github, context, core);
    check(outputs.complete === 'true', 'target release is complete only when both versioned assets exist');
    github.rest.repos.getReleaseByTag = async () => ({ data: { assets: [
      { name: 'roxy-suite-1.2.3.zip', state: 'starter', size: 100 },
      { name: 'roxy-suite-1.2.3.manifest.json', state: 'uploaded', size: 50 }
    ] } });
    await program(github, context, core);
    check(outputs.exists === 'true' && outputs.complete === 'false', 'starter or incomplete assets keep an existing target release retryable');
    github.rest.git.getRef = async () => ({ data: { object: { sha: 'b'.repeat(40) } } });
    await assert.rejects(program(github, context, core), /different commit/);
    check(true, 'existing target release with a different tag commit is not overwritten');
    github.rest.git.getRef = async () => { const error = new Error('tag ref unavailable'); error.status = 404; throw error; };
    await assert.rejects(program(github, context, core), /tag ref unavailable/);
    check(true, 'missing tag reference for an existing release fails closed');
    github.rest.git.getRef = async () => ({ data: { object: { sha: context.sha } } });
    github.rest.repos.getReleaseByTag = async () => { const error = new Error('not found'); error.status = 404; throw error; };
    await program(github, context, core);
    check(outputs.complete === 'false', 'missing target release is eligible for creation');
    github.rest.repos.getReleaseByTag = async () => { const error = new Error('forbidden'); error.status = 403; throw error; };
    await assert.rejects(program(github, context, core), /forbidden/);
    check(true, 'release lookup authorization errors fail closed');
  }
  {
    const segment = source.split('      - name: Decide whether to release\n')[1]?.split('\n      - name: ')[0];
    assert.ok(segment, 'Missing version comparison step');
    const shell = segment.split('        run: |\n')[1].split('\n').map(line => line.slice(10)).join('\n')
      .replaceAll('${{ steps.version.outputs.tag }}', 'v1.2.3')
      .replaceAll('${{ steps.latest.outputs.tag }}', '$LATEST_TAG_FIXTURE')
      .replaceAll('${{ steps.target_release.outputs.exists }}', '$TARGET_EXISTS_FIXTURE')
      .replaceAll('${{ steps.target_release.outputs.complete }}', '$TARGET_COMPLETE_FIXTURE');
    const bashAvailable = spawnSync('bash', ['-c', 'true'], { encoding: 'utf8' }).status === 0;
    const decide = (latest, complete, exists = 'false') => {
      const output = path.join(os.tmpdir(), `roxy-release-decision-${process.pid}-${Math.random()}`);
      const result = spawnSync('bash', ['-c', shell], { encoding: 'utf8', env: {
        ...process.env,
        GITHUB_OUTPUT: output,
        LATEST_TAG_FIXTURE: latest,
        TARGET_EXISTS_FIXTURE: exists,
        TARGET_COMPLETE_FIXTURE: complete
      } });
      const value = fs.existsSync(output) ? fs.readFileSync(output, 'utf8') : '';
      if (fs.existsSync(output)) fs.unlinkSync(output);
      assert.equal(result.status, 0, result.stderr);
      return value.trim();
    };
    if (bashAvailable) {
      check(decide('v1.2.3', 'false') === 'should_release=true', 'same-version missing release is created');
      check(decide('v1.2.3', 'true', 'true') === 'should_release=false', 'same-version complete release is skipped');
      check(decide('v1.2.2', 'false') === 'should_release=true', 'newer version release proceeds');
      check(decide('v1.2.4', 'false') === 'should_release=false', 'older version never replaces a newer release');
      check(decide('v1.2.4', 'false', 'true') === 'should_release=true', 'older incomplete existing release can be repaired');
    } else {
      check(shell.includes('TARGET_RELEASE_COMPLETE') && shell.includes('should_release=true'), 'release decision script includes incomplete-release retry guard (shell unavailable locally)');
    }
  }
  for (const name of ['Validate an existing version tag', 'Create Git tag when missing']) {
    const program = new AsyncFunction('github', 'context', 'core', script(name));
    let creates = 0;
    const context = { repo: { owner: 'fixture', repo: 'private' }, sha: 'a'.repeat(40) };
    const github = { rest: { git: {
      getRef: async () => ({ data: { object: { sha: context.sha } } }),
      createRef: async () => { creates++; }
    } } };
    await program(github, context, { info: () => {} });
    check(creates === 0, `${name} accepts existing tag for same commit without mutation`);
    github.rest.git.getRef = async () => ({ data: { object: { sha: 'b'.repeat(40) } } });
    await assert.rejects(program(github, context, { info: () => {} }), /different commit/);
    check(creates === 0, `${name} refuses existing tag for different commit`);
    github.rest.git.getRef = async () => { const error = new Error('not found'); error.status = 404; throw error; };
    await program(github, context, { info: () => {} });
    check(creates === (name === 'Create Git tag when missing' ? 1 : 0), `${name} handles absent tag`);
    github.rest.git.getRef = async () => { const error = new Error('auth unavailable'); error.status = 403; throw error; };
    await assert.rejects(program(github, context, { info: () => {} }), /auth unavailable/);
    check(true, `${name} does not treat authorization failure as missing tag`);
    if (name === 'Create Git tag when missing') {
      github.rest.git.getRef = async () => { const error = new Error('not found'); error.status = 404; throw error; };
      github.rest.git.createRef = async () => { const error = new Error('tag created concurrently'); error.status = 422; throw error; };
      await assert.rejects(program(github, context, { info: () => {} }), /tag created concurrently/);
      check(true, 'tag-creation race fails when the racing tag points elsewhere or cannot be verified');
      let raceReads = 0;
      github.rest.git.getRef = async () => {
        raceReads++;
        if (raceReads === 1) { const error = new Error('not found'); error.status = 404; throw error; }
        return { data: { object: { sha: context.sha } } };
      };
      github.rest.git.createRef = async () => {
        const error = new Error('tag created concurrently'); error.status = 422; throw error;
      };
      const beforeRace = creates;
      await program(github, context, { info: () => {} });
      check(creates === beforeRace, 'tag-creation race is accepted only after exact same-commit ref verification');
    }
  }
  check(source.includes('overwrite_files: true'), 'release retry can replace partially uploaded assets');
  const zipBuild = source.split('      - name: Build plugin zip\n')[1]?.split('\n      - name: ')[0];
  assert.ok(zipBuild, 'Missing release ZIP build step');
  const zipIntegrityCheck = zipBuild.indexOf('unzip -t "$ZIP_PATH"');
  check(zipIntegrityCheck > zipBuild.indexOf('zip -X') && zipIntegrityCheck < zipBuild.indexOf("node <<'NODE'"), 'release ZIP integrity is checked after creation and before its manifest is generated');
  check(zipBuild.includes('Release ZIP failed its integrity test'), 'invalid release archive fails closed with actionable output');
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
