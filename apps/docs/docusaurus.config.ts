import { NovaIdentity } from '@cbnventures/nova/toolkit';

import type { DocusaurusNovaConfig } from '@cbnventures/docusaurus-preset-nova/types/config';

/**
 * Identity.
 *
 * @since 0.0.0
 */
const identity = new NovaIdentity().forDocs();

/**
 * Docusaurus Configuration.
 *
 * @since 0.0.0
 */
const config: DocusaurusNovaConfig = {
  // Site Metadata.
  title: identity['title'] ?? '',
  titleDelimiter: '-',
  tagline: identity['tagline'],
  favicon: './favicon.ico',

  // Deployment.
  url: identity['url'] ?? '',
  baseUrl: identity['baseUrl'] ?? '/',
  organizationName: identity['organizationName'],
  projectName: identity['projectName'],
  deploymentBranch: undefined,
  githubHost: undefined,
  githubPort: undefined,
  trailingSlash: true,
  noIndex: false,

  // Error Handling.
  onBrokenLinks: 'throw',
  onBrokenAnchors: 'throw',
  onDuplicateRoutes: 'throw',

  // Static Assets.
  staticDirectories: ['static'],
  baseUrlIssueBanner: true,
  scripts: [],
  stylesheets: [`${identity['baseUrl'] ?? '/'}css/site.css`],
  clientModules: [],
  themes: [],

  // Custom Fields.
  customFields: {},

  // Head Tags.
  headTags: [
    {
      tagName: 'meta',
      attributes: {
        property: 'og:type',
        content: 'website',
      },
    },
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

  // Internationalization.
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
    path: 'i18n',
    localeConfigs: {
      'bs': {
        label: 'Bosnian',
        direction: 'ltr',
        htmlLang: 'bs',
        calendar: 'gregory',
        path: 'bs',
      },
      'zh-CN': {
        label: 'Chinese',
        direction: 'ltr',
        htmlLang: 'zh-CN',
        calendar: 'gregory',
        path: 'zh-CN',
      },
      'zh-Hans-CN': {
        label: 'Chinese (Simplified, China)',
        direction: 'ltr',
        htmlLang: 'zh-Hans-CN',
        calendar: 'gregory',
        path: 'zh-Hans-CN',
      },
      'zh-Hans-HK': {
        label: 'Chinese (Simplified, Hong Kong SAR China)',
        direction: 'ltr',
        htmlLang: 'zh-Hans-HK',
        calendar: 'gregory',
        path: 'zh-Hans-HK',
      },
      'zh-Hant-TW': {
        label: 'Chinese (Traditional, Taiwan)',
        direction: 'ltr',
        htmlLang: 'zh-Hant-TW',
        calendar: 'gregory',
        path: 'zh-Hant-TW',
      },
      'nl-NL': {
        label: 'Dutch',
        direction: 'ltr',
        htmlLang: 'nl-NL',
        calendar: 'gregory',
        path: 'nl-NL',
      },
      'en-US': {
        label: 'English',
        direction: 'ltr',
        htmlLang: 'en-US',
        calendar: 'gregory',
        path: 'en-US',
      },
      'fr-FR': {
        label: 'French',
        direction: 'ltr',
        htmlLang: 'fr-FR',
        calendar: 'gregory',
        path: 'fr-FR',
      },
      'de-DE': {
        label: 'German (Germany)',
        direction: 'ltr',
        htmlLang: 'de-DE',
        calendar: 'gregory',
        path: 'de-DE',
      },
      'it-IT': {
        label: 'Italian',
        direction: 'ltr',
        htmlLang: 'it-IT',
        calendar: 'gregory',
        path: 'it-IT',
      },
      'ko-KR': {
        label: 'Korean',
        direction: 'ltr',
        htmlLang: 'ko-KR',
        calendar: 'gregory',
        path: 'ko-KR',
      },
      'nb-NO': {
        label: 'Norwegian Bokmål',
        direction: 'ltr',
        htmlLang: 'nb-NO',
        calendar: 'gregory',
        path: 'nb-NO',
      },
      'pl-PL': {
        label: 'Polish',
        direction: 'ltr',
        htmlLang: 'pl-PL',
        calendar: 'gregory',
        path: 'pl-PL',
      },
      'pt-PT': {
        label: 'Portuguese',
        direction: 'ltr',
        htmlLang: 'pt-PT',
        calendar: 'gregory',
        path: 'pt-PT',
      },
      'pt-BR': {
        label: 'Portuguese (Brazil)',
        direction: 'ltr',
        htmlLang: 'pt-BR',
        calendar: 'gregory',
        path: 'pt-BR',
      },
      'ru-RU': {
        label: 'Russian',
        direction: 'ltr',
        htmlLang: 'ru-RU',
        calendar: 'gregory',
        path: 'ru-RU',
      },
      'es-ES': {
        label: 'Spanish',
        direction: 'ltr',
        htmlLang: 'es-ES',
        calendar: 'gregory',
        path: 'es-ES',
      },
      'es-AR': {
        label: 'Spanish (Argentina)',
        direction: 'ltr',
        htmlLang: 'es-AR',
        calendar: 'gregory',
        path: 'es-AR',
      },
    },
  },

  // Markdown.
  markdown: {
    format: 'mdx',
    mermaid: true,
    emoji: true,
    mdx1Compat: {
      comments: true,
      admonitions: true,
      headingIds: true,
    },
    anchors: {
      maintainCase: false,
    },
    hooks: {
      onBrokenMarkdownLinks: 'warn',
      onBrokenMarkdownImages: 'throw',
    },
  },

  // Presets (Nova).
  presets: [[
    '@cbnventures/docusaurus-preset-nova',
    {
      // Preset Identity.
      preset: 'sentinel',

      // Preset Overrides (undefined = use preset default).
      overrides: {
        colors: {
          primary: {
            light: '#0F766E',
            dark: '#2DD4BF',
          },
          secondary: {
            light: '#B45309',
            dark: '#FBBF24',
          },
          text: undefined,
          border: undefined,
          warning: undefined,
          danger: undefined,
        },
        fonts: {
          display: undefined,
          body: undefined,
          code: undefined,
        },
        navbar: undefined,
        footer: undefined,
      },

      // Plugins.
      plugins: {
        docs: {
          routeBasePath: 'docs',
          sidebarPath: './sidebars.ts',
          editUrl: 'https://github.com/mrjackyliang/pfsense-ipv6-prefix-updater/tree/main/apps/docs/',
          showLastUpdateTime: true,
          showLastUpdateAuthor: true,
          lastVersion: 'current',
          versions: {
            current: {
              label: 'Current',
              badge: false,
              banner: 'none',
            },
          },
        },
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
            title: 'IPv6 Prefix Updater for pfSense Blog',
            description: 'Release notes, setup guides, and notes on keeping pfSense addresses current when your IPv6 prefix changes.',
          },
        },
        pages: {
          editUrl: 'https://github.com/mrjackyliang/pfsense-ipv6-prefix-updater/tree/main/apps/docs/',
          showLastUpdateTime: true,
          showLastUpdateAuthor: true,
        },
        sitemap: {
          lastmod: 'date',
          changefreq: 'weekly',
          priority: 0.5,
          ignorePatterns: ['**/docs/tags/**'],
          filename: 'sitemap.xml',
        },
      },

      // Analytics.
      analytics: {
        gtm: {
          containerId: 'GTM-NJN3C25S',
        },
      },

      // Search.
      search: {
        indexDocs: true,
        indexBlog: true,
        indexPages: true,
        docsRouteBasePath: 'docs',
        hashed: true,
        language: ['en'],
        highlightSearchTermsOnTargetPage: true,
        searchBarShortcutKeymap: 'mod+k',
        searchResultLimits: 8,
        fuzzyMatchingDistance: 1,
        ignorePatterns: [],
      },

      // Progress Bar.
      progressBar: true,
    },
  ]],

  // Plugins.
  plugins: ['./src/plugins/locale-docs-assets.ts'],

  // Theme Config.
  themeConfig: {
    // Site.
    site: {
      logo: {
        alt: 'IPv6 Prefix Updater for pfSense',
        src: {
          light: `${identity['baseUrl'] ?? '/'}images/navbar.svg`,
          dark: undefined,
        },
        href: '/',
        target: undefined,
        rel: undefined,
        ariaLabel: undefined,
        wordmark: {
          light: undefined,
          dark: undefined,
        },
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

    // Color Mode.
    colorMode: {
      defaultMode: 'system',
      disableSwitch: false,
    },

    // Navbar.
    navbar: {
      hideOnScroll: false,
      items: [
        {
          label: 'Docs',
          position: 'left',
          type: 'dropdown',
          icon: 'lucide:book-open',
          items: [
            {
              label: 'Overview',
              to: '/docs/intro/',
              icon: 'lucide:rocket',
              activeBaseRegex: '^(/[A-Za-z-]+)?/docs/(intro|installation|first-run|compatibility|migrate-from-legacy|tags)/',
            },
            {
              label: 'Configure',
              to: '/docs/configure/interface-and-prefix/',
              icon: 'lucide:settings',
              activeBaseRegex: '^(/[A-Za-z-]+)?/docs/configure/',
            },
            {
              label: 'Operate',
              to: '/docs/operate/status-and-actions/',
              icon: 'lucide:activity',
              activeBaseRegex: '^(/[A-Za-z-]+)?/docs/operate/',
            },
            {
              label: 'Safety',
              to: '/docs/operations/',
              icon: 'lucide:shield-check',
              activeBaseRegex: '^(/[A-Za-z-]+)?/docs/(operations|safety)/',
            },
            {
              label: 'Reference',
              to: '/docs/reference/terminology/',
              icon: 'lucide:library',
              activeBaseRegex: '^(/[A-Za-z-]+)?/docs/reference/',
            },
            {
              label: 'Contributing',
              to: '/docs/contributing/build-and-release/',
              icon: 'lucide:git-pull-request',
              activeBaseRegex: '^(/[A-Za-z-]+)?/docs/contributing/',
            },
          ],
        },
        {
          label: 'Quick Start',
          position: 'left',
          type: undefined,
          to: '/docs/installation/',
          icon: 'lucide:play',
        },
        {
          label: 'Blog',
          position: 'left',
          type: undefined,
          to: '/blog/',
          icon: 'lucide:pen-line',
        },
        {
          label: 'Resources',
          position: 'left',
          type: 'dropdown',
          icon: 'lucide:life-buoy',
          items: [
            {
              label: 'Troubleshooting',
              to: '/docs/operate/troubleshooting/',
              icon: 'lucide:wrench',
            },
            {
              label: 'Changelog',
              href: 'https://github.com/mrjackyliang/pfsense-ipv6-prefix-updater/releases',
              icon: 'lucide:history',
            },
            {
              label: 'Report an Issue',
              href: 'https://github.com/mrjackyliang/pfsense-ipv6-prefix-updater/issues',
              icon: 'lucide:bug',
            },
          ],
        },
        {
          type: 'localeDropdown',
          position: 'right',
        },
      ],
    },

    // Docs.
    docs: {
      versionPersistence: 'localStorage',
      sidebar: {
        hideable: false,
        autoCollapseCategories: false,
      },
    },

    // Blog.
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

    // Table of Contents.
    tableOfContents: {
      minHeadingLevel: 2,
      maxHeadingLevel: 3,
    },

    // Announcement Bar.
    announcementBar: undefined,

    // Back to Top Button.
    backToTopButton: true,

    // Error Pages - overrides Nova's randomized defaults with consumer copy.
    // Any unset field falls through to the Nova-flavored randomized pool.
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

    // Footer.
    footer: {
      sections: {
        docs: [
          {
            label: 'Overview',
            href: '/docs/intro/',
          },
          {
            label: 'Configure',
            href: '/docs/configure/interface-and-prefix/',
          },
          {
            label: 'Operate',
            href: '/docs/operate/status-and-actions/',
          },
          {
            label: 'Safety',
            href: '/docs/operations/',
          },
          {
            label: 'Reference',
            href: '/docs/reference/terminology/',
          },
          {
            label: 'Contributing',
            href: '/docs/contributing/build-and-release/',
          },
        ],
        resources: [
          {
            label: 'Blog',
            href: '/blog/',
          },
          {
            label: 'Troubleshooting',
            href: '/docs/operate/troubleshooting/',
          },
          {
            label: 'Changelog',
            href: 'https://github.com/mrjackyliang/pfsense-ipv6-prefix-updater/releases',
          },
          {
            label: 'Report an Issue',
            href: 'https://github.com/mrjackyliang/pfsense-ipv6-prefix-updater/issues',
          },
          {
            label: 'Source Code',
            href: 'https://github.com/mrjackyliang/pfsense-ipv6-prefix-updater',
          },
        ],
        project: [
          {
            label: 'Sponsor',
            href: 'https://github.com/sponsors/mrjackyliang',
          },
          {
            label: 'pfSense Documentation',
            href: 'https://docs.netgate.com/pfsense/en/latest/',
          },
          {
            label: 'Other Projects',
            href: 'https://www.mrjackyliang.com/work/',
          },
        ],
        legal: [{
          label: 'License',
          href: 'https://github.com/mrjackyliang/pfsense-ipv6-prefix-updater/blob/HEAD/LICENSE',
        }],
      },
      layout: {
        docs: {
          title: 'Docs',
          section: 'docs',
        },
        resources: {
          title: 'Resources',
          section: 'resources',
        },
        project: {
          title: 'Project',
          section: 'project',
        },
        legal: {
          title: 'Legal',
          section: 'legal',
        },
      },
      socialLinks: [
        {
          icon: 'ri:facebook-fill',
          href: 'https://www.facebook.com/jackyliangofficial',
          label: 'Facebook',
        },
        {
          icon: 'ri:twitter-x-fill',
          href: 'https://x.com/mrjackyliang',
          label: 'X',
        },
        {
          icon: 'ri:youtube-fill',
          href: 'https://www.youtube.com/@mrjackyliang',
          label: 'YouTube',
        },
        {
          icon: 'ri:instagram-fill',
          href: 'https://www.instagram.com/mrjackyliang/',
          label: 'Instagram',
        },
        {
          icon: 'ri:github-fill',
          href: 'https://github.com/mrjackyliang',
          label: 'GitHub',
        },
        {
          icon: 'ri:linkedin-fill',
          href: 'https://www.linkedin.com/in/mrjackyliang/',
          label: 'LinkedIn',
        },
        {
          icon: 'ri:reddit-fill',
          href: 'https://www.reddit.com/user/mrjackyliang/',
          label: 'Reddit',
        },
        {
          icon: 'ri:heart-fill',
          href: 'https://github.com/sponsors/mrjackyliang',
          label: 'GitHub Sponsors',
        },
      ],
      copyright: identity['copyright'],
      credit: true,
      cta: {
        label: 'Ready to keep IPv6 prefixes aligned?',
        href: '/docs/installation/',
      },
    },
  },
};

export default config;
