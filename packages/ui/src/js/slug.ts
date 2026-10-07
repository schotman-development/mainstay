import { fresh } from './dom'

/*
 | An address that writes itself from the title while nobody has written one.
 |
 | The server marks the slug `data-slug-from` with the title's input name only
 | where following is right: never on an entry already published, whose
 | address a new title must not move. Typing into the slug stops it, and so
 | does a slug that already says something other than the title would.
 */

/* Lowercase, accents dropped, anything else a hyphen: what Route::SLUG takes. */
export function slugify(text: string): string {
  return text
    .normalize('NFKD')
    .replace(/[̀-ͯ]/g, '')
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '')
}

export function slugs(root: ParentNode = document): void {
  for (const slug of fresh<HTMLInputElement>(root, 'input[data-slug-from]')) {
    const title = slug.form?.elements.namedItem(slug.dataset.slugFrom ?? '')

    if (!(title instanceof HTMLInputElement)) continue

    let following = slug.value === '' || slug.value === slugify(title.value)
    let writing = false

    slug.addEventListener('input', () => {
      if (!writing) following = false
    })

    title.addEventListener('input', () => {
      if (!following) return

      slug.value = slugify(title.value)
      writing = true
      slug.dispatchEvent(new Event('input', { bubbles: true }))
      writing = false
    })
  }
}
