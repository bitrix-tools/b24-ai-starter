// @ts-check
import withNuxt from './.nuxt/eslint.config.mjs'

export default withNuxt(
  {
    rules: {
      // This starter uses `any` deliberately in several places (flexible config
      // maps, loosely-typed REST payloads). Keep it visible as a warning instead
      // of failing CI; tighten to 'error' once the codebase is fully typed.
      '@typescript-eslint/no-explicit-any': 'warn',
      // `Logo` is a legitimate single-word brand component.
      'vue/multi-word-component-names': 'warn'
    }
  }
)
