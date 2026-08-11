import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'

const componentUrl = new URL('../app/components/AppLaunchSplash.vue', import.meta.url)
const installPageUrl = new URL('../app/pages/install.client.vue', import.meta.url)

test('startup splash uses the compact Tochka logo with two accessible motion-safe rings', async () => {
  const source = await readFile(componentUrl, 'utf8')

  assert.match(source, /<SplashBrandMark compact class="launch-loader__brand" \/>/)
  assert.match(source, /launch-loader__ring--outer/)
  assert.match(source, /launch-loader__ring--inner/)
  assert.match(source, /Загрузка приложения…/)
  assert.match(source, /\.launch-loader\s*\{[^}]*align-items: center;[^}]*justify-content: center;/s)
  assert.match(source, /\.launch-loader__brand\s*\{[^}]*width: 7rem;[^}]*height: 7rem;/s)
  assert.match(source, /\.launch-loader__ring--outer\s*\{[^}]*border-top-color: #7f42e1;[^}]*animation: launch-loader-spin 1\.15s linear infinite;/s)
  assert.match(source, /\.launch-loader__ring--inner\s*\{[^}]*border-bottom-color: #ffdd2d;[^}]*animation: launch-loader-spin-reverse 1\.6s linear infinite;/s)
  assert.match(source, /@keyframes launch-loader-spin\s*\{[^}]*to\s*\{[^}]*rotate\(360deg\)/s)
  assert.match(source, /@keyframes launch-loader-spin-reverse\s*\{[^}]*to\s*\{[^}]*rotate\(-360deg\)/s)
  assert.match(source, /@media \(prefers-reduced-motion: reduce\)\s*\{[^}]*\.launch-loader__ring\s*\{[^}]*animation: none;/s)
  assert.doesNotMatch(source, /launch-swoosh/)
  assert.doesNotMatch(source, /launch-shimmer/)
})

test('installation flow reuses the startup splash without the legacy progress screen', async () => {
  const source = await readFile(installPageUrl, 'utf8')

  assert.match(source, /import AppLaunchSplash from '~\/components\/AppLaunchSplash\.vue'/)
  assert.match(source, /<AppLaunchSplash class="min-h-dvh bg-white" \/>/)
  assert.match(source, /for \(const step of Object\.values\(steps\.value\)\)\s*\{\s*await step\.action\(\)/)
  assert.match(source, /await \$b24\.installFinish\(\)/)
  assert.doesNotMatch(source, /TochkaBankLogo/)
  assert.doesNotMatch(source, /role="progressbar"/)
  assert.doesNotMatch(source, /page\.install\.ui\.title/)
  assert.doesNotMatch(source, /confetti\.fire/)
  assert.doesNotMatch(source, /sleepAction\(3000\)/)
})
