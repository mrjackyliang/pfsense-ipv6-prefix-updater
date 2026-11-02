import {
  dxCodeStyle,
  dxIgnore,
  langJavascript,
  langTypescript,
  novaRules,
  runtimeNode,
} from '@cbnventures/nova/presets/eslint';

/**
 * ESLint Configuration.
 *
 * Lints only the Node.js packaging helpers; pfSense product sources remain native.
 *
 * @since 0.0.0
 */
export default [
  ...dxIgnore,
  ...dxCodeStyle,
  ...langJavascript,
  ...langTypescript,
  ...runtimeNode,
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
          './tsconfig.scripts.json',
          './tsconfig.tests.json',
        ],
      },
    },
  },
  ...novaRules,
  {
    name: 'custom-nova-rules',
    rules: {
      '@cbnventures/nova/no-regex-literals': [
        'error',
        {
          ignoreFiles: [],
          regexFile: './scripts/regex.mjs',
        },
      ],
    },
  },
];
