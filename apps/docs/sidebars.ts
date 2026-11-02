import type { SidebarsConfig } from '@docusaurus/plugin-content-docs';

/**
 * Sidebars - Sidebars.
 *
 * Defines the generated documentation navigation tree.
 * Add categories and documents here as the site grows.
 *
 * @since 0.0.0
 */
const sidebars: SidebarsConfig = {
  docs: [
    {
      type: 'category',
      label: 'Start Here',
      collapsed: false,
      items: [
        'intro',
        'installation',
        'first-run',
        'compatibility',
        'migrate-from-legacy',
      ],
    },
    {
      type: 'category',
      label: 'Configure',
      collapsed: false,
      items: [
        'configure/interface-and-prefix',
        'configure/configuration-scopes',
        'configure/schedule-and-notifications',
      ],
    },
    {
      type: 'category',
      label: 'Operate',
      collapsed: false,
      items: [
        'operate/status-and-actions',
        'operate/run-outcomes',
        'operate/backups-and-recovery',
        'operate/troubleshooting',
      ],
    },
    {
      type: 'category',
      label: 'Safety',
      collapsed: false,
      items: [
        'operations',
        'safety/how-updates-work',
        'safety/testing-and-verification',
      ],
    },
    {
      type: 'category',
      label: 'Reference',
      collapsed: true,
      items: [
        'reference/terminology',
        'reference/settings',
        'reference/approved-fields',
        'reference/command-line-and-files',
        'reference/package-lifecycle',
      ],
    },
    {
      type: 'category',
      label: 'Contributing',
      collapsed: true,
      items: ['contributing/build-and-release'],
    },
  ],
};

export default sidebars;
