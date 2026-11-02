import {
  // Node.js 22 LTS includes this API; it became stable before the first 22.x LTS release.
  // eslint-disable-next-line n/no-unsupported-features/node-builtins
  cp,
  mkdir,
  readFile,
  rm,
  writeFile,
} from 'node:fs/promises';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

/**
 * Stage Package - Workspace Directory.
 *
 * Resolves the generated pfSense package workspace from this script file.
 * Every staging path derives from this stable absolute directory.
 *
 * @since 0.0.0
 */
const workspaceDirectory = resolve(dirname(fileURLToPath(import.meta.url)), '..');

/**
 * Stage Package - Source Directory.
 *
 * Points at the source-controlled FreeBSD port template.
 * Staging copies this directory without modifying the checked-in sources.
 *
 * @since 0.0.0
 */
const sourceDirectory = join(workspaceDirectory, 'port');

/**
 * Stage Package - Build Directory.
 *
 * Points at the generated build output owned by this workspace.
 * Clean and build commands may replace its contents safely.
 *
 * @since 0.0.0
 */
const buildDirectory = join(workspaceDirectory, 'build');

/**
 * Stage Package - Staged Port Directory.
 *
 * Points at the copied FreeBSD port tree ready for packaging.
 * The directory is recreated for every production build.
 *
 * @since 0.0.0
 */
const stagedPortDirectory = join(buildDirectory, 'port');

/**
 * Stage Package - package.json Raw.
 *
 * Reads the workspace manifest before extracting its release version.
 * The raw value remains separate from the parsed manifest object.
 *
 * @since 0.0.0
 */
const packageJsonRaw = await readFile(join(workspaceDirectory, 'package.json'), 'utf-8');

/**
 * Stage Package - package.json.
 *
 * Parses the workspace manifest used by the staging process.
 * Its version becomes the FreeBSD port version in the generated Makefile.
 *
 * @since 0.0.0
 */
const packageJson = JSON.parse(packageJsonRaw);

/**
 * Stage Package - Version.
 *
 * Selects the package release version written into the staged port.
 * Empty and non-string values are rejected before files are copied.
 *
 * @since 0.0.0
 */
const version = packageJson['version'];

if (typeof version !== 'string' || version.length === 0) {
  throw new Error('package.json must contain a version before the pfSense port can be staged.');
}

await rm(stagedPortDirectory, {
  force: true,
  recursive: true,
});
await mkdir(buildDirectory, { recursive: true });
await cp(sourceDirectory, stagedPortDirectory, { recursive: true });

/**
 * Stage Package - Makefile Template Path.
 *
 * Points at the copied Makefile template inside the staging tree.
 * The template is removed after its version token is replaced.
 *
 * @since 0.0.0
 */
const makefileTemplatePath = join(stagedPortDirectory, 'Makefile.template');

/**
 * Stage Package - Makefile Path.
 *
 * Points at the final FreeBSD port Makefile produced by this build.
 * Package tooling reads this file from the staged output directory.
 *
 * @since 0.0.0
 */
const makefilePath = join(stagedPortDirectory, 'Makefile');

/**
 * Stage Package - Makefile Template.
 *
 * Reads the copied Makefile template before token replacement.
 * Source-controlled templates remain unchanged by the staging process.
 *
 * @since 0.0.0
 */
const makefileTemplate = await readFile(makefileTemplatePath, 'utf-8');

/**
 * Stage Package - Makefile.
 *
 * Produces the final Makefile with the current package version embedded.
 * This generated content exists only under the build directory.
 *
 * @since 0.0.0
 */
const makefile = makefileTemplate.replaceAll('%%NOVA_PORTVERSION%%', version);

await writeFile(makefilePath, makefile, 'utf-8');
await rm(makefileTemplatePath);

process.stdout.write(`Staged pfSense port version ${version} in "${stagedPortDirectory}".\n`);
