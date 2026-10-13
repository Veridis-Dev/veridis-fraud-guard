import { readFile } from 'node:fs/promises';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const projectRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const destination = path.resolve(process.argv[2] || '/tmp/veridis-fraud-guard.pot');
const manifestPath = path.join(projectRoot, 'build', 'manifest.json');

let manifest;
try {
  manifest = JSON.parse(await readFile(manifestPath, 'utf8'));
} catch (error) {
  console.error(`Unable to read ${manifestPath}. Run npm run build first.`);
  process.exit(1);
}

const adminEntry = manifest['src/AdminApp/main.tsx'];
if (!adminEntry?.file || typeof adminEntry.file !== 'string') {
  console.error('The production manifest does not contain the admin application entry.');
  process.exit(1);
}

const adminBundlePath = path.join(projectRoot, 'build', adminEntry.file);
let adminBundle;
try {
  adminBundle = await readFile(adminBundlePath, 'utf8');
} catch (error) {
  console.error(`Unable to read ${adminBundlePath}. Run npm run build first.`);
  process.exit(1);
}

if (!/\b(?:__|_n|_nx|_x)\(/.test(adminBundle)) {
  console.error('The production admin bundle does not contain extractable WordPress gettext calls.');
  process.exit(1);
}

const include = [
  'veridis-fraud-guard.php',
  'uninstall.php',
  'src/PHP',
  `build/${adminEntry.file}`,
].join(',');

const wpCli = process.env.WP_CLI_BIN || 'wp';
const usesPhar = wpCli.endsWith('.phar');
const command = usesPhar ? (process.env.PHP_BINARY || 'php') : wpCli;
const args = [
  ...(usesPhar ? [wpCli] : []),
  'i18n',
  'make-pot',
  projectRoot,
  destination,
  '--domain=veridis-fraud-guard',
  `--include=${include}`,
];

const result = spawnSync(command, args, { cwd: projectRoot, encoding: 'utf8' });
if (result.stdout) process.stdout.write(result.stdout);
if (result.stderr) process.stderr.write(result.stderr);
if (result.error) {
  console.error(`Unable to run WP-CLI: ${result.error.message}`);
  process.exit(1);
}

const extractionOutput = `${result.stdout || ''}\n${result.stderr || ''}`;
if (extractionOutput.includes('has no "translators:" comment')) {
  console.error('WP-CLI found a formatted message without an associated translator comment.');
  process.exit(1);
}

process.exit(result.status ?? 1);
