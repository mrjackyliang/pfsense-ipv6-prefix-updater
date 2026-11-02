import {
  mkdir,
  readFile,
  rm,
  writeFile,
} from 'node:fs/promises';
import {
  dirname,
  join,
  resolve,
} from 'node:path';
import { fileURLToPath } from 'node:url';

import { NovaIdentity } from '@cbnventures/nova/toolkit';

/**
 * Generate Locales - Workspace Directory.
 *
 * Resolves every generated path from the documentation workspace rather than the
 * caller's current directory.
 *
 * @since 0.0.0
 */
const workspaceDirectory = resolve(dirname(fileURLToPath(import.meta.url)), '..');

/**
 * Generate Locales - Repository Directory.
 *
 * Locates the shared package sources used to keep documentation terminology aligned
 * with the WebGUI and runtime catalogs.
 *
 * @since 0.0.0
 */
const repositoryDirectory = resolve(workspaceDirectory, '..', '..');

/**
 * Generate Locales - Copyright.
 *
 * Reads the copyright line the English site renders, so every translated footer
 * carries the same years instead of a fixed range.
 *
 * @since 0.0.0
 */
const copyright = new NovaIdentity(workspaceDirectory).forDocs()['copyright'];

/**
 * Generate Locales - Copyright Years.
 *
 * Extracts the year range from the English copyright for the "{years}" placeholder in
 * the reviewed footer translations.
 *
 * @since 0.0.0
 */
const copyrightYears = copyright?.match(/\d{4}(?:-\d{4})?/)?.[0];

if (copyright === undefined || copyrightYears === undefined) {
  throw new Error('The nova.config.json identity must produce a copyright line with a year.');
}

/**
 * Generate Locales - Locale Copies.
 *
 * Loads the reviewed homepage, navbar, footer, blog, and sidebar translations, plus the
 * long-form installation and safety copy that the homepage summaries are drawn from.
 *
 * @since 0.0.0
 */
const localeCopies = JSON.parse(await readFile(
  join(workspaceDirectory, 'scripts', 'locale-copies.json'),
  'utf-8',
));

/**
 * Generate Locales - Runtime Translation Source.
 *
 * Reads the package-owned pfSense catalogs so homepage labels and reference pages use
 * the same reviewed terms as the installed WebGUI.
 *
 * @since 0.0.0
 */
const runtimeTranslationSource = await readFile(
  join(
    repositoryDirectory,
    'packages',
    'ipv6-prefix-updater',
    'port',
    'files',
    'usr',
    'local',
    'pkg',
    'ipv6-prefix-updater',
    'Translations.php',
  ),
  'utf-8',
);

/**
 * Generate Locales - Field Translation Source.
 *
 * Reads the package-owned Fields tab catalogs so the homepage card about choosing
 * fields uses the same reviewed terms as the tab itself.
 *
 * @since 0.0.0
 */
const fieldTranslationSource = await readFile(
  join(
    repositoryDirectory,
    'packages',
    'ipv6-prefix-updater',
    'port',
    'files',
    'usr',
    'local',
    'pkg',
    'ipv6-prefix-updater',
    'FieldTranslations.php',
  ),
  'utf-8',
);

/**
 * Generate Locales - Migration Translation Source.
 *
 * Reads the package-owned catalogs for the Monitored Interfaces rows, so homepage
 * labels describe one row per interface as the Settings tab does.
 *
 * @since 0.0.0
 */
const migrationTranslationSource = await readFile(
  join(
    repositoryDirectory,
    'packages',
    'ipv6-prefix-updater',
    'port',
    'files',
    'usr',
    'local',
    'pkg',
    'ipv6-prefix-updater',
    'MigrationTranslations.php',
  ),
  'utf-8',
);

/**
 * Generate Locales - Runtime Sources.
 *
 * Maps Docusaurus locale identifiers to the reviewed positional PHP catalogs and the
 * overrides the package applies after them. Regional variants retain their
 * package-specific overrides, and base catalogs keep their later corrections, so the
 * generated labels match the WebGUI.
 *
 * @since 0.0.0
 */
const runtimeSources = {
  'bs': [
    'bosnian',
    'bosnian',
  ],
  'zh-CN': ['chineseSimplified'],
  'zh-Hans-CN': ['chineseSimplified'],
  'zh-Hans-HK': ['chineseSimplified'],
  'zh-Hant-TW': ['chineseTraditional'],
  'nl-NL': [
    'dutch',
    'dutch',
  ],
  'fr-FR': [
    'french',
    'french',
  ],
  'de-DE': [
    'german',
    'german',
  ],
  'it-IT': [
    'italian',
    'italian',
  ],
  'ko-KR': [
    'korean',
    'korean',
  ],
  'nb-NO': [
    'norwegian',
    'norwegian',
  ],
  'pl-PL': [
    'polish',
    'polish',
  ],
  'pt-PT': ['portuguese'],
  'pt-BR': [
    'portuguese',
    'portugueseBrazil',
  ],
  'ru-RU': [
    'russian',
    'russian',
  ],
  'es-ES': ['spanish'],
  'es-AR': [
    'spanish',
    'spanishArgentina',
  ],
};

/**
 * Generate Locales - Keyed Sources.
 *
 * Maps Docusaurus locale identifiers to the catalog name suffixes shared by the keyed
 * Fields tab and migration catalogs, such as `$fieldGerman` and `$migrationGerman`.
 * Argentine Spanish applies its overrides on top of the Spanish catalog, as the package
 * does.
 *
 * @since 0.0.0
 */
const keyedSources = {
  'bs': ['Bosnian'],
  'zh-CN': ['ChineseSimplified'],
  'zh-Hans-CN': ['ChineseSimplified'],
  'zh-Hans-HK': ['ChineseSimplified'],
  'zh-Hant-TW': ['ChineseTraditional'],
  'nl-NL': ['Dutch'],
  'fr-FR': ['French'],
  'de-DE': ['German'],
  'it-IT': ['Italian'],
  'ko-KR': ['Korean'],
  'nb-NO': ['Norwegian'],
  'pl-PL': ['Polish'],
  'pt-PT': ['Portuguese'],
  'pt-BR': ['PortugueseBrazil'],
  'ru-RU': ['Russian'],
  'es-ES': ['Spanish'],
  'es-AR': [
    'Spanish',
    'SpanishArgentina',
  ],
};

/**
 * Generate Locales - Homepage Defaults.
 *
 * Mirrors every translated message emitted by the custom landing page. Exact English
 * values let Docusaurus omit redundant locale entries.
 *
 * @since 0.0.0
 */
const homepageDefaults = {
  'home.meta.description': 'Keep local IPv6 addresses in pfSense working when your ISP changes the delegated prefix.',
  'home.meta.title': 'pfSense IPv6 Prefix Updater - Safe Dynamic Prefix Updates',
  'home.hero.eyebrow': 'Native pfSense package',
  'home.hero.heading': 'Keep dynamic IPv6 prefixes aligned safely.',
  'home.hero.tagline': 'Keep local IPv6 addresses in pfSense working when your ISP changes the delegated prefix.',
  'home.hero.primaryAction': 'Install on pfSense',
  'home.hero.secondaryAction': 'Review safety behavior',
  'home.preview.ariaLabel': 'Example /56 prefix transition that preserves host bits',
  'home.preview.dryRun': 'Dry run',
  'home.preview.detected': 'Detected source',
  'home.preview.previous': 'Previous address',
  'home.preview.current': 'Planned address',
  'home.preview.prefixChanged': 'Prefix changed',
  'home.preview.hostPreserved': 'Host bits preserved',
  'home.preview.result': '2 approved fields planned · configuration unchanged',
  'home.workflow.eyebrow': 'What pfSense does',
  'home.workflow.heading': 'Follow every prefix change from detection to completion.',
  'home.workflow.detect': 'Detect new prefix',
  'home.workflow.plan': 'Find local addresses',
  'home.workflow.backup': 'Save a backup',
  'home.workflow.write': 'Update addresses',
  'home.workflow.verify': 'Refresh services',
  'home.workflow.notify': 'Report the result',
  'home.features.interface.title': 'Choose the Monitored Interfaces',
  'home.features.interface.description': 'Add a row for each pfSense interface whose public IPv6 address comes from an ISP delegation. Status shows the exact address in use for each one.',
  'home.features.prefix.title': 'Enter the ISP Prefix Length',
  'home.features.prefix.description': 'Enter the /48 through /64 prefix length supplied by the ISP. The device-specific portion of each address remains unchanged.',
  'home.features.scopes.title': 'Choose What Can Change',
  'home.features.scopes.description': 'Review the fields that follow the prefix on the Fields tab. pfSense groups start on, while settings from other packages, remote networks, peers, and secrets wait until you tick them.',
  'home.features.dryRun.title': 'Preview Without Changes',
  'home.features.dryRun.description': 'Dry Run shows the source address, old and new prefixes, affected-field count, and services without changing the firewall.',
  'home.features.backup.title': 'Back Up Before Changes',
  'home.features.backup.description': 'A real change creates one native pfSense configuration-history backup before any address is updated.',
  'home.features.notify.title': 'Notify After Success',
  'home.features.notify.description': 'Notifications are sent only after an update is checked. Routine checks stay quiet when the prefix has not changed.',
  'home.stats.heading': 'Safety You Can Verify',
  'home.stats.boundaries': 'Supported prefix boundaries',
  'home.stats.backups': 'Backup before each change',
  'home.stats.writes': 'Configuration write per change',
  'home.stats.noChange': 'Writes when the prefix is unchanged',
  'home.routes.eyebrow': 'Pick up where you are',
  'home.routes.heading': 'Follow the route for your next task.',
  'home.routes.install.title': 'Install the Package',
  'home.routes.install.description': 'Install the latest GitHub release with one pfSense shell command, then confirm the package in the WebGUI.',
  'home.routes.open': 'Open guide',
  'home.routes.configure.title': 'Select the Interface and Prefix',
  'home.routes.configure.description': 'Confirm which interface owns the global address and enter the delegated boundary supplied by the ISP.',
  'home.routes.status.title': 'Read Status and Command Output',
  'home.routes.status.description': 'Verify the chosen address, masked prefix, result, backup state, changed fields, and reloaded services after every run.',
  'home.routes.troubleshoot.title': 'Troubleshoot a Run',
  'home.routes.troubleshoot.description': 'Resolve wrong source addresses, zero-match warnings, failed safety stages, and recovery from pfSense configuration history.',
  'home.spotlight.heading': 'A run should never be a black box.',
  'home.spotlight.description': 'The Status tab and read-only command output show the exact source address, old and new prefixes, field count, backup result, service reloads, and final outcome.',
  'home.spotlight.statusAction': 'Read the Status Guide',
  'home.spotlight.testsAction': 'Check Before Automation',
  'home.blogPreview.heading': 'From the Blog',
  'home.blogPreview.description': 'Release notes, setup guides, and notes on dynamic IPv6 prefixes.',
};

/**
 * Generate Locales - Navbar Defaults.
 *
 * Matches the five configured navigation labels.
 *
 * @since 0.0.0
 */
const navbarDefaults = {
  'item.label.Home': 'Home',
  'item.label.Guides': 'Guides',
  'item.label.Safety': 'Safety',
  'item.label.Troubleshooting': 'Troubleshooting',
  'item.label.Blog': 'Blog',
};

/**
 * Generate Locales - Footer Defaults.
 *
 * Matches the configured footer headings, guide links, resources, call to action, and
 * copyright text.
 *
 * @since 0.0.0
 */
const footerDefaults = {
  'layout.learn.title': 'Guides',
  'layout.resources.title': 'Resources',
  'sections.learn.0.label': 'Install and configure',
  'sections.learn.1.label': 'Interface and prefix',
  'sections.learn.2.label': 'Status and actions',
  'sections.learn.3.label': 'Troubleshooting',
  'sections.resources.0.label': 'Safety model',
  'sections.resources.1.label': 'Check before automation',
  'sections.resources.2.label': 'pfSense documentation',
  'sections.resources.3.label': 'Source code',
  'sections.resources.4.label': 'Blog',
  copyright,
};

/**
 * Generate Locales - Blog Defaults.
 *
 * Matches the blog list heading and description configured in the theme so Nova
 * reconciliation keeps only the translated values.
 *
 * @since 0.0.0
 */
const blogDefaults = {
  'layout.heading': 'Blog',
  'layout.description': 'Release notes and setup guides',
};

/**
 * Generate Locales - Blog Plugin Defaults.
 *
 * Matches the blog plugin's own title, description, and sidebar title, which
 * Docusaurus reads from each locale's blog plugin options file.
 *
 * @since 0.0.0
 */
const blogPluginDefaults = {
  'title': 'Blog',
  'description': 'Release notes, setup guides, and notes on keeping pfSense addresses current when your IPv6 prefix changes.',
  'sidebar.title': 'Recent Posts',
};

/**
 * Generate Locales - Decode PHP String.
 *
 * Decodes the two escape sequences used by the reviewed single-quoted PHP catalogs.
 *
 * @param {string} value - Value.
 *
 * @returns {string}
 *
 * @since 0.0.0
 */
function decodePhpString(value) {
  return value
    .replaceAll('\\\'', '\'')
    .replaceAll('\\\\', '\\');
}

/**
 * Generate Locales - Extract Block.
 *
 * Returns one matched PHP array body and fails before generation if its expected source
 * shape changes.
 *
 * @param {RegExp} pattern  - Pattern.
 * @param {string} label    - Label.
 * @param {string} [source] - Source.
 *
 * @returns {string}
 *
 * @since 0.0.0
 */
function extractBlock(pattern, label, source = runtimeTranslationSource) {
  const match = source.match(pattern);

  if (match?.[1] === undefined) {
    throw new Error(`Unable to parse translation block "${label}".`);
  }

  return match[1];
}

/**
 * Generate Locales - Parse Associative Catalog.
 *
 * Parses a PHP array whose entries use explicit string keys.
 *
 * @param {string} block - Block.
 *
 * @returns {Record<string, string>}
 *
 * @since 0.0.0
 */
function parseAssociativeCatalog(block) {
  return Object.fromEntries(
    [...block.matchAll(/^\s*'((?:\\.|[^'])+)'\s*=>\s*'((?:\\.|[^'])*)',\s*$/gm)]
      .map((match) => [
        decodePhpString(match[1]),
        decodePhpString(match[2]),
      ]),
  );
}

/**
 * Generate Locales - Parse Positional Catalog.
 *
 * Parses one reviewed positional catalog in the exact English-key order enforced by
 * the package runtime.
 *
 * @param {string} block - Block.
 *
 * @returns {string[]}
 *
 * @since 0.0.0
 */
function parsePositionalCatalog(block) {
  return [...block.matchAll(/^\s*'((?:\\.|[^'])*)',\s*$/gm)]
    .map((match) => decodePhpString(match[1]));
}

/**
 * Generate Locales - Load Runtime Catalogs.
 *
 * Reconstructs the package's reviewed base and regional catalogs without executing PHP,
 * keeping locale generation portable across Node.js documentation builds.
 *
 * @returns {Record<string, Record<string, string>>}
 *
 * @since 0.0.0
 */
function loadRuntimeCatalogs() {
  const english = parseAssociativeCatalog(extractBlock(
    /\$english = \[(.*?)\n\];/s,
    'english',
  ));
  const englishKeys = Object.keys(english);
  const catalogs = {};

  for (const [
    locale,
    [
      baseName,
      overrideName,
    ],
  ] of Object.entries(runtimeSources)) {
    const baseValues = parsePositionalCatalog(extractBlock(
      new RegExp(`\\$${baseName} = \\$catalog\\(\\[(.*?)\\n\\]\\);`, 's'),
      baseName,
    ));

    if (baseValues.length !== englishKeys.length) {
      throw new Error(`Runtime locale "${locale}" has ${baseValues.length} values; expected ${englishKeys.length}.`);
    }

    const catalog = Object.fromEntries(
      englishKeys.map((key, index) => [
        key,
        baseValues[index],
      ]),
    );

    if (overrideName !== undefined) {
      Object.assign(catalog, parseAssociativeCatalog(extractBlock(
        new RegExp(`\\$${overrideName} = array_replace\\(\\$${baseName}, \\[(.*?)\\n\\]\\);`, 's'),
        overrideName,
      )));
    }

    catalogs[locale] = catalog;
  }

  return catalogs;
}

/**
 * Generate Locales - Load Keyed Catalogs.
 *
 * Reconstructs one keyed catalog file, such as the Fields tab or migration catalogs,
 * applying the Argentine Spanish overrides on top of the Spanish catalog.
 *
 * @param {string} source - Source.
 * @param {string} prefix - Prefix.
 *
 * @returns {Record<string, Record<string, string>>}
 *
 * @since 0.0.0
 */
function loadKeyedCatalogs(source, prefix) {
  const catalogs = {};

  for (const [
    locale,
    [
      baseSuffix,
      overrideSuffix,
    ],
  ] of Object.entries(keyedSources)) {
    const baseName = `${prefix}${baseSuffix}`;
    const catalog = parseAssociativeCatalog(extractBlock(
      new RegExp(`\\$${baseName} = \\[(.*?)\\n\\];`, 's'),
      baseName,
      source,
    ));

    if (overrideSuffix !== undefined) {
      const overrideName = `${prefix}${overrideSuffix}`;

      Object.assign(catalog, parseAssociativeCatalog(extractBlock(
        new RegExp(`\\$${overrideName} = array_replace\\(\\$${baseName}, \\[(.*?)\\n\\]\\);`, 's'),
        overrideName,
        source,
      )));
    }

    catalogs[locale] = catalog;
  }

  return catalogs;
}

/**
 * Generate Locales - Parse Markdown.
 *
 * Splits one reviewed legacy body into its translated opening and ordered H2 sections.
 *
 * @param {string} body - Body.
 *
 * @returns {{opening: string, sections: Array<{heading: string, body: string}>}}
 *
 * @since 0.0.0
 */
function parseMarkdown(body) {
  const parts = body.trim().split('\n## ');
  const opening = parts.shift().replace(/^# [^\n]+\n+/, '').trim();
  const sections = parts.map((part) => {
    const lineBreak = part.indexOf('\n');

    return {
      heading: part.slice(0, lineBreak).trim(),
      body: part.slice(lineBreak + 1).trim(),
    };
  });

  return {
    opening,
    sections,
  };
}

/**
 * Generate Locales - Strip Markdown.
 *
 * Reduces reviewed Markdown to plain text for localized metadata and compact homepage
 * summaries.
 *
 * @param {string} value - Value.
 *
 * @returns {string}
 *
 * @since 0.0.0
 */
function stripMarkdown(value) {
  return value
    .replaceAll(/:::.*$/gm, '')
    .replaceAll(/~~~[\s\S]*?~~~/g, '')
    .replaceAll(/\[([^\]]+)\]\([^)]+\)/g, '$1')
    .replaceAll(/[*_`#]/g, '')
    .replaceAll(/^\s*(?:\d+\.|-)\s+/gm, '')
    .replaceAll(/\s+/g, ' ')
    .trim();
}

/**
 * Generate Locales - First Sentence.
 *
 * Selects a compact translated summary while preserving scripts that use full-width
 * sentence punctuation.
 *
 * @param {string} value - Value.
 *
 * @returns {string}
 *
 * @since 0.0.0
 */
function firstSentence(value) {
  const plain = stripMarkdown(value);
  const match = plain.match(/^.*?[.!?。！？](?:\s|$)/u);

  return match?.[0]?.trim() ?? plain;
}

/**
 * Generate Locales - Format Message.
 *
 * Replaces the positional placeholders used by pfSense translations for a concrete
 * documentation example.
 *
 * @param {string}   message - Message.
 * @param {string[]} values  - Values.
 *
 * @returns {string}
 *
 * @since 0.0.0
 */
function formatMessage(message, values) {
  return message.replaceAll(/%(\d+)\$[ds]/g, (placeholder, position) => (
    values[Number(position) - 1] ?? placeholder
  ));
}

/**
 * Generate Locales - Build Catalog.
 *
 * Creates Docusaurus message records and omits translations that exactly match their
 * English defaults.
 *
 * @param {Record<string, string>} defaults     - Defaults.
 * @param {Record<string, string>} translations - Translations.
 *
 * @returns {Record<string, {message: string}>}
 *
 * @since 0.0.0
 */
function buildCatalog(defaults, translations) {
  const catalog = {};

  for (const [
    key,
    defaultValue,
  ] of Object.entries(defaults)) {
    const translatedValue = translations[key];

    if (translatedValue === undefined) {
      throw new Error(`Translation catalog is missing "${key}".`);
    }

    if (translatedValue !== defaultValue) {
      catalog[key] = { message: translatedValue };
    }
  }

  return catalog;
}

/**
 * Scripts - Generate Locales - Runtime catalogs.
 *
 * Stores this module-level value in one named location.
 * Related code reuses it instead of duplicating the definition.
 *
 * @since 0.0.0
 */
const runtimeCatalogs = loadRuntimeCatalogs();

/**
 * Scripts - Generate Locales - Field catalogs.
 *
 * Stores this module-level value in one named location.
 * Related code reuses it instead of duplicating the definition.
 *
 * @since 0.0.0
 */
const fieldCatalogs = loadKeyedCatalogs(fieldTranslationSource, 'field');

/**
 * Scripts - Generate Locales - Migration catalogs.
 *
 * Stores this module-level value in one named location.
 * Related code reuses it instead of duplicating the definition.
 *
 * @since 0.0.0
 */
const migrationCatalogs = loadKeyedCatalogs(migrationTranslationSource, 'migration');

for (const [
  locale,
  copy,
] of Object.entries(localeCopies)) {
  for (const requiredProperty of [
    'home',
    'navbar',
    'footer',
    'blog',
    'intro',
    'operations',
    'sidebar',
  ]) {
    if (Object.hasOwn(copy, requiredProperty) === false) {
      throw new Error(`Locale "${locale}" is missing "${requiredProperty}".`);
    }
  }

  const runtime = runtimeCatalogs[locale];

  if (runtime === undefined) {
    throw new Error(`Locale "${locale}" has no package runtime catalog.`);
  }

  const field = fieldCatalogs[locale];

  if (field === undefined) {
    throw new Error(`Locale "${locale}" has no package field catalog.`);
  }

  const migration = migrationCatalogs[locale];

  if (migration === undefined) {
    throw new Error(`Locale "${locale}" has no package migration catalog.`);
  }

  const introduction = parseMarkdown(copy['intro']['body']);
  const operations = parseMarkdown(copy['operations']['body']);
  const install = introduction.sections[1];
  const address = operations.sections[0];
  const transaction = operations.sections[1];
  const outcomes = operations.sections[2];
  const troubleshooting = operations.sections[3];

  if (install === undefined || address === undefined || transaction === undefined || outcomes === undefined || troubleshooting === undefined) {
    throw new Error(`Locale "${locale}" has an incomplete reviewed Markdown structure.`);
  }

  const homepageTranslations = {
    'home.meta.description': copy['home'][2],
    'home.meta.title': `${runtime['brand']} - ${copy['home'][1]}`,
    'home.hero.eyebrow': copy['home'][0],
    'home.hero.heading': copy['home'][1],
    'home.hero.tagline': copy['home'][2],
    'home.hero.primaryAction': copy['home'][3],
    'home.hero.secondaryAction': copy['home'][4],
    'home.preview.ariaLabel': `${runtime['delegated_prefix_length']}: /56`,
    'home.preview.dryRun': runtime['status_dry_run'],
    'home.preview.detected': runtime['observed_source_address'],
    'home.preview.previous': runtime['cli_previous_prefix'],
    'home.preview.current': runtime['cli_current_prefix'],
    'home.preview.prefixChanged': runtime['observed_prefix'],
    'home.preview.hostPreserved': runtime['delegated_prefix_length'],
    'home.preview.result': formatMessage(runtime['dry_run_changes'], [
      '2',
      '2001:db8:abcd:8800::',
      '56',
    ]),
    'home.workflow.eyebrow': copy['operations']['title'],
    'home.workflow.heading': transaction.heading,
    'home.workflow.detect': runtime['observed_source_address'],
    'home.workflow.plan': runtime['changed_fields'],
    'home.workflow.backup': runtime['configuration_backup_created'],
    'home.workflow.write': runtime['cli_current_prefix'],
    'home.workflow.verify': runtime['status_success'],
    'home.workflow.notify': runtime['notifications'],
    'home.features.interface.title': migration['monitored_interfaces'],
    'home.features.interface.description': migration['monitored_interfaces_help'],
    'home.features.prefix.title': runtime['delegated_prefix_length'],
    'home.features.prefix.description': runtime['delegated_prefix_length_help'],
    'home.features.scopes.title': field['fields'],
    'home.features.scopes.description': field['fields_settings_help'],
    'home.features.dryRun.title': runtime['dry_run'],
    'home.features.dryRun.description': runtime['run_help'],
    'home.features.backup.title': runtime['configuration_backup_created'],
    'home.features.backup.description': `${runtime['configuration_backup_created']}: ${runtime['yes']}.`,
    'home.features.notify.title': runtime['notifications'],
    'home.features.notify.description': runtime['send_change_notifications_help'],
    'home.stats.heading': copy['operations']['title'],
    'home.stats.boundaries': runtime['delegated_prefix_length'],
    'home.stats.backups': runtime['configuration_backup_created'],
    'home.stats.writes': runtime['changed_fields'],
    'home.stats.noChange': runtime['no_change_message'],
    'home.routes.eyebrow': copy['footer'][0],
    'home.routes.heading': copy['intro']['title'],
    'home.routes.install.title': install.heading,
    'home.routes.install.description': firstSentence(install.body),
    'home.routes.open': copy['footer'][7],
    'home.routes.configure.title': `${migration['monitored_interfaces']} / ${runtime['delegated_prefix_length']}`,
    'home.routes.configure.description': migration['monitored_interfaces_help'],
    'home.routes.status.title': `${runtime['status']} / ${runtime['command_output']}`,
    'home.routes.status.description': runtime['run_help'],
    'home.routes.troubleshoot.title': troubleshooting.heading,
    'home.routes.troubleshoot.description': firstSentence(troubleshooting.body),
    'home.spotlight.heading': runtime['command_output'],
    'home.spotlight.description': operations.opening,
    'home.spotlight.statusAction': `${runtime['status']} / ${runtime['command_output']}`,
    'home.spotlight.testsAction': outcomes.heading,
    'home.blogPreview.heading': copy['home'][5],
    'home.blogPreview.description': copy['home'][6],
  };
  const navbarTranslations = {
    'item.label.Home': copy['navbar'][0],
    'item.label.Guides': copy['navbar'][1],
    'item.label.Safety': copy['navbar'][2],
    'item.label.Troubleshooting': copy['footer'][4],
    'item.label.Blog': copy['navbar'][3],
  };
  const footerTranslations = {
    'layout.learn.title': copy['footer'][0],
    'layout.resources.title': copy['footer'][1],
    'sections.learn.0.label': copy['intro']['title'],
    'sections.learn.1.label': `${migration['monitored_interfaces']} / ${runtime['delegated_prefix_length']}`,
    'sections.learn.2.label': `${runtime['status']} / ${runtime['actions']}`,
    'sections.learn.3.label': copy['footer'][4],
    'sections.resources.0.label': copy['operations']['title'],
    'sections.resources.1.label': outcomes.heading,
    'sections.resources.2.label': copy['footer'][5],
    'sections.resources.3.label': copy['footer'][6],
    'sections.resources.4.label': copy['footer'][9],
    'copyright': copy['footer'][8].replace('{years}', copyrightYears),
  };
  const blogTranslations = {
    'layout.heading': copy['blog'][0],
    'layout.description': copy['blog'][1],
  };
  const blogPluginTranslations = {
    'title': copy['blog'][2],
    'description': copy['blog'][3],
    'sidebar.title': copy['blog'][4],
  };
  const sidebarCatalog = {
    'sidebar.docs.category.Start Here': { message: copy['sidebar']['Start Here'] },
    'sidebar.docs.category.Configure': { message: copy['sidebar']['Configure'] },
    'sidebar.docs.category.Operate': { message: copy['sidebar']['Operate'] },
    'sidebar.docs.category.Safety': { message: copy['sidebar']['Safety'] },
    'sidebar.docs.category.Reference': { message: copy['sidebar']['Reference'] },
    'sidebar.docs.category.Contributing': { message: copy['sidebar']['Contributing'] },
  };
  const localeDirectory = join(workspaceDirectory, 'i18n', locale);
  const themeDirectory = join(localeDirectory, 'docusaurus-theme-nova');
  const docsPluginDirectory = join(localeDirectory, 'docusaurus-plugin-content-docs');
  const blogPluginDirectory = join(localeDirectory, 'docusaurus-plugin-content-blog');

  await mkdir(themeDirectory, { recursive: true });
  await mkdir(docsPluginDirectory, { recursive: true });
  await mkdir(blogPluginDirectory, { recursive: true });

  await writeFile(
    join(localeDirectory, 'code.json'),
    `${JSON.stringify(buildCatalog(homepageDefaults, homepageTranslations), null, 2)}\n`,
  );
  await writeFile(
    join(themeDirectory, 'navbar.json'),
    `${JSON.stringify(buildCatalog(navbarDefaults, navbarTranslations), null, 2)}\n`,
  );
  await writeFile(
    join(themeDirectory, 'footer.json'),
    `${JSON.stringify(buildCatalog(footerDefaults, footerTranslations), null, 2)}\n`,
  );
  await writeFile(
    join(docsPluginDirectory, 'current.json'),
    `${JSON.stringify(sidebarCatalog, null, 2)}\n`,
  );

  const blogCatalog = buildCatalog(blogDefaults, blogTranslations);
  const blogPath = join(themeDirectory, 'blog.json');

  if (Object.keys(blogCatalog).length > 0) {
    await writeFile(blogPath, `${JSON.stringify(blogCatalog, null, 2)}\n`);
  } else {
    await rm(blogPath, { force: true });
  }

  const blogPluginCatalog = buildCatalog(blogPluginDefaults, blogPluginTranslations);
  const blogPluginPath = join(blogPluginDirectory, 'options.json');

  if (Object.keys(blogPluginCatalog).length > 0) {
    await writeFile(blogPluginPath, `${JSON.stringify(blogPluginCatalog, null, 2)}\n`);
  } else {
    await rm(blogPluginPath, { force: true });
  }
}

process.stdout.write(
  `Generated interface catalogs for ${Object.keys(localeCopies).length} locales. Translated docs pages and blog posts in each locale's plugin folders are left in place.\n`,
);
