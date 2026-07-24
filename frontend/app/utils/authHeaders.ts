/**
 * Build the `Authorization: Bearer <jwt>` header for protected backend routes.
 *
 * Kept as a plain, framework-free helper (no Nuxt/Pinia context) so it can be
 * unit-tested directly and reused by the API store.
 *
 * @param token the current application JWT
 * @returns a headers object with the Authorization entry
 */
export function buildAuthHeaders(token: string): Record<string, string> {
  return { Authorization: `Bearer ${token}` }
}
