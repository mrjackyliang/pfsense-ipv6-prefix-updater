import {
  dxCodeStyle,
  dxIgnore,
  fwDocusaurus,
  langJavascript,
  langMdx,
  langTypescript,
  novaRules,
  runtimeBrowser,
} from '@cbnventures/nova/presets/eslint';

/**
 * ESLint Configuration.
 *
 * @since 0.0.0
 */
export default [
  ...dxIgnore,
  ...dxCodeStyle,
  ...langJavascript,
  ...langMdx,
  ...langTypescript,
  ...fwDocusaurus,
  ...runtimeBrowser,
  {
    name: 'custom-ignores',
    ignores: [],
  },
  {
    name: 'custom-tsconfig',
    files: [
      '**/*.cts',
      '**/*.mts',
      '**/*.ts',
      '**/*.tsx',
    ],
    languageOptions: {
      parserOptions: {
        project: [
          './tsconfig.app.json',
          './tsconfig.config.json',
          './tsconfig.tests.json',
        ],
      },
    },
  },
  ...novaRules,
  {
    name: 'custom-nova-rules',
    files: [
      '**/*.cjs',
      '**/*.cts',
      '**/*.js',
      '**/*.jsx',
      '**/*.mjs',
      '**/*.mts',
      '**/*.ts',
      '**/*.tsx',
    ],
    rules: {
      '@cbnventures/nova/require-jsdoc-body': [
        'error',
        {
          ignoreFiles: [
            './docusaurus.config.ts',
            './eslint.config.mts',
            './sidebars.ts',
            './vitest.config.mts',
          ],
          maxLines: 3,
          maxWidth: 90,
          minLines: 2,
          skipDirectories: [
            'styles',
            'tests',
            'types',
          ],
        },
      ],
      '@cbnventures/nova/require-jsdoc-hierarchy': [
        'error',
        {
          anchorDirectories: [
            'src',
            'utils',
          ],
          ignoreFiles: [
            './docusaurus.config.ts',
            './eslint.config.mts',
            './sidebars.ts',
            './vitest.config.mts',
            './vitest.setup.ts',
          ],
          knownNames: {},
          stripDirectories: ['types'],
        },
      ],
      '@cbnventures/nova/require-jsdoc-presence': [
        'error',
        {
          ignoreFiles: [
            './docusaurus.config.ts',
            './eslint.config.mts',
            './sidebars.ts',
            './vitest.config.mts',
          ],
          skipDirectories: [
            'tests',
            'types',
          ],
        },
      ],
      '@cbnventures/nova/require-kebab-case-filename': [
        'error',
        {
          extraExtensions: [],
          ignoreFiles: [
            './docusaurus.config.ts',
            './eslint.config.mts',
            './vitest.config.mts',
            './vitest.setup.ts',
          ],
        },
      ],
    },
  },
  {
    name: 'locale-generator-output-exceptions',
    files: ['scripts/generate-locales.mjs'],
    rules: {
      '@cbnventures/nova/no-bracket-assignment': 'off',
      '@cbnventures/nova/no-destructuring': 'off',
      '@cbnventures/nova/no-inline-type-annotation': 'off',
      '@cbnventures/nova/no-multiline-strings': 'off',
      '@cbnventures/nova/no-optional-chaining': 'off',
      '@cbnventures/nova/no-regex-literal-flags': 'off',
      '@cbnventures/nova/no-regex-literals': 'off',
      '@cbnventures/nova/require-jsdoc-body': 'off',
      '@cbnventures/nova/require-jsdoc-hierarchy': 'off',
      '@cbnventures/nova/require-jsdoc-presence': 'off',
      '@cbnventures/nova/require-jsdoc-returns': 'off',
      '@cbnventures/nova/require-multiline-conditions': 'off',
    },
  },
];
