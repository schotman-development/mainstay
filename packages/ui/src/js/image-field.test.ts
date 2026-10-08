import { afterEach, expect, test, vi } from 'vitest'
import { imageFields } from './image-field'

afterEach(() => vi.unstubAllGlobals())

test('Choose fetches the library into the picker, and a pick sets the id and the thumbnail', async () => {
  document.body.innerHTML = `
    <form>
      <div data-image-field data-picker="/admin/media?pick=1">
        <input type="hidden" name="cover" value="">
        <span data-image-preview><div>None</div></span>
        <button type="button" data-image-choose>Choose</button>
        <button type="button" data-image-remove hidden>Remove</button>
        <template data-image-chosen><img src="about:blank" alt=""></template>
        <template data-image-empty><div>None</div></template>
      </div>
    </form>
    <dialog id="mainstay-picker"><div data-picker-body></div></dialog>`

  const dialog = document.querySelector('dialog')!

  dialog.showModal = function () {
    this.setAttribute('open', '')
  }
  dialog.close = function () {
    this.removeAttribute('open')
  }

  const fetched = vi.fn(async () => new Response('<button type="button" data-pick="7" data-src="/storage/media/abc-640.webp">Seven</button><a href="/admin/media?pick=1&page=2" data-picker-page>Next</a>'))

  vi.stubGlobal('fetch', fetched)
  imageFields()

  document.querySelector<HTMLElement>('[data-image-choose]')!.click()
  await vi.waitFor(() => expect(document.querySelector('[data-pick]')).not.toBeNull())

  expect(fetched.mock.calls[0]?.[0]).toBe('/admin/media?pick=1')
  expect(dialog.open).toBe(true)

  /* Its pages are fetched into it, rather than followed. */
  const next = new MouseEvent('click', { bubbles: true, cancelable: true })

  document.querySelector('[data-picker-page]')!.dispatchEvent(next)
  expect(next.defaultPrevented).toBe(true)
  await vi.waitFor(() => expect(String(fetched.mock.calls[1]?.[0])).toContain('page=2'))
  await vi.waitFor(() => expect(document.querySelector('[data-pick]')).not.toBeNull())

  document.querySelector<HTMLElement>('[data-pick]')!.click()

  expect(new FormData(document.querySelector('form')!).get('cover')).toBe('7')
  expect(document.querySelector('[data-image-preview] img')!.getAttribute('src')).toBe('/storage/media/abc-640.webp')
  expect(document.querySelector('[data-image-choose]')!.textContent).toBe('Replace')
  expect(document.querySelector<HTMLElement>('[data-image-remove]')!.hidden).toBe(false)
  expect(dialog.open).toBe(false)

  document.querySelector<HTMLElement>('[data-image-remove]')!.click()

  expect(new FormData(document.querySelector('form')!).get('cover')).toBe('')
  expect(document.querySelector('[data-image-preview]')!.textContent).toBe('None')
})

test('an upload from the picker is sent with fetch, and picks what it uploaded', async () => {
  document.body.innerHTML = `
    <form>
      <div data-image-field data-picker="/admin/media?pick=1">
        <input type="hidden" name="cover" value="">
        <span data-image-preview></span>
        <button type="button" data-image-choose>Choose</button>
        <button type="button" data-image-remove hidden>Remove</button>
        <template data-image-chosen><img src="about:blank" alt=""></template>
        <template data-image-empty><div>None</div></template>
      </div>
    </form>
    <dialog id="mainstay-picker"><div data-picker-body></div></dialog>`

  const dialog = document.querySelector('dialog')!

  dialog.showModal = function () {
    this.setAttribute('open', '')
  }
  dialog.close = function () {
    this.removeAttribute('open')
  }

  const fetched = vi.fn(async (url: string) =>
    new Response(
      url.includes('store')
        ? '<button type="button" data-pick="9" data-src="/nine.webp" data-picked>Nine</button>'
        : '<form method="post" action="/admin/media?pick=1&store=1" data-media-upload><input name="alt[en]" value="A pier"></form>',
    ),
  )

  vi.stubGlobal('fetch', fetched)
  imageFields()

  document.querySelector<HTMLElement>('[data-image-choose]')!.click()
  await vi.waitFor(() => expect(document.querySelector('form[data-media-upload]')).not.toBeNull())

  document.querySelector('form[data-media-upload]')!.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }))
  await vi.waitFor(() => expect(new FormData(document.querySelector('form')!).get('cover')).toBe('9'))

  expect((fetched.mock.calls[1] as unknown as [string, RequestInit])[1].method).toBe('POST')
  expect(dialog.open).toBe(false)
})

test('a picker the library refuses says so, rather than drawing the refusal', async () => {
  document.body.innerHTML = `
    <div data-image-field data-picker="/admin/media?pick=1">
      <input type="hidden" name="cover" value="">
      <button type="button" data-image-choose>Choose</button>
    </div>
    <dialog id="mainstay-picker"><div data-picker-body></div></dialog>`

  document.querySelector('dialog')!.showModal = function () {
    this.setAttribute('open', '')
  }
  vi.stubGlobal('fetch', vi.fn(async () => new Response('<!DOCTYPE html><title>Forbidden</title>', { status: 403 })))
  imageFields()

  document.querySelector<HTMLElement>('[data-image-choose]')!.click()

  await vi.waitFor(() => expect(document.querySelector('[data-picker-body]')!.textContent).toBe('The media library is not open to you.'))
})
