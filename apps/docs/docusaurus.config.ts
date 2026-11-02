import { NovaIdentity } from '@cbnventures/nova/toolkit';

import type { DocusaurusNovaConfig } from '@cbnventures/docusaurus-preset-nova/types/config';

/**
 * Docusaurus - Identity.
 *
 * Reads the documentation identity from the generated Nova configuration.
 * Fallbacks keep the site runnable while consumers finish project metadata.
 *
 * @since 0.0.0
 */
const identity = new NovaIdentity().forDocs();

/**
 * Docusaurus - Config.
 *
 * Configures the generated documentation site with Nova's selected preset.
 * Consumers can extend these values through normal Docusaurus configuration.
 *
 * @since 0.0.0
 */
const config: DocusaurusNovaConfig = {
  title: identity['title'] ?? '',
  titleDelimiter: '-',
  tagline: identity['tagline'] ?? 'Safe dynamic IPv6 prefix updates for pfSense',
  url: identity['url'] ?? 'https://www.example.com',
  baseUrl: identity['baseUrl'] ?? '/',
  favicon: './favicon.ico',
  organizationName: identity['organizationName'] ?? 'your-org',
  projectName: identity['projectName'] ?? 'pfsense-ipv6-prefix-updater',
  trailingSlash: true,
  noIndex: false,
  onBrokenLinks: 'throw',
  stylesheets: [`${identity['baseUrl'] ?? '/'}css/site.css`],

  headTags: [
    {
      tagName: 'link',
      attributes: {
        rel: 'apple-touch-icon',
        sizes: '180x180',
        href: `${identity['baseUrl'] ?? '/'}apple-touch-icon.png`,
      },
    },
    {
      tagName: 'link',
      attributes: {
        rel: 'icon',
        type: 'image/png',
        sizes: '96x96',
        href: `${identity['baseUrl'] ?? '/'}favicon-96x96.png`,
      },
    },
    {
      tagName: 'link',
      attributes: {
        rel: 'icon',
        type: 'image/svg+xml',
        href: `${identity['baseUrl'] ?? '/'}favicon.svg`,
      },
    },
    {
      tagName: 'link',
      attributes: {
        rel: 'manifest',
        href: `${identity['baseUrl'] ?? '/'}site.webmanifest`,
      },
    },
  ],

  markdown: {
    hooks: {
      onBrokenMarkdownLinks: 'warn',
    },
  },

  i18n: {
    defaultLocale: 'en-US',
    locales: [
      'bs',
      'zh-CN',
      'zh-Hans-CN',
      'zh-Hans-HK',
      'zh-Hant-TW',
      'nl-NL',
      'en-US',
      'fr-FR',
      'de-DE',
      'it-IT',
      'ko-KR',
      'nb-NO',
      'pl-PL',
      'pt-PT',
      'pt-BR',
      'ru-RU',
      'es-ES',
      'es-AR',
    ],
    localeConfigs: {
      'bs': {
        label: 'Bosnian',
        htmlLang: 'bs',
      },
      'zh-CN': {
        label: 'Chinese',
        htmlLang: 'zh-CN',
      },
      'zh-Hans-CN': {
        label: 'Chinese (Simplified, China)',
        htmlLang: 'zh-Hans-CN',
      },
      'zh-Hans-HK': {
        label: 'Chinese (Simplified, Hong Kong SAR China)',
        htmlLang: 'zh-Hans-HK',
      },
      'zh-Hant-TW': {
        label: 'Chinese (Traditional, Taiwan)',
        htmlLang: 'zh-Hant-TW',
      },
      'nl-NL': {
        label: 'Dutch',
        htmlLang: 'nl-NL',
      },
      'en-US': {
        label: 'English',
        htmlLang: 'en-US',
      },
      'fr-FR': {
        label: 'French',
        htmlLang: 'fr-FR',
      },
      'de-DE': {
        label: 'German (Germany)',
        htmlLang: 'de-DE',
      },
      'it-IT': {
        label: 'Italian',
        htmlLang: 'it-IT',
      },
      'ko-KR': {
        label: 'Korean',
        htmlLang: 'ko-KR',
      },
      'nb-NO': {
        label: 'Norwegian Bokmål',
        htmlLang: 'nb-NO',
      },
      'pl-PL': {
        label: 'Polish',
        htmlLang: 'pl-PL',
      },
      'pt-PT': {
        label: 'Portuguese',
        htmlLang: 'pt-PT',
      },
      'pt-BR': {
        label: 'Portuguese (Brazil)',
        htmlLang: 'pt-BR',
      },
      'ru-RU': {
        label: 'Russian',
        htmlLang: 'ru-RU',
      },
      'es-ES': {
        label: 'Spanish',
        htmlLang: 'es-ES',
      },
      'es-AR': {
        label: 'Spanish (Argentina)',
        htmlLang: 'es-AR',
      },
    },
  },

  presets: [[
    '@cbnventures/docusaurus-preset-nova',
    {
      preset: 'sentinel',
      overrides: {
        colors: {
          primary: {
            light: '#0f766e',
            dark: '#2dd4bf',
          },
          secondary: {
            light: '#b45309',
            dark: '#fbbf24',
          },
        },
      },
      plugins: {
        blog: {
          showReadingTime: true,
          blogTitle: 'Blog',
          blogDescription: 'Release notes, setup guides, and notes on keeping pfSense addresses current when your IPv6 prefix changes.',
          postsPerPage: 10,
          blogSidebarTitle: 'Recent Posts',
          blogSidebarCount: 15,
          feedOptions: {
            type: [
              'rss',
              'atom',
            ],
            copyright: identity['copyright'],
            limit: 20,
            title: 'pfSense IPv6 Prefix Updater Blog',
            description: 'Release notes, setup guides, and notes on keeping pfSense addresses current when your IPv6 prefix changes.',
          },
        },
        docs: {
          sidebarPath: './sidebars.ts',
          routeBasePath: 'docs',
        },
        sitemap: {
          lastmod: 'date',
          changefreq: 'weekly',
          priority: 0.5,
          ignorePatterns: ['**/docs/tags/**'],
          filename: 'sitemap.xml',
        },
      },
      search: {},
    },
  ]],

  themeConfig: {
    site: {
      logo: {
        alt: 'pfSense IPv6 Prefix Updater',
        src: {
          light: `${identity['baseUrl'] ?? '/'}images/navbar.svg`,
          dark: undefined,
        },
        href: '/',
      },
      image: '/thumbnails/brand.png',
      metadata: [
        {
          name: 'description',
          content: identity['metaDescription'] ?? '',
        },
        {
          name: 'twitter:card',
          content: 'summary_large_image',
        },
      ],
    },
    navbar: {
      title: 'IPv6 Prefix Updater',
      items: [
        {
          label: 'Home',
          position: 'left',
          to: '/',
          icon: 'lucide:house',
        },
        {
          label: 'Guides',
          position: 'left',
          type: 'docSidebar',
          sidebarId: 'docs',
          icon: 'lucide:book-open',
        },
        {
          label: 'Safety',
          position: 'left',
          to: '/docs/operations',
          icon: 'lucide:shield-check',
        },
        {
          label: 'Troubleshooting',
          position: 'left',
          to: '/docs/operate/troubleshooting',
          icon: 'lucide:life-buoy',
        },
        {
          label: 'Blog',
          position: 'left',
          to: '/blog',
          icon: 'lucide:pen-line',
        },
      ],
    },
    blog: {
      sidebar: {
        groupByYear: true,
      },
      layout: {
        heading: 'Blog',
        description: 'Release notes and setup guides',
      },
      share: {
        platforms: [
          'linkedin',
          'reddit',
          'facebook',
          'x',
          'copy',
        ],
      },
    },
    errorPages: {
      notFound: {
        title: 'This page was not found.',
        description: 'The link may be out of date, or the page may have moved.',
        backHomeLabel: 'Go to the overview',
        backHomeHref: '/docs/intro/',
      },
      errorPageContent: {
        title: 'This page could not be loaded.',
        retryLabel: 'Reload the page',
      },
      error: {
        retryLabel: 'Try again',
      },
    },
    footer: {
      socialLinks: [],
      sections: {
        learn: [
          {
            label: 'Install and configure',
            href: '/docs/installation',
          },
          {
            label: 'Interface and prefix',
            href: '/docs/configure/interface-and-prefix',
          },
          {
            label: 'Status and actions',
            href: '/docs/operate/status-and-actions',
          },
          {
            label: 'Troubleshooting',
            href: '/docs/operate/troubleshooting',
          },
        ],
        resources: [
          {
            label: 'Safety model',
            href: '/docs/operations',
          },
          {
            label: 'Check before automation',
            href: '/docs/safety/testing-and-verification',
          },
          {
            label: 'pfSense documentation',
            href: 'https://docs.netgate.com/pfsense/en/latest/',
          },
          {
            label: 'Source code',
            href: 'https://github.com/mrjackyliang/pfsense-ipv6-prefix-updater',
          },
          {
            label: 'Blog',
            href: '/blog',
          },
        ],
      },
      layout: {
        learn: {
          title: 'Guides',
          section: 'learn',
        },
        resources: {
          title: 'Resources',
          section: 'resources',
        },
      },
      copyright: identity['copyright'],
      credit: true,
    },
  },
};

export default config;
