import { lstat, readFile } from 'node:fs/promises';
import path from 'node:path';
import {
  PLUGIN_SLUG,
  assertSafeRelativePath,
  compareArchivePaths,
  getDistributionPlan,
  repositoryRoot,
} from './package-config.mjs';
import { readZipEntries } from './deterministic-zip.mjs';

const FORBIDDEN_SEGMENTS = new Set([
  '.aws',
  '.git',
  '.github',
  'dist',
  'docs',
  'fixtures',
  'node_modules',
  'tests',
]);

function readArchiveArgument(defaultArchive) {
  const args = process.argv.slice(2);
  if (args.length === 0) {
    return defaultArchive;
  }
  if (args.length === 1) {
    return path.resolve(repositoryRoot, args[0]);
  }
  throw new Error('Usage: node scripts/verify-package.mjs [zip-path]');
}

function assertNoForbiddenPath(relativePath) {
  const segments = relativePath.split('/');
  const basename = segments.at(-1).toLowerCase();
  if (segments.some((segment) => FORBIDDEN_SEGMENTS.has(segment))) {
    throw new Error(`Forbidden path entered the package: ${relativePath}`);
  }
  if (
    basename === '.env'
    || basename.startsWith('.env.')
    || basename.endsWith('.log')
    || basename.endsWith('.pem')
    || basename.endsWith('.key')
    || basename.endsWith('~')
  ) {
    throw new Error(`Potentially private or temporary file entered the package: ${relativePath}`);
  }
}

const plan = await getDistributionPlan();
const defaultArchive = path.join(repositoryRoot, 'dist', `${PLUGIN_SLUG}-${plan.version}.zip`);
const archivePath = readArchiveArgument(defaultArchive);
const archiveStats = await lstat(archivePath);
if (archiveStats.isSymbolicLink() || !archiveStats.isFile()) {
  throw new Error(`Package must be a regular file, not a link or special entry: ${archivePath}`);
}
const entries = await readZipEntries(archivePath);
const expectedNames = plan.files.map((relativePath) => `${PLUGIN_SLUG}/${relativePath}`);
const actualNames = entries.map((entry) => entry.name);

if (new Set(actualNames).size !== actualNames.length) {
  throw new Error('The package contains duplicate archive entries.');
}
if (JSON.stringify(actualNames) !== JSON.stringify([...actualNames].sort(compareArchivePaths))) {
  throw new Error('Archive entries are not in deterministic byte order.');
}
if (JSON.stringify(actualNames) !== JSON.stringify(expectedNames)) {
  throw new Error('Archive contents do not exactly match the distribution allowlist.');
}

const entryMap = new Map();
for (const entry of entries) {
  if (!entry.name.startsWith(`${PLUGIN_SLUG}/`)) {
    throw new Error(`Archive entry is outside the required top-level plugin folder: ${entry.name}`);
  }
  const relativePath = entry.name.slice(PLUGIN_SLUG.length + 1);
  assertSafeRelativePath(relativePath);
  assertNoForbiddenPath(relativePath);

  const unixMode = (entry.externalAttributes >>> 16) & 0xffff;
  if ((unixMode & 0o170000) === 0o120000) {
    throw new Error(`Symbolic links are forbidden in the package: ${relativePath}`);
  }
  if ((unixMode & 0o170000) !== 0o100000) {
    throw new Error(`Archive entry is not a regular file: ${relativePath}`);
  }

  const source = await readFile(path.join(repositoryRoot, relativePath));
  if (!entry.data.equals(source)) {
    throw new Error(`Archive content differs from the allowlisted source file: ${relativePath}`);
  }
  entryMap.set(relativePath, entry.data);
}

const manifest = JSON.parse(entryMap.get('build/manifest.json').toString('utf8'));
for (const manifestEntry of Object.values(manifest)) {
  const references = [manifestEntry?.file, ...(manifestEntry?.css ?? []), ...(manifestEntry?.assets ?? [])];
  for (const reference of references) {
    if (!entryMap.has(`build/${reference}`)) {
      throw new Error(`Manifest asset is absent from the package: build/${reference}`);
    }
  }
}

for (const requiredRuntimeFile of [
  'veridis-fraud-guard.php',
  'uninstall.php',
  'readme.txt',
  'LICENSE.txt',
  'THIRD-PARTY-NOTICES.md',
  'assets/order-list.css',
  'src/PHP/Core/Plugin.php',
]) {
  if (!entryMap.has(requiredRuntimeFile)) {
    throw new Error(`Required runtime or metadata file is absent: ${requiredRuntimeFile}`);
  }
}

console.log(`Verified ${entries.length} allowlisted regular files in ${archivePath}`);
