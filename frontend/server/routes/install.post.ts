export default defineEventHandler(async (event) => {
  const backendUrl = process.env.SERVER_HOST || 'http://api:8000'
  const useFrontendInstall = backendUrl.includes('api-python')
    && process.env.B24_INSTALL_API_ONLY?.trim().toLowerCase() !== 'true'

  if (!useFrontendInstall) {
    const body = await readBody(event)
    return await $fetch(`${backendUrl}/api/install`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body),
    })
  }

  return sendRedirect(event, '/install', 302)
})
