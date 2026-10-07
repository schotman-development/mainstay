import { beforeEach, expect, test } from 'vitest'
import { slugify, slugs } from './slug'

function form(slug = '', title = '') {
  document.body.innerHTML = `
    <form>
      <input name="title" value="${title}">
      <input name="slug" value="${slug}" data-slug-from="title">
    </form>`

  slugs()

  return {
    title: document.querySelector<HTMLInputElement>('[name=title]')!,
    slug: document.querySelector<HTMLInputElement>('[name=slug]')!,
  }
}

const type = (input: HTMLInputElement, value: string) => {
  input.value = value
  input.dispatchEvent(new Event('input', { bubbles: true }))
}

beforeEach(() => (document.body.innerHTML = ''))

test('a slug is what Route::SLUG takes: lowercase, accents dropped, anything else a hyphen', () => {
  expect(slugify('Ça marche — déjà 2 fois!')).toBe('ca-marche-deja-2-fois')
  expect(slugify('  --Hello  World-- ')).toBe('hello-world')
})

test('an empty slug follows the title as it is typed, and says so to the form', () => {
  const { title, slug } = form()
  let heard = 0
  slug.form!.addEventListener('input', (event) => (heard += event.target === slug ? 1 : 0))

  type(title, 'Drafts, and a way back')

  expect(slug.value).toBe('drafts-and-a-way-back')
  expect(heard).toBe(1)
})

test('typing into the slug stops it following', () => {
  const { title, slug } = form()

  type(title, 'First')
  type(slug, 'my-own')
  type(title, 'Second')

  expect(slug.value).toBe('my-own')
})

test('a slug that already says something else is left alone', () => {
  const { title, slug } = form('kept', 'Hello')

  type(title, 'Hello again')

  expect(slug.value).toBe('kept')
})

test('a slug still matching its title goes on following it', () => {
  const { title, slug } = form('hello', 'Hello')

  type(title, 'Hello again')

  expect(slug.value).toBe('hello-again')
})
