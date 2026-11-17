import type { LoadContext, Plugin } from '@docusaurus/types';

/**
 * Plugins - Locale Docs Assets - Locale Docs Assets.
 *
 * @since 0.0.0
 */
export type Plugins_LocaleDocsAssets_LocaleDocsAssets_Context = LoadContext;

export type Plugins_LocaleDocsAssets_LocaleDocsAssets_Returns = Plugin;

export type Plugins_LocaleDocsAssets_LocaleDocsAssets_DocsDirectory = string;

/**
 * Plugins - Locale Docs Assets - Locale Docs Assets - Configure Webpack.
 *
 * @since 0.0.0
 */
export type Plugins_LocaleDocsAssets_LocaleDocsAssets_ConfigureWebpack_ReturnsResolveAlias = Record<string, string>;

export type Plugins_LocaleDocsAssets_LocaleDocsAssets_ConfigureWebpack_ReturnsResolve = {
  alias: Plugins_LocaleDocsAssets_LocaleDocsAssets_ConfigureWebpack_ReturnsResolveAlias;
};

export type Plugins_LocaleDocsAssets_LocaleDocsAssets_ConfigureWebpack_Returns = {
  resolve: Plugins_LocaleDocsAssets_LocaleDocsAssets_ConfigureWebpack_ReturnsResolve;
};
