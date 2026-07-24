import { describe, it, expect, vi } from 'vitest'
import { buildAuthHeaders } from '../app/utils/authHeaders'
import { chunkArray, chunkProductsList } from '../app/utils/chunkArray'
import { sleepAction } from '../app/utils/sleep'

describe('buildAuthHeaders', () => {
  it('formats the Bearer header from a token', () => {
    expect(buildAuthHeaders('JWT123')).toEqual({ Authorization: 'Bearer JWT123' })
  })

  it('still produces a header for an empty (pre-init) token', () => {
    expect(buildAuthHeaders('')).toEqual({ Authorization: 'Bearer ' })
  })
})

describe('chunkArray', () => {
  it('splits into chunks of the requested size', () => {
    expect(chunkArray([1, 2, 3, 4, 5], 2)).toEqual([[1, 2], [3, 4], [5]])
  })

  it('defaults to a chunk size of 50', () => {
    const res = chunkArray(Array.from({ length: 120 }, (_, i) => i))
    expect(res).toHaveLength(3)
    expect(res[0]).toHaveLength(50)
    expect(res[2]).toHaveLength(20)
  })

  it('returns an empty array for empty input', () => {
    expect(chunkArray([])).toEqual([])
  })

  it('throws on a non-positive chunk size (guards against an infinite loop)', () => {
    expect(() => chunkArray([1, 2, 3], 0)).toThrow(RangeError)
    expect(() => chunkArray([1, 2, 3], -1)).toThrow(RangeError)
  })
})

describe('chunkProductsList', () => {
  it('keeps everything on the first page when it fits', () => {
    expect(chunkProductsList([1, 2, 3])).toEqual([[1, 2, 3]])
  })

  it('enlarges the first page by fix, then paginates by second', () => {
    const items = Array.from({ length: 30 }, (_, i) => i)
    const pages = chunkProductsList(items) // first 9(+2), second 15, fix 2
    expect(pages.map(p => p.length)).toEqual([11, 15, 4])
    expect(pages.flat()).toEqual(items)
  })

  it('does not enlarge the first page at the exact first+fix boundary', () => {
    const items = Array.from({ length: 11 }, (_, i) => i) // 11 === first(9) + fix(2)
    expect(chunkProductsList(items).map(p => p.length)).toEqual([9, 2])
  })

  it('does not mutate the caller\'s perPageMap across calls', () => {
    const cfg = { first: 9, second: 15 }
    const items = Array.from({ length: 30 }, (_, i) => i)
    const first = chunkProductsList(items, cfg, 2)
    const second = chunkProductsList(items, cfg, 2)
    expect(cfg).toEqual({ first: 9, second: 15 }) // input untouched
    expect(first.map(p => p.length)).toEqual(second.map(p => p.length)) // stable result
  })

  it('returns an empty array for empty input', () => {
    expect(chunkProductsList([])).toEqual([])
  })
})

describe('sleepAction', () => {
  it('resolves after the given delay', async () => {
    vi.useFakeTimers()
    try {
      let resolved = false
      const p = sleepAction(1000).then(() => { resolved = true })
      await vi.advanceTimersByTimeAsync(1000)
      await p
      expect(resolved).toBe(true)
    } finally {
      vi.useRealTimers()
    }
  })
})
