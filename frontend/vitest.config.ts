import { defineConfig } from 'vitest/config'

// Plain Vitest config for framework-free unit tests (pure helpers/utils).
// Nuxt-runtime component/store tests would need @nuxt/test-utils; kept out
// intentionally to avoid coupling the unit suite to the Nuxt test runtime.
export default defineConfig({
  test: {
    environment: 'node',
    include: ['test/**/*.{test,spec}.ts']
  }
})
