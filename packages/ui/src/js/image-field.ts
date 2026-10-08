import { once } from './dom'

/*
 | An image field: a thumbnail, the image's id in a hidden input, and Choose,
 | Replace and Remove. Choose opens the admin's picker dialog with the library
 | fetched into it -- its pages and its upload fetched too, since the dialog
 | sits outside the entry's form -- and a picked image sets the id and the
 | thumbnail. One picker serves every field: whichever opened it last is
 | answered. An upload from it picks what it uploaded.
 |
 | The thumbnails are cloned from the field's own <template>s rather than
 | built here, so the markup is stated once.
 */

let opener: HTMLElement | null = null

function set(field: HTMLElement, id: string, src: string | null): void {
  const input = field.querySelector<HTMLInputElement>('input[type="hidden"]')
  const preview = field.querySelector('[data-image-preview]')
  const template = field.querySelector<HTMLTemplateElement>(src ? 'template[data-image-chosen]' : 'template[data-image-empty]')
  const shown = template?.content.firstElementChild?.cloneNode(true) as HTMLElement | undefined

  if (!input || !preview || !shown) return

  if (src) shown.setAttribute('src', src)

  input.value = id
  preview.replaceChildren(shown)
  field.querySelector('[data-image-choose]')!.textContent = id ? 'Replace' : 'Choose'
  field.querySelector<HTMLElement>('[data-image-remove]')!.hidden = !id
  input.dispatchEvent(new Event('input', { bubbles: true }))
}

async function load(body: HTMLElement, url: string, init?: RequestInit): Promise<void> {
  const response = await fetch(url, { headers: { Accept: 'text/html' }, ...init })

  /* The library's own answer -- the grid, or a refused upload drawn as the
     grid with its reasons -- and nothing else, such as a refusal's page. */
  if (!response.ok && response.status !== 422) {
    body.textContent = response.status === 403 ? 'The media library is not open to you.' : 'The media library could not be loaded.'

    return
  }

  body.innerHTML = await response.text()

  const uploaded = response.ok && init?.method === 'POST' ? body.querySelector<HTMLElement>('[data-pick][data-picked]') : null

  if (uploaded) uploaded.click()
}

export function imageFields(): void {
  once('image-fields', () => {
    document.addEventListener('click', (event) => {
      const target = event.target as Element | null
      const dialog = document.getElementById('mainstay-picker')
      const body = dialog?.querySelector<HTMLElement>('[data-picker-body]')

      if (!(dialog instanceof HTMLDialogElement) || !body) return

      const choose = target?.closest<HTMLElement>('[data-image-choose]')?.closest<HTMLElement>('[data-image-field]')
      const remove = target?.closest<HTMLElement>('[data-image-remove]')?.closest<HTMLElement>('[data-image-field]')
      const pick = target?.closest<HTMLElement>('[data-pick]')
      const page = target?.closest<HTMLAnchorElement>('a[data-picker-page]')

      if (choose) {
        opener = choose
        dialog.showModal()
        void load(body, choose.dataset.picker ?? '')
      } else if (remove) {
        set(remove, '', null)
      } else if (pick && opener && dialog.contains(pick)) {
        set(opener, pick.dataset.pick ?? '', pick.dataset.src ?? null)
        dialog.close()
      } else if (page && dialog.contains(page)) {
        event.preventDefault()
        void load(body, page.href)
      }
    })

    document.addEventListener('submit', (event) => {
      const form = (event.target as Element | null)?.closest<HTMLFormElement>('form[data-media-upload]')
      const body = form?.closest<HTMLElement>('[data-picker-body]')

      if (!form || !body) return

      event.preventDefault()
      void load(body, form.action, { method: 'POST', body: new FormData(form) })
    })
  })
}
