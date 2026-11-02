import { access, readdir, readFile } from 'node:fs/promises';
import {
  dirname,
  join,
  relative,
  resolve,
  sep,
} from 'node:path';
import { fileURLToPath } from 'node:url';

import { semanticVersionPattern } from './regex.mjs';

/**
 * Check Package - Workspace Directory.
 *
 * Resolves the generated pfSense package workspace from this script file.
 * Every structural check uses this stable absolute directory as its base.
 *
 * @since 0.0.0
 */
const workspaceDirectory = resolve(dirname(fileURLToPath(import.meta.url)), '..');

/**
 * Check Package - Local Payload Directory.
 *
 * Contains every file installed below /usr/local by the generated FreeBSD port.
 * Recursive discovery prevents a newly added runtime file from missing the plist.
 *
 * @since 0.0.0
 */
const localPayloadDirectory = join(workspaceDirectory, 'port', 'files', 'usr', 'local');

/**
 * Check Package - Required Files.
 *
 * Lists every source artifact needed to stage and publish the pfSense package.
 * A missing entry fails validation before a release archive is attempted.
 *
 * @since 0.0.0
 */
const requiredFiles = [
  'package.json',
  'port/Makefile.template',
  'port/pkg-descr',
  'port/pkg-plist',
  'port/files/pkg-deinstall.in',
  'port/files/pkg-install.in',
  'port/files/usr/local/pkg/ipv6-prefix-updater.inc',
  'port/files/usr/local/pkg/ipv6-prefix-updater.xml',
  'port/files/usr/local/pkg/ipv6-prefix-updater/BackupStore.php',
  'port/files/usr/local/pkg/ipv6-prefix-updater/ConfigurationChangePlanner.php',
  'port/files/usr/local/pkg/ipv6-prefix-updater/ConfigurationFieldDetector.php',
  'port/files/usr/local/pkg/ipv6-prefix-updater/ConfigurationTargetScanner.php',
  'port/files/usr/local/pkg/ipv6-prefix-updater/FieldSelection.php',
  'port/files/usr/local/pkg/ipv6-prefix-updater/FieldTranslations.php',
  'port/files/usr/local/pkg/ipv6-prefix-updater/FileLock.php',
  'port/files/usr/local/pkg/ipv6-prefix-updater/Ipv6Prefix.php',
  'port/files/usr/local/pkg/ipv6-prefix-updater/LegacyInstallation.php',
  'port/files/usr/local/pkg/ipv6-prefix-updater/LegacyMigrator.php',
  'port/files/usr/local/pkg/ipv6-prefix-updater/MigrationTranslations.php',
  'port/files/usr/local/pkg/ipv6-prefix-updater/MonitoredInterfaces.php',
  'port/files/usr/local/pkg/ipv6-prefix-updater/PackagePaths.php',
  'port/files/usr/local/pkg/ipv6-prefix-updater/PfSenseAdapter.php',
  'port/files/usr/local/pkg/ipv6-prefix-updater/PlatformAdapter.php',
  'port/files/usr/local/pkg/ipv6-prefix-updater/PrefixChangePlanner.php',
  'port/files/usr/local/pkg/ipv6-prefix-updater/PrefixUpdater.php',
  'port/files/usr/local/pkg/ipv6-prefix-updater/StateStore.php',
  'port/files/usr/local/pkg/ipv6-prefix-updater/Translations.php',
  'port/files/usr/local/pkg/ipv6-prefix-updater/Translator.php',
  'port/files/usr/local/sbin/ipv6-prefix-updater',
  'port/files/usr/local/share/pfSense-pkg-Ipv6PrefixUpdater/info.xml',
  'port/files/usr/local/www/ipv6-prefix-updater-backups.php',
  'port/files/usr/local/www/ipv6-prefix-updater-cleanup.php',
  'port/files/usr/local/www/ipv6-prefix-updater-fields.php',
  'port/files/usr/local/www/ipv6-prefix-updater-status.php',
  'scripts/test-domain.sh',
  'tests/domain.php',
  'tests/pfsense-adapter.php',
  'tests/runtime.php',
  'tests/translations.php',
  'tests/webgui-localization.php',
  'tests/stubs/config.inc',
  'tests/stubs/interfaces.inc',
  'tests/stubs/pfsense-utils.inc',
  'tests/stubs/services.inc',
];

await Promise.all(requiredFiles.map(async (requiredFile) => {
  await access(join(workspaceDirectory, requiredFile));

  return;
}));

/**
 * Check Package - package.json Raw.
 *
 * Reads the workspace manifest before its release version is validated.
 * The raw value remains separate from the parsed object for clear diagnostics.
 *
 * @since 0.0.0
 */
const packageJsonRaw = await readFile(join(workspaceDirectory, 'package.json'), 'utf-8');

/**
 * Check Package - package.json.
 *
 * Parses the workspace manifest used to stage the FreeBSD port version.
 * Validation below rejects missing and malformed release values.
 *
 * @since 0.0.0
 */
const packageJson = JSON.parse(packageJsonRaw);

/**
 * Check Package - Version.
 *
 * Selects the manifest version embedded into the staged FreeBSD port.
 * Semantic-version validation keeps generated release metadata predictable.
 *
 * @since 0.0.0
 */
const version = packageJson['version'];

if (typeof version !== 'string' || semanticVersionPattern.test(version) === false) {
  throw new Error('package.json must contain a semantic version that can be staged as a FreeBSD port version.');
}

/**
 * Check Package - Makefile Template.
 *
 * Reads the port Makefile template so its Nova version token can be checked.
 * The staging command replaces this token with the validated package version.
 *
 * @since 0.0.0
 */
const makefileTemplate = await readFile(join(workspaceDirectory, 'port', 'Makefile.template'), 'utf-8');

if (makefileTemplate.includes('%%NOVA_PORTVERSION%%') === false) {
  throw new Error('port/Makefile.template must retain the %%NOVA_PORTVERSION%% build placeholder.');
}

/**
 * Check Package - Payload Directory Entries.
 *
 * Discovers the complete /usr/local payload instead of relying only on a maintained
 * list. Every discovered file must be installed and represented in pkg-plist.
 *
 * @since 0.0.0
 */
const payloadDirectoryEntries = await readdir(localPayloadDirectory, {
  recursive: true,
  withFileTypes: true,
});

/**
 * Check Package - Payload Files.
 *
 * Keeps only regular files from recursive discovery and normalizes their paths for
 * deterministic manifest validation.
 *
 * @since 0.0.0
 */
const payloadFiles = payloadDirectoryEntries
  .filter((directoryEntry) => directoryEntry.isFile())
  .map((directoryEntry) => join(directoryEntry.parentPath, directoryEntry.name))
  .toSorted();

/**
 * Check Package - Expected Plist Entries.
 *
 * Maps every payload source to its installed path and sorts the result for exact
 * comparison with the package manifest.
 *
 * @since 0.0.0
 */
const expectedPlistEntries = payloadFiles
  .map((sourcePath) => relative(localPayloadDirectory, sourcePath).split(sep).join('/'))
  .map((localPath) => localPath.replace(
    'share/pfSense-pkg-Ipv6PrefixUpdater/',
    '%%DATADIR%%/',
  ))
  .toSorted();

/**
 * Check Package - Plist Entries.
 *
 * Loads nonempty manifest lines so missing, duplicate, and stale payload entries fail
 * before a package reaches a FreeBSD builder.
 *
 * @since 0.0.0
 */
const plistEntries = (await readFile(join(workspaceDirectory, 'port', 'pkg-plist'), 'utf-8'))
  .split('\n')
  .map((entry) => entry.trim())
  .filter((entry) => entry.length > 0);

/**
 * Check Package - Unique Plist Entries.
 *
 * Deduplicates the parsed manifest so duplicate entries can be rejected explicitly.
 * The original order remains available for the cardinality comparison.
 *
 * @since 0.0.0
 */
const uniquePlistEntries = [...new Set(plistEntries)];

if (uniquePlistEntries.length !== plistEntries.length) {
  throw new Error('port/pkg-plist contains duplicate entries.');
}

/**
 * Check Package - Sorted Plist Entries.
 *
 * Normalizes manifest ordering before comparing it with recursively discovered source
 * files, leaving source ordering free to remain human-friendly.
 *
 * @since 0.0.0
 */
const sortedPlistEntries = uniquePlistEntries.toSorted();

if (JSON.stringify(sortedPlistEntries) !== JSON.stringify(expectedPlistEntries)) {
  const missingEntries = expectedPlistEntries.filter((entry) => sortedPlistEntries.includes(entry) === false);
  const staleEntries = sortedPlistEntries.filter((entry) => expectedPlistEntries.includes(entry) === false);
  const missingEntryText = (missingEntries.length > 0) ? missingEntries.join(', ') : 'none';
  const staleEntryText = (staleEntries.length > 0) ? staleEntries.join(', ') : 'none';

  throw new Error([
    'port/pkg-plist must exactly match the /usr/local package payload.',
    `Missing: ${missingEntryText}.`,
    `Stale: ${staleEntryText}.`,
  ].join(' '));
}

/**
 * Check Package - Makefile Sources.
 *
 * Converts installed plist paths to source expressions that must appear in do-install.
 * Package metadata uses DATADIR while every other payload lives below PREFIX.
 *
 * @since 0.0.0
 */
const makefileSources = [
  ...expectedPlistEntries
    .filter((plistEntry) => plistEntry.startsWith('%%DATADIR%%/'))
    .map((plistEntry) => `\${FILESDIR}\${DATADIR}/${plistEntry.slice('%%DATADIR%%/'.length)}`),
  ...expectedPlistEntries
    .filter((plistEntry) => plistEntry.startsWith('%%DATADIR%%/') === false)
    .map((plistEntry) => `\${FILESDIR}\${PREFIX}/${plistEntry}`),
];

for (const makefileSource of makefileSources) {
  if (makefileTemplate.includes(makefileSource) === false) {
    throw new Error(`port/Makefile.template does not install payload source "${makefileSource}".`);
  }
}

/**
 * Check Package - Executable Install Marker.
 *
 * Constructs the literal make-variable expression without embedding template syntax in
 * a normal JavaScript string, then confirms the CLI receives executable permissions.
 *
 * @since 0.0.0
 */
const executableInstallMarker = [
  '$',
  '{INSTALL_SCRIPT} ',
  '$',
  '{FILESDIR}',
  '$',
  '{PREFIX}/sbin/ipv6-prefix-updater',
].join('');

if (makefileTemplate.includes(executableInstallMarker) === false) {
  throw new Error('The command-line entry point must be installed with INSTALL_SCRIPT.');
}

/**
 * Check Package - Command Line Entry Point.
 *
 * A direct PHP shebang leaves package flags after the script path for application
 * parsing. The -f interpreter option would consume flags such as --manual itself.
 *
 * @since 2.0.0
 */
const commandLineEntryPoint = await readFile(
  join(workspaceDirectory, 'port', 'files', 'usr', 'local', 'sbin', 'ipv6-prefix-updater'),
  'utf-8',
);

/**
 * Check Package - Newline Character.
 *
 * Creates the line separator without embedding multiline content in a string literal.
 * It lets the structural check isolate the executable's interpreter declaration.
 *
 * @since 2.0.0
 */
const newlineCharacter = String.fromCharCode(10);

/**
 * Check Package - Command Line Entry Point Lines.
 *
 * Separates the executable source so the first line can be compared exactly without
 * accepting interpreter options that would consume package arguments.
 *
 * @since 2.0.0
 */
const commandLineEntryPointLines = commandLineEntryPoint.split(newlineCharacter);

/**
 * Check Package - Command Line Entry Point Shebang.
 *
 * Selects the installed interpreter declaration used by direct shell and WebGUI calls.
 * An empty source remains invalid through the exact comparison below.
 *
 * @since 2.0.0
 */
const commandLineEntryPointShebang = commandLineEntryPointLines[0];

if (commandLineEntryPointShebang !== '#!/usr/local/bin/php') {
  throw new Error('The command-line entry point must use a direct PHP shebang without interpreter flags.');
}

if (commandLineEntryPoint.includes('case \'--accept-current-prefix\':') === false
  || commandLineEntryPoint.includes('$prefixUpdater->acceptCurrentPrefix($onlyInterface);') === false) {
  throw new Error('The command-line entry point must expose the locked baseline recovery action.');
}

if (commandLineEntryPoint.includes('case \'--migrate-legacy\':') === false
  || commandLineEntryPoint.includes('new LegacyMigrator(') === false) {
  throw new Error('The command-line entry point must expose the locked version 1.x cleanup action.');
}

/**
 * Check Package - Package Info XML.
 *
 * Keeps the metadata name aligned with the suffix passed by pfSense's package lifecycle
 * scripts. A display label here prevents post-install registration from completing.
 *
 * @since 2.0.0
 */
const packageInfoXml = await readFile(
  join(
    workspaceDirectory,
    'port',
    'files',
    'usr',
    'local',
    'share',
    'pfSense-pkg-Ipv6PrefixUpdater',
    'info.xml',
  ),
  'utf-8',
);

if (packageInfoXml.includes('<name>Ipv6PrefixUpdater</name>') === false) {
  throw new Error('Package info.xml must use the internal pfSense package name Ipv6PrefixUpdater.');
}

/**
 * Check Package - Web Gui XML.
 *
 * Confirms the generated package configuration retains every required settings field,
 * lifecycle hook, and status-page link used by the runtime.
 *
 * @since 0.0.0
 */
const webGuiXml = await readFile(
  join(workspaceDirectory, 'port', 'files', 'usr', 'local', 'pkg', 'ipv6-prefix-updater.xml'),
  'utf-8',
);

/**
 * Check Package - Required Web Gui Markers.
 *
 * Lists settings, hooks, and navigation required for the package's complete native
 * interface. A missing marker fails before staging or publication.
 *
 * @since 0.0.0
 */
const requiredWebGuiMarkers = [
  '<name>ipv6prefixupdater</name>',
  '<include_file>/usr/local/pkg/ipv6-prefix-updater.inc</include_file>',
  '<aftersaveredirect>/pkg_edit.php?xml=ipv6-prefix-updater.xml&amp;id=0&amp;savemsg=Settings%20saved.</aftersaveredirect>',
  '<url>/pkg_edit.php?xml=ipv6-prefix-updater.xml&amp;id=0</url>',
  '<url>/ipv6-prefix-updater-status.php</url>',
  '<url>/ipv6-prefix-updater-fields.php</url>',
  '<fieldname>enabled</fieldname>',
  '<fieldname>interface</fieldname>',
  '<fieldname>prefix_length</fieldname>',
  '<fieldname>update_frequency</fieldname>',
  '<fieldname>notifications</fieldname>',
  'pfsensePackageIpv6PrefixUpdaterSync();',
  '<custom_php_deinstall_command>',
  'pfsensePackageIpv6PrefixUpdaterRemove();',
  'pfsensePackageIpv6PrefixUpdaterValidate($_POST, $input_errors);',
  'pfsensePackageIpv6PrefixUpdaterLocalizePackageGui($pkg);',
];

for (const requiredWebGuiMarker of requiredWebGuiMarkers) {
  if (webGuiXml.includes(requiredWebGuiMarker) === false) {
    throw new Error(`The WebGUI definition is missing required content: ${requiredWebGuiMarker}`);
  }
}

if (webGuiXml.includes('<name>ipv6-prefix-updater</name>') === true) {
  throw new Error('The WebGUI package name must already be safe for use as a pfSense configuration key.');
}

if (webGuiXml.includes('<fieldname>schedule</fieldname>') === true) {
  throw new Error('The WebGUI must avoid the reserved pfSense schedule configuration tag.');
}

if (webGuiXml.includes('<fieldname>scope_') === true) {
  throw new Error('Field group switches belong on the Fields tab, not in the settings record.');
}

/**
 * Check Package - Runtime Configuration Sources.
 *
 * Both the lifecycle include and execution adapter must read the same normalized key
 * used by pfSense's generic package editor.
 *
 * @since 2.0.0
 */
const runtimeConfigurationSources = await Promise.all([
  readFile(
    join(workspaceDirectory, 'port', 'files', 'usr', 'local', 'pkg', 'ipv6-prefix-updater.inc'),
    'utf-8',
  ),
  readFile(
    join(
      workspaceDirectory,
      'port',
      'files',
      'usr',
      'local',
      'pkg',
      'ipv6-prefix-updater',
      'PfSenseAdapter.php',
    ),
    'utf-8',
  ),
]);

for (const runtimeConfigurationSource of runtimeConfigurationSources) {
  if (runtimeConfigurationSource.includes('installedpackages/ipv6prefixupdater/config/0') === false) {
    throw new Error('Every runtime settings reader must use the normalized WebGUI configuration key.');
  }
}

/**
 * Check Package - Status Page.
 *
 * The custom status page must return to the singleton settings editor rather than the
 * generic repeatable-record list.
 *
 * @since 2.0.0
 */
const statusPage = await readFile(
  join(workspaceDirectory, 'port', 'files', 'usr', 'local', 'www', 'ipv6-prefix-updater-status.php'),
  'utf-8',
);

if (statusPage.includes('/pkg_edit.php?xml=ipv6-prefix-updater.xml&id=0') === false) {
  throw new Error('The status page Settings tab must link directly to the singleton package editor.');
}

for (const iconClass of [
  'fa-solid fa-play',
  'fa-solid fa-search',
  'fa-solid fa-check-circle',
]) {
  if (statusPage.includes(`'${iconClass}'`) === false) {
    throw new Error(`The status action icon must include its Font Awesome style: ${iconClass}.`);
  }
}

if (statusPage.includes('new Form_Textarea(') === false
  || statusPage.includes('setAttribute(\'readonly\', true)') === false) {
  throw new Error('The status page must render captured command output in a read-only textarea.');
}

if (statusPage.includes('exec(\'/usr/local/sbin/ipv6-prefix-updater --dry-run 2>&1\'') === false) {
  throw new Error('The status page must capture dry-run command output for the operator.');
}

if (statusPage.includes('exec(\'/usr/local/sbin/ipv6-prefix-updater --accept-current-prefix 2>&1\'') === false) {
  throw new Error('The status page must capture baseline recovery command output for the operator.');
}

if (statusPage.includes('ConfigurationFieldDetector::detect(') === true
  || statusPage.includes('name="approved_fields[]"') === true) {
  throw new Error('Field selection belongs on the Fields tab, not the status page.');
}

if (statusPage.includes('Translator::translate(\'last_run_skipped_fields\')') === false
  || statusPage.includes('Translator::translate(\'last_run_packages\')') === false) {
  throw new Error('The status page must list the fields and package settings the last run skipped or applied again.');
}

if (statusPage.includes('Translator::translate(\'accept_current_prefix_confirm\')') === false
  || statusPage.includes('\'return confirm(\'') === false) {
  throw new Error('The baseline recovery action must use a distinct icon and explicit confirmation.');
}

/**
 * Check Package - Action Section Position.
 *
 * Locates the action controls in the rendered-form construction order.
 * The paired output position verifies their visible relationship.
 *
 * @since 2.0.0
 */
const actionSectionPosition = statusPage.indexOf('new Form_Section(Translator::translate(\'actions\'))');

/**
 * Check Package - Output Section Position.
 *
 * Locates the command output in the rendered-form construction order.
 * Its position must follow the controls that generated the output.
 *
 * @since 2.0.0
 */
const outputSectionPosition = statusPage.indexOf('new Form_Section(Translator::translate(\'command_output\'))');

if (actionSectionPosition === -1
  || outputSectionPosition === -1
  || outputSectionPosition < actionSectionPosition) {
  throw new Error('The status page must render command output below the actions that produced it.');
}

/**
 * Check Package - Fields Page.
 *
 * The custom Fields page needs its own privilege, lists fields with the detector, and
 * saves the selection under the package lock so a running check can't interleave.
 *
 * @since 2.0.0
 */
const fieldsPage = await readFile(
  join(workspaceDirectory, 'port', 'files', 'usr', 'local', 'www', 'ipv6-prefix-updater-fields.php'),
  'utf-8',
);

if (fieldsPage.includes('##|+PRIV') === false
  || fieldsPage.includes('##|*MATCH=ipv6-prefix-updater-fields.php*') === false) {
  throw new Error('The Fields page must declare its own WebGUI privilege.');
}

if (fieldsPage.includes('ConfigurationFieldDetector::detect(') === false
  || fieldsPage.includes('FieldSelection::applyChoices(') === false
  || fieldsPage.includes('new FileLock(PackagePaths::LOCK_FILE)') === false
  || fieldsPage.includes('pfsensePackageIpv6PrefixUpdaterSaveFieldSelection(') === false) {
  throw new Error('The Fields page must list fields and save the selection under the package lock.');
}

if (fieldsPage.includes('id="ipu-auto-select"') === false
  || fieldsPage.includes('id="ipu-clear-all"') === false
  || fieldsPage.includes('data-kind="recommended"') === false) {
  throw new Error('The Fields page must offer Auto-select and Clear All that set the switches on the page.');
}

for (const iconClass of [
  'fa fa-solid fa-magic',
  'fa fa-solid fa-times',
  'fa fa-solid fa-trash',
  'fa fa-solid fa-link',
  'fa fa-solid fa-save',
]) {
  if (fieldsPage.includes(`class="${iconClass}`) === false) {
    throw new Error(`The Fields page icon must support old and current pfSense: ${iconClass}.`);
  }
}

if (fieldsPage.includes('pfsensePackageIpv6PrefixUpdaterTabs(\'fields\')') === false) {
  throw new Error('The Fields page must show the package tabs with Fields active.');
}

/**
 * Check Package - Cleanup Page.
 *
 * Current pfSense requires an explicit Font Awesome style on Form_Button icons.
 * The complete class also remains valid when pfSense 2.7.2 prepends its legacy class.
 *
 * @since 2.0.1
 */
const cleanupPage = await readFile(
  join(workspaceDirectory, 'port', 'files', 'usr', 'local', 'www', 'ipv6-prefix-updater-cleanup.php'),
  'utf-8',
);

if (cleanupPage.includes('\'fa-solid fa-eraser\'') === false) {
  throw new Error('The cleanup action icon must include its Font Awesome style.');
}

/**
 * Check Package - Backups Page.
 *
 * Directly rendered icons keep the legacy fa class for pfSense 2.7.2 and add the
 * explicit solid style required by current pfSense releases.
 *
 * @since 2.0.1
 */
const backupsPage = await readFile(
  join(workspaceDirectory, 'port', 'files', 'usr', 'local', 'www', 'ipv6-prefix-updater-backups.php'),
  'utf-8',
);

if (backupsPage.includes('class="fa fa-solid fa-download"') === false
  || backupsPage.includes('class="fa fa-solid fa-trash no-confirm"') === false) {
  throw new Error('Backup action icons must support old and current pfSense.');
}

process.stdout.write('pfSense package sources are structurally valid.\n');
