#!/usr/bin/env node
/**
 * Build the two GenD Society distribution zips from the module manifest.
 *
 *   node bin/build-dist.js                  build dist/full + dist/wporg, run every assertion
 *   node bin/build-dist.js --self-test      prove each assertion fails on an injected violation
 *
 * Options (mostly for the self-test):
 *   --root <dir>            plugin root to build (default: the repo this script lives in)
 *   --out <dir>             output dir (default: <root>/dist)
 *   --variant <name>        build only "full" or "wporg"
 *   --manifest-json <file>  use this manifest dump instead of running bin/manifest-json.php
 *   --no-header-transform   TEST ONLY: ship gend-society.php untouched (proves the Network rule)
 *   --allow-old-prefix      report old-prefix PHP globals as warnings (default until Plan 105-11)
 *   --enforce-old-prefix    fail on old-prefix PHP globals
 *
 * Output per variant: dist/<variant>/gend-society/ (unzipped, what Plugin Check reads),
 * dist/<variant>/gend-society.zip (entries rooted at gend-society/) and a .sha256 sidecar.
 *
 * File set (research 105 section 8): inc PHP by manifest tier (never by directory), plus
 * gend-society.php and readme.txt, plus every other path not excluded by
 * bin/dist/<variant>.exclude; assets/ files ship only when a shipped PHP/JS file names them.
 *
 * Every violation prints as "BUILD FAIL <variant>: <rule>: <path>"; the build reports all of
 * them, removes the failed variant's zip, and exits 1.
 */
'use strict';

const fs = require('fs');
const os = require('os');
const path = require('path');
const crypto = require('crypto');
const { spawnSync } = require('child_process');

const SLUG = 'gend-society';
const MAIN_FILE = 'gend-society.php';
const ALWAYS = [MAIN_FILE, 'readme.txt'];
const SCRIPT_ROOT = path.resolve(__dirname, '..');

// Plan 105-11 sets true (after the prefix rename lands on this branch).
const OLD_PREFIX_ENFORCED = false;

const WPORG_MAX_BYTES = 10 * 1024 * 1024;

// Defence in depth: these do NOT come from the exclude files, so a bad edit there still fails.
const VARIANTS = {
	full: {
		forbiddenTiers: ['hub'],
		forbiddenPaths: [],
		stampTiers: 'core,customer,container,updater',
		dropUpdateUri: false,
		remoteRule: 'warn',
		oldPrefixAllowedIn: ['inc/compat-aliases.php', 'inc/compat-bridges.php'],
		maxBytes: 0,
	},
	wporg: {
		forbiddenTiers: ['hub', 'updater', 'container'],
		forbiddenPaths: ['themes/', 'inc/theme-bundle.php', 'inc/compat-aliases.php', 'inc/compat-bridges.php', 'chatflows/'],
		stampTiers: 'core,customer',
		dropUpdateUri: true,
		remoteRule: 'fail',
		oldPrefixAllowedIn: [],
		maxBytes: WPORG_MAX_BYTES,
	},
};

// Forbidden in every build, whatever the exclude files say.
const ALWAYS_FORBIDDEN = [
	{ re: /^handoff\//, why: 'handoff/' },
	{ re: /^bin\//, why: 'bin/' },
	{ re: /^\.github\//, why: '.github/' },
	{ re: /(^|\/)node_modules\//, why: 'node_modules/' },
	{ re: /^vendor\//, why: 'vendor/' },
	{ re: /^dist\//, why: 'dist/' },
	{ re: /(^|\/)\.[^/]+$/, why: 'dotfile' },
	{ re: /(^|\/)\.[^/]+\//, why: 'dot-directory' },
	{ re: /\.sh$/, why: '*.sh' },
	{ re: /(^|\/)package(-lock)?\.json$/, why: 'package*.json' },
	{ re: /(^|\/)composer\.[^/]+$/, why: 'composer.*' },
	{ re: /(^|\/)phpcs\.xml[^/]*$/, why: 'phpcs.xml*' },
	{ re: /\.md$/i, why: '*.md' },
];

const CDN_HOSTS = ['cdn.jsdelivr.net', 'unpkg.com', 'cdnjs.cloudflare.com', 'ajax.googleapis.com', 'code.jquery.com', 'raw.githubusercontent.com'];
const UPLOADS_URL = 'gend.me/wp-content/uploads';
// The single consent-gated remote-asset URL table (Plan 105-04).
const UPLOADS_ALLOWED_IN = ['inc/remote-assets.php'];

const OLD_PREFIX_RES = [
	/\bfunction\s+gs_\w*/g,
	/\bfunction\s+gdc_\w*/g,
	/\bfunction\s+gci_\w*/g,
	/define\(\s*['"]GS_\w*/g,
	/\bclass\s+GS_\w*/g,
	/\bGS_VERSION\b/g,
];

const BINARY_EXT = new Set(['.png', '.jpg', '.jpeg', '.gif', '.webp', '.ico', '.woff', '.woff2', '.ttf', '.eot', '.zip', '.mp3', '.mp4', '.webm', '.pdf']);
const WALK_SKIP_TOP = new Set(['.git', 'node_modules', 'dist', 'vendor', '.cache']);

// ---------------------------------------------------------------------------
// CLI
// ---------------------------------------------------------------------------

function parseArgs(argv) {
	const o = { root: SCRIPT_ROOT, out: null, variants: Object.keys(VARIANTS), manifestJson: null, headerTransform: true, oldPrefixEnforced: OLD_PREFIX_ENFORCED, selfTest: false };
	for (let i = 0; i < argv.length; i++) {
		const a = argv[i];
		const next = () => {
			if (i + 1 >= argv.length) throw new Error(`${a} needs a value`);
			return argv[++i];
		};
		if (a === '--root') o.root = path.resolve(next());
		else if (a === '--out') o.out = path.resolve(next());
		else if (a === '--variant') {
			const v = next();
			if (!VARIANTS[v]) throw new Error(`unknown variant ${v}`);
			o.variants = [v];
		} else if (a === '--manifest-json') o.manifestJson = path.resolve(next());
		else if (a === '--no-header-transform') o.headerTransform = false;
		else if (a === '--allow-old-prefix') o.oldPrefixEnforced = false;
		else if (a === '--enforce-old-prefix') o.oldPrefixEnforced = true;
		else if (a === '--self-test') o.selfTest = true;
		else throw new Error(`unknown option ${a}`);
	}
	if (!o.out) o.out = path.join(o.root, 'dist');
	return o;
}

// ---------------------------------------------------------------------------
// Manifest (authoritative: PHP evaluates it; never regex-parse PHP here)
// ---------------------------------------------------------------------------

function loadManifest(root, manifestJson) {
	let raw;
	if (manifestJson) {
		raw = fs.readFileSync(manifestJson, 'utf8');
	} else {
		raw = runManifestDump(root);
	}
	let m;
	try {
		m = JSON.parse(raw);
	} catch (e) {
		throw new Error(`manifest-json output is not JSON: ${e.message}`);
	}
	const tiers = new Map();
	for (const e of [...(m.modules || []), ...(m.partials || [])]) {
		if (!e.file || !e.tier) throw new Error(`manifest entry without file/tier: ${JSON.stringify(e)}`);
		if (tiers.has(e.file)) throw new Error(`manifest lists ${e.file} twice`);
		tiers.set(e.file, e.tier);
	}
	if (!tiers.size) throw new Error('manifest is empty');
	return tiers;
}

function runManifestDump(root) {
	const php = spawnSync('php', ['bin/manifest-json.php'], { cwd: root, encoding: 'utf8', maxBuffer: 16 * 1024 * 1024 });
	if (!php.error && php.status === 0) return php.stdout;
	if (!php.error) {
		throw new Error(`php bin/manifest-json.php failed (exit ${php.status}):\n${php.stderr}`);
	}
	// No php on PATH: docker php:8.2-cli.
	const env = Object.assign({}, process.env);
	if (process.platform === 'win32') env.MSYS_NO_PATHCONV = '1';
	const mount = process.platform === 'win32' ? root.replace(/\\/g, '/') : root;
	const docker = spawnSync('docker', ['run', '--rm', '-v', `${mount}:/p`, '-w', '/p', 'php:8.2-cli', 'php', 'bin/manifest-json.php'], { encoding: 'utf8', env, maxBuffer: 16 * 1024 * 1024 });
	if (!docker.error && docker.status === 0) return docker.stdout;
	const why = docker.error ? docker.error.message : `exit ${docker.status}: ${docker.stderr}`;
	throw new Error(`cannot dump the manifest: no php on PATH and docker php:8.2-cli failed (${why})`);
}

// ---------------------------------------------------------------------------
// Exclude files
// ---------------------------------------------------------------------------

function globToRe(glob) {
	let re = '';
	for (let i = 0; i < glob.length; i++) {
		const c = glob[i];
		if (c === '*') {
			if (glob[i + 1] === '*') {
				re += '.*';
				i++;
			} else re += '[^/]*';
		} else if (c === '?') re += '[^/]';
		else re += c.replace(/[.+^${}()|[\]\\]/g, '\\$&');
	}
	return new RegExp(`^${re}$`);
}

function loadExclude(root, variant) {
	const file = path.join(root, 'bin', 'dist', `${variant}.exclude`);
	if (!fs.existsSync(file)) throw new Error(`missing ${path.relative(root, file)}`);
	const ex = { tiers: null, dirs: [], base: [], full: [], allowUnreferenced: new Set() };
	for (let line of fs.readFileSync(file, 'utf8').split(/\r?\n/)) {
		const allow = line.match(/^#\s*allow-unreferenced:\s*(\S+)/);
		if (allow) {
			ex.allowUnreferenced.add(allow[1]);
			continue;
		}
		line = line.trim();
		if (!line || line.startsWith('#')) continue;
		const tiers = line.match(/^@tiers\s+(.+)$/);
		if (tiers) {
			ex.tiers = tiers[1].split(',').map((s) => s.trim()).filter(Boolean);
			continue;
		}
		if (line.endsWith('/')) ex.dirs.push(line);
		else if (!line.includes('/')) ex.base.push(globToRe(line));
		else ex.full.push(globToRe(line));
	}
	if (!ex.tiers || !ex.tiers.length) throw new Error(`${variant}.exclude has no @tiers line`);
	return ex;
}

function isExcluded(rel, ex) {
	if (ex.dirs.some((d) => rel.startsWith(d))) return true;
	const base = rel.split('/').pop();
	if (ex.base.some((re) => re.test(base))) return true;
	// A dot-directory anywhere ("x/.y/z") is excluded by a ".*" basename pattern too.
	if (rel.split('/').slice(0, -1).some((seg) => ex.base.some((re) => re.test(seg) && seg.startsWith('.')))) return true;
	return ex.full.some((re) => re.test(rel));
}

// ---------------------------------------------------------------------------
// File set
// ---------------------------------------------------------------------------

function walk(root) {
	const out = [];
	const rec = (dir, rel) => {
		for (const d of fs.readdirSync(dir, { withFileTypes: true })) {
			const r = rel ? `${rel}/${d.name}` : d.name;
			if (!rel && WALK_SKIP_TOP.has(d.name)) continue;
			if (d.isDirectory()) rec(path.join(dir, d.name), r);
			else if (d.isFile()) out.push(r);
		}
	};
	rec(root, '');
	return out.sort();
}

function isText(rel) {
	return !BINARY_EXT.has(path.extname(rel).toLowerCase());
}

function selectFiles(root, all, tiers, variant, ex, fail) {
	const keep = [];
	const assets = [];
	for (const rel of all) {
		if (rel.startsWith('inc/') && rel.endsWith('.php')) {
			const tier = tiers.get(rel);
			if (!tier) {
				fail('unlisted-inc-php', rel);
				continue;
			}
			if (ex.tiers.includes(tier) && !isExcluded(rel, ex)) keep.push(rel);
			continue;
		}
		if (ALWAYS.includes(rel)) {
			keep.push(rel);
			continue;
		}
		if (rel.startsWith('assets/')) {
			if (!isExcluded(rel, ex)) assets.push(rel);
			continue;
		}
		if (!isExcluded(rel, ex)) keep.push(rel);
	}
	for (const rel of ALWAYS) {
		if (!all.includes(rel)) fail('missing-required-file', rel);
	}

	// Assets are derived: an asset ships iff a shipped PHP or JS file names its basename
	// (iterated, so a JS asset can pull in another asset).
	const read = (rel) => fs.readFileSync(path.join(root, rel), 'utf8');
	const isCode = (rel) => /\.(php|js)$/.test(rel);
	const sources = keep.filter(isCode).map(read);
	const kept = new Set();
	let changed = true;
	while (changed) {
		changed = false;
		for (const a of assets) {
			if (kept.has(a)) continue;
			const base = a.split('/').pop();
			if (sources.some((s) => s.includes(base))) {
				kept.add(a);
				if (isCode(a)) sources.push(read(a));
				changed = true;
			}
		}
	}
	// Orphans: assets that NO PHP/JS file in the source tree names (any tier) are dead
	// weight or a broken reference; they fail unless allowlisted.
	const everyCode = all.filter((r) => isCode(r) && !r.startsWith('handoff/') && !r.startsWith('bin/')).map(read);
	for (const a of assets) {
		if (kept.has(a)) continue;
		const base = a.split('/').pop();
		if (ex.allowUnreferenced.has(a)) {
			kept.add(a);
			continue;
		}
		if (!everyCode.some((s) => s.includes(base))) fail('unreferenced-asset', a);
	}
	return [...keep, ...kept].sort();
}

// ---------------------------------------------------------------------------
// Header transform (first docblock of the staged gend-society.php only)
// ---------------------------------------------------------------------------

function transformMain(src, cfg, variantName, fail) {
	const doc = src.match(/\/\*\*[\s\S]*?\*\//);
	if (!doc) {
		fail('header-transform', `${MAIN_FILE} (no plugin header docblock)`);
		return src;
	}
	let header = doc[0].replace(/^[ \t]*\*[ \t]*Network:.*\r?\n/gim, '');
	if (cfg.dropUpdateUri) header = header.replace(/^[ \t]*\*[ \t]*Update URI:.*\r?\n/gim, '');
	let out = src.slice(0, doc.index) + header + src.slice(doc.index + doc[0].length);
	const guard = out.match(/if\s*\(\s*!\s*defined\s*\(\s*['"]ABSPATH['"]\s*\)\s*\)\s*\{\s*exit\s*;\s*\}[ \t]*\r?\n/);
	if (!guard) {
		fail('header-transform', `${MAIN_FILE} (ABSPATH guard not found)`);
		return out;
	}
	const at = guard.index + guard[0].length;
	const stamp =
		`\n// Distribution build (${variantName}): only these manifest tiers ship in this zip.\n` +
		`if ( ! defined( 'GEND_SOCIETY_BUILD_TIERS' ) ) {\n` +
		`\tdefine( 'GEND_SOCIETY_BUILD_TIERS', '${cfg.stampTiers}' );\n` +
		`}\n`;
	out = out.slice(0, at) + stamp + out.slice(at);
	return out;
}

// ---------------------------------------------------------------------------
// Assertions
// ---------------------------------------------------------------------------

function assertPaths(rels, tiers, cfg, fail) {
	for (const rel of rels) {
		for (const f of ALWAYS_FORBIDDEN) {
			if (f.re.test(rel)) fail(`forbidden-path ${f.why}`, rel);
		}
		const tier = tiers.get(rel);
		if (tier && cfg.forbiddenTiers.includes(tier)) fail(`tier-${tier}`, rel);
		for (const p of cfg.forbiddenPaths) {
			if (p.endsWith('/') ? rel.startsWith(p) : rel === p) fail(`forbidden-path ${p.replace(/\/$/, '')}`, rel);
		}
	}
}

function firstDocblock(src) {
	const m = src.match(/\/\*\*[\s\S]*?\*\//);
	return m ? m[0] : '';
}

function assertContent(stageDir, rels, cfg, oldPrefixEnforced, fail, warn) {
	const oldPrefixHits = [];
	for (const rel of rels) {
		if (!isText(rel)) continue;
		const src = fs.readFileSync(path.join(stageDir, rel), 'utf8');
		if (rel === MAIN_FILE && /^[ \t]*\*?[ \t]*Network:/im.test(firstDocblock(src))) fail('network-header', rel);
		if (cfg.dropUpdateUri && /Update URI/i.test(src)) fail('update-uri', rel);
		const remote = cfg.remoteRule === 'fail' ? fail : warn;
		for (const host of CDN_HOSTS) {
			if (src.includes(host)) remote(`cdn-host ${host}`, rel);
		}
		if (src.includes(UPLOADS_URL) && !UPLOADS_ALLOWED_IN.includes(rel)) remote('gend-uploads-url', rel);
		if (rel.endsWith('.php') && !cfg.oldPrefixAllowedIn.includes(rel)) {
			let n = 0;
			for (const re of OLD_PREFIX_RES) n += (src.match(re) || []).length;
			if (n) oldPrefixHits.push([rel, n]);
		}
	}
	for (const [rel, n] of oldPrefixHits) {
		(oldPrefixEnforced ? fail : warn)('old-prefix', `${rel} (${n})`);
	}
	return oldPrefixHits.reduce((s, [, n]) => s + n, 0);
}

// ---------------------------------------------------------------------------
// Build one variant
// ---------------------------------------------------------------------------

function rmrf(p) {
	fs.rmSync(p, { recursive: true, force: true });
}

async function buildVariant(name, opts, tiers, all) {
	const cfg = VARIANTS[name];
	const violations = [];
	const warnings = [];
	const fail = (rule, rel) => violations.push(`BUILD FAIL ${name}: ${rule}: ${rel}`);
	const warn = (rule, rel) => warnings.push(`BUILD WARN ${name}: ${rule}: ${rel}`);

	const ex = loadExclude(opts.root, name);
	const rels = selectFiles(opts.root, all, tiers, name, ex, fail);

	const variantDir = path.join(opts.out, name);
	const stageDir = path.join(variantDir, SLUG);
	const zipPath = path.join(variantDir, `${SLUG}.zip`);
	rmrf(variantDir);
	fs.mkdirSync(stageDir, { recursive: true });
	for (const rel of rels) {
		const dst = path.join(stageDir, rel);
		fs.mkdirSync(path.dirname(dst), { recursive: true });
		if (rel === MAIN_FILE && opts.headerTransform) {
			fs.writeFileSync(dst, transformMain(fs.readFileSync(path.join(opts.root, rel), 'utf8'), cfg, name, fail));
		} else {
			fs.copyFileSync(path.join(opts.root, rel), dst);
		}
	}

	// Assertions on the staged directory.
	assertPaths(rels, tiers, cfg, fail);
	const oldPrefix = assertContent(stageDir, rels, cfg, opts.oldPrefixEnforced, fail, warn);

	// Zip, then the same path assertions on the archiver entry list.
	const entries = await writeZip(stageDir, rels, zipPath);
	const entryRels = [];
	for (const e of entries) {
		if (!e.startsWith(`${SLUG}/`) || e.includes('\\')) fail('zip-entry-root', e);
		entryRels.push(e.replace(new RegExp(`^${SLUG}/`), ''));
	}
	assertPaths(entryRels, tiers, cfg, fail);
	const staged = new Set(rels);
	for (const r of entryRels) if (!staged.has(r)) fail('zip-entry-not-staged', r);
	if (entryRels.length !== rels.length) fail('zip-entry-count', `${entryRels.length} entries vs ${rels.length} staged files`);

	const bytes = fs.statSync(zipPath).size;
	if (cfg.maxBytes && bytes > cfg.maxBytes) fail('zip-size', `${(bytes / 1048576).toFixed(2)} MB > ${(cfg.maxBytes / 1048576).toFixed(0)} MB`);

	const sha = crypto.createHash('sha256').update(fs.readFileSync(zipPath)).digest('hex');
	if (violations.length) {
		// Never leave a zip that failed its assertions lying around; keep the staged dir to inspect.
		rmrf(zipPath);
	} else {
		fs.writeFileSync(`${zipPath}.sha256`, `${sha}  ${SLUG}.zip\n`);
	}
	return { name, files: rels.length, bytes, sha, violations, warnings, oldPrefix, zipPath, stageDir };
}

function writeZip(stageDir, rels, zipPath) {
	const archiver = require('archiver');
	return new Promise((resolve, reject) => {
		const entries = [];
		const output = fs.createWriteStream(zipPath);
		const archive = archiver('zip', { zlib: { level: 9 } });
		archive.on('entry', (e) => entries.push(e.name));
		archive.on('warning', reject);
		archive.on('error', reject);
		output.on('close', () => resolve(entries));
		archive.pipe(output);
		for (const rel of rels) {
			archive.file(path.join(stageDir, rel), { name: `${SLUG}/${rel}`, date: new Date('2026-01-01T00:00:00Z') });
		}
		archive.finalize();
	});
}

async function build(opts) {
	const tiers = loadManifest(opts.root, opts.manifestJson);
	const all = walk(opts.root);
	const results = [];
	for (const name of opts.variants) results.push(await buildVariant(name, opts, tiers, all));
	let failed = false;
	for (const r of results) {
		for (const w of r.warnings) console.log(w);
		for (const v of r.violations) console.log(v);
		const mb = (r.bytes / 1048576).toFixed(2);
		const status = r.violations.length ? `FAILED (${r.violations.length} violations)` : 'OK';
		console.log(`${r.name}: ${status} -- ${r.files} files, zip ${mb} MB (${r.bytes} bytes), old-prefix hits ${r.oldPrefix}${r.violations.length ? '' : `, sha256 ${r.sha}`}`);
		if (r.violations.length) failed = true;
	}
	return failed ? 1 : 0;
}

// ---------------------------------------------------------------------------
// Self-test: every negative fixture must fail with its rule; the clean copy must not
// ---------------------------------------------------------------------------

function copyTree(src, dst) {
	fs.cpSync(src, dst, {
		recursive: true,
		filter: (s) => {
			const rel = path.relative(src, s).split(path.sep).join('/');
			return !rel || !WALK_SKIP_TOP.has(rel.split('/')[0]);
		},
	});
}

function editExclude(root, variant, edit) {
	const file = path.join(root, 'bin', 'dist', `${variant}.exclude`);
	let lines = fs.readFileSync(file, 'utf8').split(/\r?\n/);
	if (edit.remove) lines = lines.filter((l) => !edit.remove.includes(l.trim()));
	if (edit.add) lines = lines.concat(edit.add);
	if (edit.tiers) lines = lines.map((l) => (/^@tiers\s/.test(l.trim()) ? `@tiers ${edit.tiers}` : l));
	fs.writeFileSync(file, lines.join('\n'));
}

function applyFixture(root, fx) {
	for (const [rel, content] of Object.entries(fx.write || {})) {
		const p = path.join(root, rel);
		fs.mkdirSync(path.dirname(p), { recursive: true });
		fs.writeFileSync(p, content);
	}
	for (const [rel, bytes] of Object.entries(fx.random || {})) {
		const p = path.join(root, rel);
		fs.mkdirSync(path.dirname(p), { recursive: true });
		fs.writeFileSync(p, crypto.randomBytes(bytes));
	}
	for (const [rel, content] of Object.entries(fx.append || {})) {
		fs.appendFileSync(path.join(root, rel), content);
	}
	for (const [rel, pair] of Object.entries(fx.replace || {})) {
		const p = path.join(root, rel);
		const src = fs.readFileSync(p, 'utf8');
		if (!src.includes(pair[0])) throw new Error(`fixture ${fx.name}: "${pair[0]}" not found in ${rel}`);
		fs.writeFileSync(p, src.replace(pair[0], pair[1]));
	}
	for (const [variant, edit] of Object.entries(fx.exclude || {})) editExclude(root, variant, edit);
}

async function selfTest() {
	const fixturesDir = path.join(SCRIPT_ROOT, 'bin', 'dist', 'fixtures');
	const fixtures = fs
		.readdirSync(fixturesDir)
		.filter((f) => f.endsWith('.json'))
		.sort()
		.map((f) => Object.assign({ file: f }, JSON.parse(fs.readFileSync(path.join(fixturesDir, f), 'utf8'))));
	const tmpBase = fs.mkdtempSync(path.join(os.tmpdir(), 'gs-dist-selftest-'));
	const manifestJson = path.join(tmpBase, 'manifest.json');
	fs.writeFileSync(manifestJson, runManifestDump(SCRIPT_ROOT));

	let pass = 0;
	const lines = [];
	for (const fx of fixtures) {
		const root = path.join(tmpBase, fx.file.replace(/\.json$/, ''));
		copyTree(SCRIPT_ROOT, root);
		applyFixture(root, fx);
		const args = [__filename, '--root', root, '--manifest-json', manifestJson].concat(fx.flags || []);
		const r = spawnSync(process.execPath, args, { encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 });
		const out = `${r.stdout || ''}${r.stderr || ''}`;
		const fails = out.split(/\r?\n/).filter((l) => l.startsWith('BUILD FAIL '));
		let ok;
		let why;
		if (fx.expect === 'clean') {
			// Clean copy: exit 0, or (until Plans 105-04/05/11 clear them) only the recorded
			// baseline violations of today's main. The real build never tolerates these.
			const known = (fx.knownBaseline || []).map((k) => new RegExp(k));
			const unexpected = fails.filter((l) => !known.some((re) => re.test(l)));
			ok = !r.error && (r.status === 0 || (r.status === 1 && fails.length > 0 && unexpected.length === 0));
			why = r.status === 0 ? 'exit 0' : `exit ${r.status}, ${fails.length} known-baseline violations, ${unexpected.length} unexpected${unexpected.length ? `: ${unexpected.slice(0, 5).join(' | ')}` : ''}`;
			if (r.status !== 0 && !fails.length) why += `\n${out.slice(-2000)}`;
		} else {
			const missing = fx.expect.filter((e) => !fails.some((l) => l.startsWith(`BUILD FAIL ${e.variant}: ${e.rule}:`)));
			ok = r.status !== 0 && missing.length === 0;
			why = `exit ${r.status}; ${missing.length ? `missing ${missing.map((e) => `${e.variant}:${e.rule}`).join(', ')}` : `got ${fx.expect.map((e) => `${e.variant}:${e.rule}`).join(', ')}`}`;
			if (!ok && !fails.length) why += `\n${out.slice(-2000)}`;
		}
		if (ok) pass++;
		lines.push(`${ok ? 'ok  ' : 'FAIL'} ${fx.file}: ${fx.name} -- ${why}`);
		console.log(lines[lines.length - 1]);
		rmrf(root);
	}
	rmrf(tmpBase);
	const total = fixtures.length;
	if (pass === total && total > 0) {
		console.log(`SELF-TEST PASS (${pass}/${total})`);
		return 0;
	}
	console.log(`SELF-TEST FAIL (${pass}/${total})`);
	return 1;
}

// ---------------------------------------------------------------------------

(async () => {
	let opts;
	try {
		opts = parseArgs(process.argv.slice(2));
	} catch (e) {
		console.error(`build-dist: ${e.message}`);
		process.exit(2);
	}
	try {
		process.exit(opts.selfTest ? await selfTest() : await build(opts));
	} catch (e) {
		console.error(`BUILD FAIL: ${e.message}`);
		process.exit(1);
	}
})();
