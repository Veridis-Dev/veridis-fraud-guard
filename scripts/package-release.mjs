import { lstat, mkdir, readFile } from 'node:fs/promises';
import path from 'node:path';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import {
  PLUGIN_SLUG,
  getDistributionPlan,
  repositoryRoot,
} from './package-config.mjs';
import { writeDeterministicZip } from './deterministic-zip.mjs';

function readOutputArgument(defaultOutput) {
  const args = process.argv.slice(2);
  if (args.length === 0) {
    return defaultOutput;
  }
  if (args.length === 2 && args[0] === '--output' && args[1] !== '') {
    return path.resolve(repositoryRoot, args[1]);
  }
  throw new Error('Usage: node scripts/package-release.mjs [--output <zip-path>]');
}

const plan = await getDistributionPlan();
const defaultOutput = path.join(repositoryRoot, 'dist', `${PLUGIN_SLUG}-${plan.version}.zip`);
const outputPath = readOutputArgument(defaultOutput);
const relativeOutput = path.relative(repositoryRoot, outputPath);

if (path.extname(outputPath).toLowerCase() !== '.zip') {
  throw new Error(`Package output must use a .zip extension: ${outputPath}`);
}
if (
  relativeOutput.startsWith(`..${path.sep}`)
  || path.isAbsolute(relativeOutput)
  || relativeOutput.split(path.sep)[0] !== 'dist'
) {
  throw new Error('Package output must remain inside the repository dist directory.');
}

await mkdir(path.dirname(outputPath), { recursive: true });
let outputParent = repositoryRoot;
for (const segment of path.dirname(relativeOutput).split(path.sep)) {
  outputParent = path.join(outputParent, segment);
  const stats = await lstat(outputParent);
  if (stats.isSymbolicLink() || !stats.isDirectory()) {
    throw new Error(`Package output directory is not a real directory: ${outputParent}`);
  }
}
const entries = await Promise.all(
  plan.files.map(async (relativePath) => ({
    data: await readFile(path.join(repositoryRoot, relativePath)),
    name: `${PLUGIN_SLUG}/${relativePath}`,
  })),
);

await writeDeterministicZip(outputPath, entries);

const verifyScript = fileURLToPath(new URL('./verify-package.mjs', import.meta.url));
execFileSync(process.execPath, [verifyScript, outputPath], { stdio: 'inherit' });
console.log(`Created deterministic distribution package: ${outputPath}`);
