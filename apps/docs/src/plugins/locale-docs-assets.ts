import type {
  Plugins_LocaleDocsAssets_LocaleDocsAssets_ConfigureWebpack_Returns,
  Plugins_LocaleDocsAssets_LocaleDocsAssets_Context,
  Plugins_LocaleDocsAssets_LocaleDocsAssets_DocsDirectory,
  Plugins_LocaleDocsAssets_LocaleDocsAssets_Returns,
} from '../types/plugins/locale-docs-assets.d.ts';

/**
 * Plugins - Locale Docs Assets - Locale Docs Assets.
 *
 * Points the "@locale-docs" import alias at the docs folder of the locale being
 * built, so the homepage shows the WebGUI screenshots in that language.
 *
 * @param {Plugins_LocaleDocsAssets_LocaleDocsAssets_Context} context - Context.
 *
 * @returns {Plugins_LocaleDocsAssets_LocaleDocsAssets_Returns}
 *
 * @since 0.0.0
 */
function localeDocsAssets(context: Plugins_LocaleDocsAssets_LocaleDocsAssets_Context): Plugins_LocaleDocsAssets_LocaleDocsAssets_Returns {
  const docsDirectory: Plugins_LocaleDocsAssets_LocaleDocsAssets_DocsDirectory = (context.i18n.currentLocale === context.i18n.defaultLocale) ? `${context.siteDir}/docs` : `${context.siteDir}/i18n/${context.i18n.currentLocale}/docusaurus-plugin-content-docs/current`;

  return {
    name: 'locale-docs-assets',
    configureWebpack(): Plugins_LocaleDocsAssets_LocaleDocsAssets_ConfigureWebpack_Returns {
      return {
        resolve: {
          alias: {
            '@locale-docs': docsDirectory,
          },
        },
      };
    },
  };
}

export default localeDocsAssets;
