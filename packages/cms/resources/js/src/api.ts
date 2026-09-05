/*
 | The admin is just another client of the public content API. Blade hands it
 | the base URL so the panel keeps working when the API prefix is reconfigured.
 */
const base =
  document.querySelector<HTMLMetaElement>('meta[name="mainstay-api"]')?.content ?? '/api/mainstay'

export async function api<T>(path = ''): Promise<T> {
  const response = await fetch(`${base}${path}`, {
    headers: { Accept: 'application/json' },
  })

  if (!response.ok) {
    throw new Error(`Mainstay API responded ${response.status}`)
  }

  return response.json() as Promise<T>
}
