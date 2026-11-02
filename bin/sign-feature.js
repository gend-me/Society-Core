#!/usr/bin/env node
/**
 * Sign one dashboard plugin package for the private feature bucket (Phase 114).
 *
 *   node bin/sign-feature.js --slug blog --plugin blog-manager/blog-manager.php --version 1.4.2 \
 *        --zip dist/blog-manager.zip --out dist/features [--key-id gs-2026a] [--root <plugin root>]
 *   node bin/sign-feature.js --self-test
 *
 * The Ed25519 private key (PKCS8 PEM) comes from GEND_UPDATE_SIGNING_KEY (the same Actions secret the release workflow uses)
 * and is never printed or written. The message signed is `gend-society-feature|<slug>|<version>|<sha256>`, exactly what
 * inc/feature-installer.php checks, so a signature for one plugin or version can never vouch for another.
 *
 * Before anything is written the public key derived from the secret is compared with the key pinned for --key-id in
 * inc/self-update.php: a wrong secret exits 1 and publishes nothing a site would refuse.
 *
 * Writes into --out/features/<slug>/:
 *   <slug>-<version>.zip         the package (copy)
 *   <slug>-<version>.zip.sha256  "<sha256>  <file>"
 *   latest.json                  {slug, plugin, version, sha256, signature, key_id, size, object}
 *
 * No dependencies beyond Node (>= 20).
 */
'use strict';

const fs = require('fs');
const os = require('os');
const path = require('path');
const crypto = require('crypto');

const SCRIPT_ROOT = path.resolve(__dirname, '..');
const KEY_ENV = 'GEND_UPDATE_SIGNING_KEY';
const PINNED_RE = /'(gs-[0-9a-z]+)'\s*=>\s*'([A-Za-z0-9+/]{43}=)'/g;
const SLUGS = ['blog', 'email', 'store', 'sales', 'projects', 'social', 'membership', 'rewards'];

class SignError extends Error {}

function parseArgs(argv) {
	const o = { slug: null, plugin: null, version: null, zip: null, out: null, keyId: 'gs-2026a', root: SCRIPT_ROOT, selfTest: false };
	for (let i = 0; i < argv.length; i++) {
		const a = argv[i];
		const next = () => {
			if (i + 1 >= argv.length) throw new SignError(`${a} needs a value`);
			return argv[++i];
		};
		if (a === '--slug') o.slug = next();
		else if (a === '--plugin') o.plugin = next();
		else if (a === '--version') o.version = next();
		else if (a === '--zip') o.zip = path.resolve(next());
		else if (a === '--out') o.out = path.resolve(next());
		else if (a === '--key-id') o.keyId = next();
		else if (a === '--root') o.root = path.resolve(next());
		else if (a === '--self-test') o.selfTest = true;
		else throw new SignError(`unknown argument ${a}`);
	}
	return o;
}

function pinnedKeys(root) {
	const src = fs.readFileSync(path.join(root, 'inc', 'self-update.php'), 'utf8');
	const at = src.indexOf('function gend_society_update_public_keys');
	if (at < 0) throw new SignError('pinned key function not found in inc/self-update.php');
	const body = src.slice(at, at + 1200);
	const m = new Map();
	for (const x of body.matchAll(PINNED_RE)) m.set(x[1], x[2]);
	if (!m.size) throw new SignError('no pinned keys found');
	return m;
}

function sign(o, env) {
	if (!SLUGS.includes(o.slug)) throw new SignError(`slug must be one of ${SLUGS.join(', ')}`);
	if (!/^[a-z0-9-]+\/[a-z0-9-]+\.php$/.test(o.plugin || '')) throw new SignError('--plugin must look like folder/file.php');
	if (!/^\d+\.\d+\.\d+$/.test(o.version || '')) throw new SignError('--version must be X.Y.Z');
	if (!o.zip || !fs.existsSync(o.zip)) throw new SignError('--zip not found');
	if (!o.out) throw new SignError('--out is required');
	const pem = env[KEY_ENV];
	if (!pem) throw new SignError(`${KEY_ENV} is not set`);
	let key;
	try { key = crypto.createPrivateKey(pem); } catch (e) { throw new SignError('the signing key is not a valid PEM'); }
	if (key.asymmetricKeyType !== 'ed25519') throw new SignError('the signing key is not Ed25519');
	const pinned = pinnedKeys(o.root);
	if (!pinned.has(o.keyId)) throw new SignError(`key id ${o.keyId} is not pinned`);
	const pub = crypto.createPublicKey(key);
	const derived = Buffer.from(pub.export({ format: 'jwk' }).x, 'base64url').toString('base64');
	if (derived !== pinned.get(o.keyId)) throw new SignError('the secret does not match the pinned key; nothing written');

	const bytes = fs.readFileSync(o.zip);
	const sha = crypto.createHash('sha256').update(bytes).digest('hex');
	const message = Buffer.from(`gend-society-feature|${o.slug}|${o.version}|${sha}`);
	const sig = crypto.sign(null, message, key);
	if (!crypto.verify(null, message, pub, sig)) throw new SignError('self-verify failed; nothing written');

	const dir = path.join(o.out, 'features', o.slug);
	fs.mkdirSync(dir, { recursive: true });
	const name = `${o.slug}-${o.version}.zip`;
	fs.writeFileSync(path.join(dir, name), bytes);
	fs.writeFileSync(path.join(dir, `${name}.sha256`), `${sha}  ${name}\n`);
	const manifest = { slug: o.slug, plugin: o.plugin, version: o.version, sha256: sha, signature: sig.toString('base64'), key_id: o.keyId, size: bytes.length, object: `features/${o.slug}/${name}` };
	fs.writeFileSync(path.join(dir, 'latest.json'), JSON.stringify(manifest, null, 2) + '\n');
	return manifest;
}

function selfTest() {
	const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'sign-feature-'));
	let failed = 0;
	const check = (name, ok) => { console.log((ok ? 'PASS ' : 'FAIL ') + name); if (!ok) failed++; };
	try {
		const kp = crypto.generateKeyPairSync('ed25519');
		const pem = kp.privateKey.export({ type: 'pkcs8', format: 'pem' });
		const x = Buffer.from(kp.publicKey.export({ format: 'jwk' }).x, 'base64url').toString('base64');
		const root = path.join(tmp, 'root'); fs.mkdirSync(path.join(root, 'inc'), { recursive: true });
		fs.writeFileSync(path.join(root, 'inc', 'self-update.php'), `<?php\nfunction gend_society_update_public_keys() {\n\treturn array(\n\t\t'gs-2026a' => '${x}',\n\t);\n}\n`);
		const zip = path.join(tmp, 'p.zip'); fs.writeFileSync(zip, Buffer.from('PK-not-really-a-zip-but-bytes'));
		const base = { slug: 'blog', plugin: 'blog-manager/blog-manager.php', version: '1.2.3', zip, out: path.join(tmp, 'out'), keyId: 'gs-2026a', root };
		const m = sign(base, { [KEY_ENV]: pem });
		const sha = crypto.createHash('sha256').update(fs.readFileSync(zip)).digest('hex');
		check('manifest has the package hash', m.sha256 === sha);
		check('signature verifies for slug|version|sha', crypto.verify(null, Buffer.from(`gend-society-feature|blog|1.2.3|${sha}`), kp.publicKey, Buffer.from(m.signature, 'base64')));
		check('signature fails for another feature', !crypto.verify(null, Buffer.from(`gend-society-feature|store|1.2.3|${sha}`), kp.publicKey, Buffer.from(m.signature, 'base64')));
		check('signature fails for the release message', !crypto.verify(null, Buffer.from(`gend-society|1.2.3|${sha}`), kp.publicKey, Buffer.from(m.signature, 'base64')));
		check('files written', fs.existsSync(path.join(tmp, 'out', 'features', 'blog', 'blog-1.2.3.zip')) && fs.existsSync(path.join(tmp, 'out', 'features', 'blog', 'latest.json')));
		let refused = false;
		const other = crypto.generateKeyPairSync('ed25519').privateKey.export({ type: 'pkcs8', format: 'pem' });
		try { sign({ ...base, out: path.join(tmp, 'out2') }, { [KEY_ENV]: other }); } catch (e) { refused = e instanceof SignError && !fs.existsSync(path.join(tmp, 'out2')); }
		check('a secret that does not match the pinned key writes nothing', refused);
		let badSlug = false;
		try { sign({ ...base, slug: 'wireframe' }, { [KEY_ENV]: pem }); } catch (e) { badSlug = e instanceof SignError; }
		check('the plugin itself (wireframe) is not a deliverable feature', badSlug);
	} finally {
		fs.rmSync(tmp, { recursive: true, force: true });
	}
	if (failed) process.exit(1);
	console.log('self-test OK');
}

try {
	const o = parseArgs(process.argv.slice(2));
	if (o.selfTest) selfTest();
	else {
		const m = sign(o, process.env);
		console.log(`signed ${m.slug} ${m.version} (${m.size} bytes) with ${m.key_id}`);
	}
} catch (e) {
	console.error(e instanceof SignError ? `sign-feature: ${e.message}` : e);
	process.exit(1);
}
