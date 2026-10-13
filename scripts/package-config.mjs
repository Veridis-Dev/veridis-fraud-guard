import { lstat, readFile, readdir, realpath } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

export const PLUGIN_SLUG = 'veridis-fraud-guard';
export const repositoryRoot = path.resolve(fileURLToPath(new URL('..', import.meta.url)));

const REQUIRED_FILES = [
  'LICENSE.txt',
  'README.md',
  'THIRD-PARTY-NOTICES.md',
  'assets/fonts/geist/Geist-Bold.woff2',
  'assets/fonts/geist/Geist-Medium.woff2',
  'assets/fonts/geist/Geist-Regular.woff2',
  'assets/fonts/geist/Geist-SemiBold.woff2',
  'assets/fonts/geist/OFL.txt',
  'assets/order-list.css',
  'package-lock.json',
  'package.json',
  'readme.txt',
  'scripts/deterministic-zip.mjs',
  'scripts/i18n-extract.mjs',
  'scripts/package-config.mjs',
  'scripts/package-release.mjs',
  'scripts/verify-package.mjs',
  'tsconfig.json',
  'uninstall.php',
  'veridis-fraud-guard.php',
  'vite.config.ts',
];

const SOURCE_FILES = [
  'src/AdminApp/api/activity.ts',
  'src/AdminApp/api/blocklist.ts',
  'src/AdminApp/api/client.ts',
  'src/AdminApp/api/importExport.ts',
  'src/AdminApp/api/overview.ts',
  'src/AdminApp/api/settings.ts',
  'src/AdminApp/app/App.tsx',
  'src/AdminApp/app/ErrorBoundary.tsx',
  'src/AdminApp/components/ActivityMatchSummary.tsx',
  'src/AdminApp/components/ActivityPage.tsx',
  'src/AdminApp/components/BlocklistPage.tsx',
  'src/AdminApp/components/ImportExportPage.tsx',
  'src/AdminApp/components/Overview.tsx',
  'src/AdminApp/components/OverviewSkeleton.tsx',
  'src/AdminApp/components/ProductShellPages.tsx',
  'src/AdminApp/components/SettingsPage.tsx',
  'src/AdminApp/csvLimits.ts',
  'src/AdminApp/design-system/Badge.tsx',
  'src/AdminApp/design-system/Button.tsx',
  'src/AdminApp/design-system/Card.tsx',
  'src/AdminApp/design-system/EmptyState.tsx',
  'src/AdminApp/design-system/Modal.tsx',
  'src/AdminApp/design-system/Notice.tsx',
  'src/AdminApp/design-system/Skeleton.tsx',
  'src/AdminApp/design-system/Toast.tsx',
  'src/AdminApp/format.ts',
  'src/AdminApp/hooks/useActivity.ts',
  'src/AdminApp/hooks/useBlocklist.ts',
  'src/AdminApp/hooks/useOverview.ts',
  'src/AdminApp/main.tsx',
  'src/AdminApp/styles/app.css',
  'src/AdminApp/styles/tokens.css',
  'src/AdminApp/types/index.ts',
  'src/AdminApp/vite-env.d.ts',
  'src/AdminApp/wordpress-element.ts',
  'src/AdminApp/wordpress-i18n.ts',
  'src/OrderPanel/main.ts',
  'src/OrderPanel/styles.css',
  'src/PHP/Activity/ActivityIdentifierRetention.php',
  'src/PHP/Activity/ActivityPrivacy.php',
  'src/PHP/Activity/ActivityRepository.php',
  'src/PHP/Activity/ActivityService.php',
  'src/PHP/Activity/BlocklistActivityRecorder.php',
  'src/PHP/Activity/MatchedIdentifierSet.php',
  'src/PHP/Admin/AdminPage.php',
  'src/PHP/Admin/Assets.php',
  'src/PHP/Admin/BlocklistExportController.php',
  'src/PHP/Admin/OrderPanel.php',
  'src/PHP/Admin/OrdersList.php',
  'src/PHP/Blocklist/BlocklistEntry.php',
  'src/PHP/Blocklist/BlocklistNormalizer.php',
  'src/PHP/Blocklist/BlocklistRepository.php',
  'src/PHP/Blocklist/BlocklistService.php',
  'src/PHP/CSV/BlocklistCsvExporter.php',
  'src/PHP/CSV/BlocklistCsvImporter.php',
  'src/PHP/CSV/SpreadsheetFormulaProtector.php',
  'src/PHP/Checkout/CheckoutActivityRecorder.php',
  'src/PHP/Checkout/CheckoutBlocklistEvaluator.php',
  'src/PHP/Checkout/CheckoutMode.php',
  'src/PHP/Checkout/CheckoutRejectionMessage.php',
  'src/PHP/Checkout/ClassicCheckoutBlockEnforcement.php',
  'src/PHP/Checkout/CodCheckoutHoldEnforcement.php',
  'src/PHP/Checkout/StoreApiCheckoutBlockEnforcement.php',
  'src/PHP/Core/Access.php',
  'src/PHP/Core/Installer.php',
  'src/PHP/Core/Plugin.php',
  'src/PHP/Core/Uninstaller.php',
  'src/PHP/Orders/OrderBlocklistManager.php',
  'src/PHP/Orders/OrderIdentityResolver.php',
  'src/PHP/REST/ActivityController.php',
  'src/PHP/REST/BlocklistController.php',
  'src/PHP/REST/BlocklistImportController.php',
  'src/PHP/REST/CheckoutSettingsController.php',
  'src/PHP/REST/OrderBlocklistController.php',
  'src/PHP/REST/OverviewController.php',
];

const EXPECTED_MANIFEST_KEYS = [
  'assets/fonts/geist/Geist-Bold.woff2',
  'assets/fonts/geist/Geist-Medium.woff2',
  'assets/fonts/geist/Geist-Regular.woff2',
  'assets/fonts/geist/Geist-SemiBold.woff2',
  'src/AdminApp/main.tsx',
  'src/OrderPanel/main.ts',
];

const GUARDED_ROOTS = ['assets', 'src/AdminApp', 'src/OrderPanel', 'src/PHP'];

export function compareArchivePaths(left, right) {
  return Buffer.from(left).compare(Buffer.from(right));
}

export function assertSafeRelativePath(relativePath) {
  if (
    typeof relativePath !== 'string'
    || relativePath.length === 0
    || relativePath.includes('\\')
    || relativePath.includes('\0')
    || path.posix.isAbsolute(relativePath)
  ) {
    throw new Error(`Unsafe distribution path: ${JSON.stringify(relativePath)}`);
  }

  const segments = relativePath.split('/');
  if (segments.some((segment) => segment === '' || segment === '.' || segment === '..')) {
    throw new Error(`Unsafe distribution path: ${relativePath}`);
  }

  if (path.posix.normalize(relativePath) !== relativePath) {
    throw new Error(`Non-normalized distribution path: ${relativePath}`);
  }
}

async function assertRegularFile(relativePath) {
  assertSafeRelativePath(relativePath);
  const segments = relativePath.split('/');
  let current = repositoryRoot;

  for (const [index, segment] of segments.entries()) {
    current = path.join(current, segment);
    const stats = await lstat(current);
    if (stats.isSymbolicLink()) {
      throw new Error(`Symlinks are not allowed in the distribution: ${relativePath}`);
    }
    if (index < segments.length - 1 && !stats.isDirectory()) {
      throw new Error(`Distribution path parent is not a directory: ${relativePath}`);
    }
    if (index === segments.length - 1 && !stats.isFile()) {
      throw new Error(`Distribution path is not a regular file: ${relativePath}`);
    }
  }

  const resolved = await realpath(current);
  const relativeResolved = path.relative(repositoryRoot, resolved);
  if (relativeResolved.startsWith(`..${path.sep}`) || path.isAbsolute(relativeResolved)) {
    throw new Error(`Distribution path escapes the repository: ${relativePath}`);
  }
}

async function walkFiles(relativeDirectory) {
  const directory = path.join(repositoryRoot, relativeDirectory);
  const directoryStats = await lstat(directory);
  if (directoryStats.isSymbolicLink() || !directoryStats.isDirectory()) {
    throw new Error(`Guarded root must be a real directory: ${relativeDirectory}`);
  }

  const files = [];
  const entries = await readdir(directory, { withFileTypes: true });
  entries.sort((left, right) => compareArchivePaths(left.name, right.name));

  for (const entry of entries) {
    const relativePath = `${relativeDirectory}/${entry.name}`;
    if (entry.isSymbolicLink()) {
      throw new Error(`Symlinks are not allowed in guarded roots: ${relativePath}`);
    }
    if (entry.isDirectory()) {
      files.push(...await walkFiles(relativePath));
    } else if (entry.isFile()) {
      files.push(relativePath);
    } else {
      throw new Error(`Unsupported filesystem entry in guarded root: ${relativePath}`);
    }
  }

  return files;
}

async function validateGuardedRoots(allowlistedFiles) {
  const allowed = new Set(allowlistedFiles);
  for (const root of GUARDED_ROOTS) {
    for (const relativePath of await walkFiles(root)) {
      if (!allowed.has(relativePath)) {
        throw new Error(`File in guarded runtime/source root is not explicitly allowlisted: ${relativePath}`);
      }
    }
  }
}

async function loadBuildFiles() {
  const manifestPath = path.join(repositoryRoot, 'build/manifest.json');
  const manifest = JSON.parse(await readFile(manifestPath, 'utf8'));
  const manifestKeys = Object.keys(manifest).sort(compareArchivePaths);
  const expectedKeys = [...EXPECTED_MANIFEST_KEYS].sort(compareArchivePaths);

  if (JSON.stringify(manifestKeys) !== JSON.stringify(expectedKeys)) {
    throw new Error(`Unexpected build manifest keys: ${manifestKeys.join(', ')}`);
  }

  const adminEntry = manifest['src/AdminApp/main.tsx'];
  const orderEntry = manifest['src/OrderPanel/main.ts'];
  if (!adminEntry?.isEntry || !orderEntry?.isEntry) {
    throw new Error('Both production JavaScript entry points must be present in build/manifest.json.');
  }

  const buildFiles = new Set(['build/manifest.json']);
  for (const entry of Object.values(manifest)) {
    const references = [entry?.file, ...(entry?.css ?? []), ...(entry?.assets ?? [])];
    for (const reference of references) {
      assertSafeRelativePath(reference);
      if (!reference.startsWith('assets/')) {
        throw new Error(`Manifest reference must remain inside build/assets: ${reference}`);
      }
      buildFiles.add(`build/${reference}`);
    }
  }

  return [...buildFiles].sort(compareArchivePaths);
}

async function validateVersionMetadata() {
  const packageMetadata = JSON.parse(await readFile(path.join(repositoryRoot, 'package.json'), 'utf8'));
  const pluginBootstrap = await readFile(path.join(repositoryRoot, 'veridis-fraud-guard.php'), 'utf8');
  const wordpressReadme = await readFile(path.join(repositoryRoot, 'readme.txt'), 'utf8');
  const headerVersion = pluginBootstrap.match(/^ \* Version:\s*(\S+)\s*$/m)?.[1];
  const runtimeVersion = pluginBootstrap.match(/define\( 'VERIDIS_FRAUD_GUARD_VERSION', '([^']+)' \);/)?.[1];
  const constructorUsesRuntimeVersion = /new \\Veridis\\FraudGuard\\Core\\Plugin\(\s*__FILE__,\s*VERIDIS_FRAUD_GUARD_VERSION\s*\)/s.test(pluginBootstrap);
  const stableTag = wordpressReadme.match(/^Stable tag:\s*(\S+)\s*$/mi)?.[1];
  const versions = [packageMetadata.version, headerVersion, runtimeVersion, stableTag];

  if (
    versions.some((version) => typeof version !== 'string')
    || new Set(versions).size !== 1
    || !constructorUsesRuntimeVersion
  ) {
    throw new Error(`Release metadata versions are inconsistent: ${versions.join(', ')}`);
  }

  return packageMetadata.version;
}

export async function getDistributionPlan() {
  const buildFiles = await loadBuildFiles();
  const files = [...new Set([...REQUIRED_FILES, ...SOURCE_FILES, ...buildFiles])].sort(compareArchivePaths);

  await validateGuardedRoots(files);
  await Promise.all(files.map(assertRegularFile));

  return {
    files,
    version: await validateVersionMetadata(),
  };
}
