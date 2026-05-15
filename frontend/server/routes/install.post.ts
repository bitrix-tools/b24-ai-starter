export default defineEventHandler(async (event) => {
  const body = await readBody(event)
  const backendUrl = process.env.SERVER_HOST || 'http://api:8000'
  return await $fetch(`${backendUrl}/api/install`, {
    method: 'POST',
    body
  })
})
