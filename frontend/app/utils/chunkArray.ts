export function chunkArray<T>(array: T[], chunkSize: number = 50): T[][] {
  if (!Number.isInteger(chunkSize) || chunkSize < 1) {
    // A non-positive step would make the loop below spin forever.
    throw new RangeError(`chunkArray: chunkSize must be a positive integer, got ${chunkSize}`)
  }

  const result: T[][] = []
  for (let i = 0; i < array.length; i += chunkSize) {
    const chunk = array.slice(i, i + chunkSize)
    result.push(chunk)
  }
  return result
}

/**
 * Splits an array of elements into pages with a flexible first page size and fixed subsequent pages
 * @template T - Type of elements in the original array
 * @param {T[]} productsCollection - Original array of elements to split into pages
 * @param {object} [perPageMap] - Settings for the number of elements on pages
 * @param {number} [perPageMap.first] - Basic number of elements on the first page
 * @param {number} [perPageMap.second] - Number of elements on subsequent pages.
 *                                       Selected experimentally, with the maximum number the footer does not extend beyond the edges of the page
 * @param {number} [fix] - Correction factor: if there are more elements than first + fix,
 *                         increases the size of the first page by the value fix
 * @returns {T[][]} Array of pages with elements
 *
 * @example
 * // Standard usage
 * chunkProductsList(products);
 *
 * @example
 * // Custom parameters
 * chunkProductsList(products, { first: 5, second: 10 }, 3);
 */
export function chunkProductsList<T>(
  productsCollection: T[],
  perPageMap = { first: 9, second: 15 },
  fix = 2
): T[][] {
  // Work on a copy — never mutate the caller's object (perPageMap.first += fix
  // below would otherwise leak across repeated calls with the same object).
  perPageMap = { ...perPageMap }

  let currentPage = 0
  const pageItems: T[][] = []

  if (productsCollection.length > (perPageMap.first + fix)) {
    perPageMap.first += fix
  }

  let iterator = 0
  for (const product of productsCollection) {
    pageItems[currentPage] = pageItems[currentPage] || []
    pageItems[currentPage]!.push(product)
    iterator++

    if (currentPage === 0) {
      if (iterator >= perPageMap.first) {
        currentPage++
        iterator = 0
      }
    } else {
      if (iterator >= perPageMap.second) {
        currentPage++
        iterator = 0
      }
    }
  }

  // Fix last page
  const lastPage = pageItems.length > 0 ? pageItems.length - 1 : 0
  if (pageItems[lastPage] && pageItems[lastPage].length === 0) {
    pageItems.pop()
  }

  return pageItems
}
