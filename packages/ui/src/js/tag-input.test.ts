import { beforeEach, expect, test } from 'vitest'
import { tagInputs } from './tag-input'

/*
 | The markup here is a trimmed copy of what tag-input.blade.php renders. The
 | copy is the seam, so BehaviourContractTest asserts the real component still
 | emits every hook these tests reach for.
 */
const chip = (tag: string) => `
  <span data-tag>
    <span data-tag-label>${tag}</span>
    <input type="hidden" name="tags[]" value="${tag}">
    <button type="button" data-tag-remove><span class="sr-only">Remove <span data-tag-label>${tag}</span></span></button>
  </span>`

function field(tags: string[] = []) {
  document.body.innerHTML = `
    <div data-tag-input>
      ${tags.map(chip).join('')}
      <input data-tag-field data-placeholder="Add a tag" placeholder="${tags.length ? '' : 'Add a tag'}">
      <template data-tag-template>${chip('')}</template>
    </div>`

  tagInputs()

  return document.querySelector<HTMLInputElement>('[data-tag-field]')!
}

const tags = () => [...document.querySelectorAll('[data-tag] input[type=hidden]')].map((i) => (i as HTMLInputElement).value)

const press = (input: HTMLInputElement, key: string) =>
  input.dispatchEvent(new KeyboardEvent('keydown', { key, bubbles: true }))

beforeEach(() => (document.body.innerHTML = ''))

test('Enter commits a tag and clears the box', () => {
  const input = field()

  input.value = 'headless'
  press(input, 'Enter')

  expect(tags()).toEqual(['headless'])
  expect(input.value).toBe('')
})

test('a comma commits too, without landing in the next tag', () => {
  const input = field()

  input.value = 'design'
  press(input, ',')

  expect(tags()).toEqual(['design'])
  expect(input.value).toBe('')
})

/* "Editor" after "editor" would otherwise be a second tag that filters to a
   different set of entries. */
test('a tag that differs only in case is not added twice', () => {
  const input = field(['editor'])

  input.value = 'EDITOR'
  press(input, 'Enter')

  expect(tags()).toEqual(['editor'])
})

test('blank input is not committed', () => {
  const input = field(['editor'])

  input.value = '   '
  press(input, 'Enter')

  expect(tags()).toEqual(['editor'])
})

test('Backspace on an empty box takes the last tag back', () => {
  const input = field(['editor', 'release'])

  input.value = ''
  press(input, 'Backspace')

  expect(tags()).toEqual(['editor'])
})

/* A tag typed and left sitting in the box looks added and is not -- and the
   save button is a click away, which is exactly the blur that loses it. */
test('leaving the box commits what is in it', () => {
  const input = field()

  input.value = 'typography'
  input.dispatchEvent(new FocusEvent('focusout', { bubbles: true }))

  expect(tags()).toEqual(['typography'])
})

/* The placeholder is an instruction for an empty field; once there are chips to
   read it just crowds them. */
test('the placeholder goes when the first tag arrives and comes back when the last leaves', () => {
  const input = field()

  input.value = 'editor'
  press(input, 'Enter')
  expect(input.placeholder).toBe('')

  document.querySelector<HTMLButtonElement>('[data-tag-remove]')!.click()
  expect(input.placeholder).toBe('Add a tag')
})

/* The template is markup, not a tag: it must not be counted or posted. */
test('the blank chip in the template is not one of the tags', () => {
  field(['editor'])

  expect(tags()).toEqual(['editor'])
})
