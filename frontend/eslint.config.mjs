// @ts-check
import withNuxt from './.nuxt/eslint.config.mjs'

export default withNuxt(
  {
    // `Logo` is a legitimate single-word brand component.
    files: ['**/components/Logo.vue'],
    rules: {
      'vue/multi-word-component-names': 'off'
    }
  },
  {
    // Grandfather the pre-existing `any` in these files (flexible config maps /
    // loosely-typed REST payloads). The rule stays 'error' everywhere else, so
    // new code cannot silently introduce `any`. Trim this list as files get typed.
    files: [
      '**/stores/appSettings.ts',
      '**/stores/userSettings.ts',
      '**/pages/install.client.vue',
      '**/pages/slider/app-options.client.vue',
      '**/middleware/01.app.page.or.slider.global.ts',
      '**/shared/types/base.d.ts'
    ],
    rules: {
      '@typescript-eslint/no-explicit-any': 'off'
    }
  }
)
