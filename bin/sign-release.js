#!/usr/bin/env node
/**
 * Sign a GenD Society release and write the updates.gend.me feed files.
 *
 *   node bin/sign-release.js --zip dist/full/gend-society.zip --out dist/feed
 *        [--key-id gs-2026a] [--notes-html <file>] [--description-html <file>]
 *        [--released-at <ISO 8601>] [--root <plugin root>]
 *   node bin/sign-release.js --self-test
 *
 * The Ed25519 private key (PKCS8 PEM) comes from the environment variable
 * GEND_UPDATE_SIGNING_KEY. It is never printed or written anywhere.
 *
 * Steps:
 *   1. Read the version from GEND_SOCIETY_VERSION in gend-society.php and require the
 *      plugin header "Version:" to match it.
 *   2. sha256 of the zip (lowercase hex).
 *   3. Sign "gend-society|<version>|<sha256>", the exact message inc/self-update.php checks.
 *   4. Derive the public key from the private key and compare it with the key pinned for
 *      --key-id in gend_society_update_public_keys() (inc/self-update.php). A mismatch exits
 *      1 BEFORE any file is written, so a wrong secret can never publish a release that
 *      installed sites would refuse.
 *   5. Verify the signature, then write into --out:
 *        gend-society-<ver>.zip          copy of the input zip
 *        gend-society-<ver>.zip.sha256   "<sha256>  gend-society-<ver>.zip"
 *        gend-society-<ver>.zip.sig      base64 signature
 *        gend-society.json               the update manifest read by inc/self-update.php
 *
 * No dependencies beyond Node (>= 20).
 */
'use strict';

const fs = require('fs');
const os = require('os');
const path = require('path');
const crypto = require('crypto');
const { spawnSync } = require('child_process');

const SLUG = 'gend-society';
const PREFIX = 'https://updates.gend.me/gend-society/';
const SCRIPT_ROOT = path.resolve(__dirname, '..');
const KEY_ENV = 'GEND_UPDATE_SIGNING_KEY';
const PINNED_RE = /'(gs-[0-9a-z]+)'\s*=>\s*'([A-Za-z0-9+/]{43}=)'/g;

class SignError extends Error {}

function parseArgs(argv) {
	const o = { zip: null, out: null, keyId: 'gs-2026a', notesHtml: null, descriptionHtml: null, releasedAt: null, root: SCRIPT_ROOT, selfTest: false };
	for (let i = 0; i < argv.length; i++) {
		const a = argv[i];
		const next = () => {
			if (i + 1 >= argv.length) throw new SignError(`${a} needs a value`);
			return argv[++i];
		};
		if (a === '--zip') o.zip = path.resolve(next());
		else if (a === '--out') o.out = path.resolve(next());
		else if (a === '--key-id') o.keyId = next();
		else if (a === '--notes-html') o.notesHtml = path.resolve(next());
		else if (a === '--description-html') o.descriptionHtml = path.resolve(next());
		else if (a === '--released-at') o.releasedAt = next();
		else if (a === '--root') o.root = path.resolve(next());
		else if (a === '--self-test') o.selfTest = true;
		else throw new SignError(`unknown option ${a}`);
	}
	if (!o.selfTest) {
		if (!o.zip) throw new SignError('--zip is required');
		if (!o.out) throw new SignError('--out is required');
		if (!/^gs-[0-9a-z]+$/.test(o.keyId)) throw new SignError(`bad --key-id ${o.keyId}`);
	}
	return o;
}

// ---------------------------------------------------------------------------
// Plugin metadata
// ---------------------------------------------------------------------------

function firstDocblock(src) {
	const m = src.match(/\/\*\*[\s\S]*?\*\//);
	return m ? m[0] : '';
}

function headerField(doc, name) {
	const re = new RegExp(`^[ \\t]*\\*[ \\t]*${name.replace(/ /g, '[ \\t]+')}:[ \\t]*(.+?)[ \\t]*$`, 'im');
	const m = doc.match(re);
	return m ? m[1] : '';
}

function readPluginMeta(root) {
	const mainFile = path.join(root, `${SLUG}.php`);
	if (!fs.existsSync(mainFile)) throw new SignError(`missing ${mainFile}`);
	const src = fs.readFileSync(mainFile, 'utf8');
	const def = src.match(/define\(\s*['"]GEND_SOCIETY_VERSION['"]\s*,\s*['"]([^'"]+)['"]\s*\)/);
	if (!def) throw new SignError('GEND_SOCIETY_VERSION define not found in gend-society.php');
	const version = def[1];
	if (!/^\d+\.\d+\.\d+$/.test(version)) throw new SignError(`GEND_SOCIETY_VERSION ${version} is not x.y.z (inc/self-update.php only accepts x.y.z)`);
	const doc = firstDocblock(src);
	const headerVersion = headerField(doc, 'Version');
	if (headerVersion !== version) throw new SignError(`plugin header Version "${headerVersion}" does not match GEND_SOCIETY_VERSION "${version}"`);
	let tested = '';
	const readme = path.join(root, 'readme.txt');
	if (fs.existsSync(readme)) {
		const m = fs.readFileSync(readme, 'utf8').match(/^Tested up to:[ \t]*(\S+)/im);
		if (m) tested = m[1];
	}
	return {
		version,
		requires: headerField(doc, 'Requires at least'),
		requiresPhp: headerField(doc, 'Requires PHP'),
		description: headerField(doc, 'Description'),
		tested,
	};
}

function readPinnedKeys(root) {
	const file = path.join(root, 'inc', 'self-update.php');
	if (!fs.existsSync(file)) throw new SignError(`missing ${file}`);
	const src = fs.readFileSync(file, 'utf8');
	const at = src.indexOf('function gend_society_update_public_keys');
	if (at < 0) throw new SignError('gend_society_update_public_keys() not found in inc/self-update.php');
	const end = src.indexOf('}', src.indexOf('array(', at));
	const body = src.slice(at, end < 0 ? undefined : end);
	const keys = new Map();
	for (const m of body.matchAll(PINNED_RE)) {
		if (keys.has(m[1])) throw new SignError(`key id ${m[1]} is pinned twice`);
		keys.set(m[1], m[2]);
	}
	if (!keys.size) throw new SignError('no pinned keys parsed from gend_society_update_public_keys()');
	return keys;
}

// ---------------------------------------------------------------------------
// Signing
// ---------------------------------------------------------------------------

function escHtml(s) {
	return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

function readHtml(file, fallback) {
	if (file && fs.existsSync(file)) {
		const html = fs.readFileSync(file, 'utf8').trim();
		if (html) return html;
	}
	return fallback;
}

function formatTime(iso) {
	const d = iso ? new Date(iso) : new Date();
	if (Number.isNaN(d.getTime())) throw new SignError(`bad --released-at ${iso}`);
	return d.toISOString().replace('T', ' ').replace(/\.\d+Z$/, '');
}

function sign(opts, env) {
	// Everything that can fail runs before the first write.
	const meta = readPluginMeta(opts.root);
	const pinned = readPinnedKeys(opts.root);
	if (!pinned.has(opts.keyId)) throw new SignError(`key id ${opts.keyId} is not pinned in inc/self-update.php (pinned: ${[...pinned.keys()].join(', ')})`);
	if (!fs.existsSync(opts.zip)) throw new SignError(`zip not found: ${opts.zip}`);

	const pem = env[KEY_ENV];
	if (!pem || !pem.trim()) throw new SignError(`${KEY_ENV} is not set`);
	let key;
	try {
		key = crypto.createPrivateKey(pem);
	} catch (e) {
		throw new SignError(`${KEY_ENV} is not a readable private key`);
	}
	if (key.asymmetricKeyType !== 'ed25519') throw new SignError(`${KEY_ENV} is not an Ed25519 key`);
	const pubKey = crypto.createPublicKey(key);
	const pub = Buffer.from(pubKey.export({ format: 'jwk' }).x, 'base64url').toString('base64');
	if (pub !== pinned.get(opts.keyId)) {
		throw new SignError(`the signing key does not match the public key pinned for ${opts.keyId} in inc/self-update.php; nothing written`);
	}

	const zipBytes = fs.readFileSync(opts.zip);
	const sha = crypto.createHash('sha256').update(zipBytes).digest('hex');
	const message = Buffer.from(`${SLUG}|${meta.version}|${sha}`);
	const sigBuf = crypto.sign(null, message, key);
	const sig = sigBuf.toString('base64');
	const pinnedPub = crypto.createPublicKey({ key: { kty: 'OKP', crv: 'Ed25519', x: Buffer.from(pinned.get(opts.keyId), 'base64').toString('base64url') }, format: 'jwk' });
	if (!crypto.verify(null, message, pinnedPub, sigBuf)) throw new SignError('self-verify against the pinned key failed; nothing written');

	const zipName = `${SLUG}-${meta.version}.zip`;
	const manifest = {
		slug: SLUG,
		name: 'GenD Society',
		version: meta.version,
		requires: meta.requires,
		tested: meta.tested,
		requires_php: meta.requiresPhp,
		last_updated: formatTime(opts.releasedAt),
		homepage: 'https://gend.me',
		package: `${PREFIX}${zipName}`,
		sha256: sha,
		signature: sig,
		key_id: opts.keyId,
		sections: {
			description: readHtml(opts.descriptionHtml, `<p>${escHtml(meta.description || 'GenD Society')} (version ${escHtml(meta.version)})</p>`),
			changelog: readHtml(opts.notesHtml, `<p>GenD Society ${escHtml(meta.version)}.</p>`),
		},
	};

	fs.mkdirSync(opts.out, { recursive: true });
	fs.writeFileSync(path.join(opts.out, zipName), zipBytes);
	fs.writeFileSync(path.join(opts.out, `${zipName}.sha256`), `${sha}  ${zipName}\n`);
	fs.writeFileSync(path.join(opts.out, `${zipName}.sig`), `${sig}\n`);
	fs.writeFileSync(path.join(opts.out, `${SLUG}.json`), `${JSON.stringify(manifest, null, 2)}\n`);
	console.log(`signed ${zipName} with ${opts.keyId}: sha256 ${sha}`);
	console.log(`wrote ${path.join(opts.out, `${SLUG}.json`)} (package ${manifest.package})`);
	return 0;
}

// ---------------------------------------------------------------------------
// Self-test
// ---------------------------------------------------------------------------

function ephemeralKey() {
	const { privateKey, publicKey } = crypto.generateKeyPairSync('ed25519');
	return {
		pem: privateKey.export({ type: 'pkcs8', format: 'pem' }),
		pub: Buffer.from(publicKey.export({ format: 'jwk' }).x, 'base64url').toString('base64'),
		publicKey,
	};
}

function writeFakeRoot(dir, { version, headerVersion, keys }) {
	fs.mkdirSync(path.join(dir, 'inc'), { recursive: true });
	fs.writeFileSync(
		path.join(dir, `${SLUG}.php`),
		`<?php\n/**\n * Plugin Name: GenD Society\n * Description: Self-test plugin.\n * Version:     ${headerVersion || version}\n * Update URI:  ${PREFIX}\n * Requires at least: 6.7\n * Requires PHP: 8.1\n */\n\nif (!defined('ABSPATH')) {\n    exit;\n}\n\ndefine('GEND_SOCIETY_VERSION', '${version}');\n`
	);
	fs.writeFileSync(path.join(dir, 'readme.txt'), `=== GenD Society ===\nRequires at least: 6.7\nTested up to: 7.1\nRequires PHP: 8.1\nStable tag: ${version}\n`);
	const lines = Object.entries(keys).map(([id, pub]) => `\t\t\t'${id}' => '${pub}',`).join('\n');
	fs.writeFileSync(
		path.join(dir, 'inc', 'self-update.php'),
		`<?php\nif ( ! function_exists( 'gend_society_update_public_keys' ) ) {\n\tfunction gend_society_update_public_keys() {\n\t\treturn array(\n${lines}\n\t\t);\n\t}\n}\n`
	);
}

function emptyZip() {
	// End-of-central-directory record only: a valid, empty zip archive.
	const b = Buffer.alloc(22);
	b.writeUInt32LE(0x06054b50, 0);
	return b;
}

function selfTest() {
	const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'gs-sign-selftest-'));
	const A = ephemeralKey();
	const B = ephemeralKey();
	let pass = 0;
	let total = 0;
	const check = (name, ok, detail) => {
		total++;
		if (ok) pass++;
		console.log(`${ok ? 'ok  ' : 'FAIL'} ${name}${detail ? ` -- ${detail}` : ''}`);
	};
	const run = (root, out, keyPem, extra) => {
		const args = [__filename, '--root', root, '--zip', path.join(tmp, 'in.zip'), '--out', out].concat(extra || []);
		const env = Object.assign({}, process.env);
		delete env[KEY_ENV];
		if (keyPem) env[KEY_ENV] = keyPem;
		return spawnSync(process.execPath, args, { encoding: 'utf8', env });
	};
	const files = (dir) => (fs.existsSync(dir) ? fs.readdirSync(dir).sort() : []);
	try {
		const zip = Buffer.concat([emptyZip()]);
		fs.writeFileSync(path.join(tmp, 'in.zip'), zip);
		const zipSha = crypto.createHash('sha256').update(zip).digest('hex');

		// 1. Happy path (active key id).
		const good = path.join(tmp, 'good');
		writeFakeRoot(good, { version: '9.8.7', keys: { 'gs-2026a': A.pub, 'gs-2026b': B.pub } });
		fs.writeFileSync(path.join(tmp, 'notes.html'), '<h4>9.8.7</h4><ul><li>Self-test note</li></ul>\n');
		let out = path.join(tmp, 'out-good');
		let r = run(good, out, A.pem, ['--key-id', 'gs-2026a', '--notes-html', path.join(tmp, 'notes.html'), '--released-at', '2026-10-12T12:00:00Z']);
		const expected = ['gend-society-9.8.7.zip', 'gend-society-9.8.7.zip.sha256', 'gend-society-9.8.7.zip.sig', 'gend-society.json'];
		check('happy path exits 0 and writes the 4 files', r.status === 0 && JSON.stringify(files(out)) === JSON.stringify(expected), `exit ${r.status}, files ${files(out).join(',')}${r.status ? ` ${r.stderr.trim()}` : ''}`);
		if (r.status === 0) {
			const m = JSON.parse(fs.readFileSync(path.join(out, 'gend-society.json'), 'utf8'));
			const fieldsOk =
				m.slug === SLUG && m.name === 'GenD Society' && m.version === '9.8.7' && m.requires === '6.7' && m.tested === '7.1' && m.requires_php === '8.1' &&
				m.homepage === 'https://gend.me' && m.package === `${PREFIX}gend-society-9.8.7.zip` && m.sha256 === zipSha && m.key_id === 'gs-2026a' &&
				m.last_updated === '2026-10-12 12:00:00' && m.sections && m.sections.changelog.includes('Self-test note') && /^<p>/.test(m.sections.description);
			check('manifest fields', fieldsOk, JSON.stringify(Object.assign({}, m, { signature: '<sig>' })).slice(0, 400));
			const msg = Buffer.from(`gend-society|9.8.7|${zipSha}`);
			check('signature verifies with crypto.verify (pinned public key)', crypto.verify(null, msg, A.publicKey, Buffer.from(m.signature, 'base64')));
			check('signature fails for a different version', !crypto.verify(null, Buffer.from(`gend-society|9.8.8|${zipSha}`), A.publicKey, Buffer.from(m.signature, 'base64')));
			check('.sig file equals the manifest signature', fs.readFileSync(path.join(out, 'gend-society-9.8.7.zip.sig'), 'utf8') === `${m.signature}\n`);
			check('.sha256 file format', fs.readFileSync(path.join(out, 'gend-society-9.8.7.zip.sha256'), 'utf8') === `${zipSha}  gend-society-9.8.7.zip\n`);
			check('zip copy is byte-identical', Buffer.compare(fs.readFileSync(path.join(out, 'gend-society-9.8.7.zip')), zip) === 0);
			check('private key never printed', !`${r.stdout}${r.stderr}`.includes('PRIVATE KEY') && !`${r.stdout}${r.stderr}`.includes(A.pem.split('\n')[1]));
		}

		// 2. Wrong private key for the key id: refuses, writes nothing.
		out = path.join(tmp, 'out-wrongkey');
		r = run(good, out, B.pem, ['--key-id', 'gs-2026a']);
		check('wrong private key -> non-zero exit, no files', r.status !== 0 && files(out).length === 0, `exit ${r.status}, files ${files(out).length}, ${r.stderr.trim()}`);

		// 3. Backup key id with its own key works.
		out = path.join(tmp, 'out-backup');
		r = run(good, out, B.pem, ['--key-id', 'gs-2026b']);
		check('backup key id gs-2026b signs with its key', r.status === 0 && files(out).length === 4 && JSON.parse(fs.readFileSync(path.join(out, 'gend-society.json'), 'utf8')).key_id === 'gs-2026b', `exit ${r.status}`);

		// 4. Header/define version mismatch.
		const mism = path.join(tmp, 'mismatch');
		writeFakeRoot(mism, { version: '9.8.7', headerVersion: '9.8.6', keys: { 'gs-2026a': A.pub } });
		out = path.join(tmp, 'out-mismatch');
		r = run(mism, out, A.pem, []);
		check('header/define version mismatch -> fails, no files', r.status !== 0 && files(out).length === 0 && /does not match/.test(r.stderr), `exit ${r.status}, ${r.stderr.trim()}`);

		// 5. Unpinned key id.
		out = path.join(tmp, 'out-unpinned');
		r = run(good, out, A.pem, ['--key-id', 'gs-2099z']);
		check('unpinned key id -> fails, no files', r.status !== 0 && files(out).length === 0, `exit ${r.status}`);

		// 6. Missing key env.
		out = path.join(tmp, 'out-nokey');
		r = run(good, out, null, []);
		check('missing GEND_UPDATE_SIGNING_KEY -> fails, no files', r.status !== 0 && files(out).length === 0, `exit ${r.status}`);

		// 7. Non-Ed25519 key.
		const rsa = crypto.generateKeyPairSync('rsa', { modulusLength: 2048 }).privateKey.export({ type: 'pkcs8', format: 'pem' });
		out = path.join(tmp, 'out-rsa');
		r = run(good, out, rsa, []);
		check('non-Ed25519 key -> fails, no files', r.status !== 0 && files(out).length === 0, `exit ${r.status}`);

		// 8. The real repo's pinned layout parses (two keys, 44-char base64, 32 bytes).
		const real = readPinnedKeys(SCRIPT_ROOT);
		const realOk = real.size >= 2 && [...real.values()].every((v) => Buffer.from(v, 'base64').length === 32);
		check('repo inc/self-update.php pinned keys parse', realOk, [...real.keys()].join(', '));
	} finally {
		fs.rmSync(tmp, { recursive: true, force: true });
	}
	if (pass === total) {
		console.log(`SIGN SELF-TEST: PASS (${pass}/${total})`);
		return 0;
	}
	console.log(`SIGN SELF-TEST: FAIL (${pass}/${total})`);
	return 1;
}

// ---------------------------------------------------------------------------

if (require.main === module) {
	let code;
	try {
		const opts = parseArgs(process.argv.slice(2));
		code = opts.selfTest ? selfTest() : sign(opts, process.env);
	} catch (e) {
		console.error(`sign-release: ${e instanceof SignError ? e.message : `unexpected error: ${e.message}`}`);
		code = 1;
	}
	process.exit(code);
}

module.exports = { readPinnedKeys, readPluginMeta };
