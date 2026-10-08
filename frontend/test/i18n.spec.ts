import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'
import { contentLocales } from '../i18n/i18n.map'

/**
 * Every locale must have exactly the keys of en.json (the source of truth).
 * Missing keys silently fall back to English, stale keys are dead text —
 * both are caught here instead of in review.
 */
type Messages = { [key: string]: string | Messages }

const localesDir = resolve(__dirname, '../i18n/locales')
const load = (file: string): Messages => JSON.parse(readFileSync(resolve(localesDir, file), 'utf8'))

function keys(messages: Messages, prefix = ''): string[] {
  return Object.entries(messages).flatMap(([key, value]) =>
    typeof value === 'string' ? [`${prefix}${key}`] : keys(value, `${prefix}${key}.`)
  ).sort()
}

const reference = keys(load('en.json'))

describe('i18n locales', () => {
  it.each(contentLocales.map(locale => [locale.code, locale.file as string]))(
    '%s has exactly the keys of en.json',
    (_code, file) => {
      const actual = keys(load(file))
      expect({
        missing: reference.filter(key => !actual.includes(key)),
        extra: actual.filter(key => !reference.includes(key))
      }).toEqual({ missing: [], extra: [] })
    }
  )
})
