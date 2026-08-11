import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'

const dashboardUrl = new URL('../app/pages/dashboard.client.vue', import.meta.url)

test('background sync settings describe server operation after the app closes', async () => {
  const source = await readFile(dashboardUrl, 'utf8')

  assert.match(source, /Включить фоновую синхронизацию/)
  assert.match(source, /сервер продолжает синхронизацию даже при закрытом приложении/)
  assert.doesNotMatch(
    source,
    /после закрытия окна фоновые запросы из приложения не выполняются/,
  )
  assert.doesNotMatch(
    source,
    /Включить постоянную синхронизацию \(пока открыто приложение\)/,
  )
})
