/*
 | Tags as chips with a text input after them. Enter and comma both commit,
 | because both are what people already type; Backspace on an empty input takes
 | the last one back, which is the one thing a chip list is always missing.
 |
 | The chips are real markup carrying real hidden inputs, so the field posts with
 | the form it sits in and there is nothing to serialise. Adding one clones the
 | <template> the component rendered rather than building the chip here, which
 | is the only way the two stay the same chip.
 */

import { once } from './dom'

const SHELL = '[data-tag-input]'

function values(shell: Element): string[] {
  return [...shell.querySelectorAll<HTMLInputElement>('[data-tag] input[type=hidden]')].map((input) => input.value)
}

/* The placeholder is an instruction for an empty field. Once there are chips to
   read, repeating it just crowds them. */
function placeholder(shell: Element): void {
  const field = shell.querySelector<HTMLInputElement>('[data-tag-field]')

  if (field) field.placeholder = values(shell).length === 0 ? (field.dataset.placeholder ?? '') : ''
}

function commit(shell: Element): void {
  const field = shell.querySelector<HTMLInputElement>('[data-tag-field]')
  const template = shell.querySelector<HTMLTemplateElement>('template[data-tag-template]')
  const value = field?.value.trim()

  if (!field || !template) return

  field.value = ''

  if (!value) return

  /* Case-insensitively deduplicated, so "Editor" typed after "editor" does not
     quietly create a second tag that filters to a different set of entries. */
  if (values(shell).some((tag) => tag.toLowerCase() === value.toLowerCase())) return placeholder(shell)

  const chip = template.content.firstElementChild?.cloneNode(true) as HTMLElement | undefined

  if (!chip) return

  for (const label of chip.querySelectorAll('[data-tag-label]')) label.textContent = value

  const hidden = chip.querySelector<HTMLInputElement>('input[type=hidden]')

  if (hidden) hidden.value = value

  field.before(chip)
  placeholder(shell)
}

export function tagInputs(): void {
  once('tag-input', () => {
  document.addEventListener('keydown', (event) => {
    const field = (event.target as Element | null)?.closest<HTMLInputElement>('[data-tag-field]')
    const shell = field?.closest(SHELL)

    if (!field || !shell) return

    if (event.key === 'Enter' || event.key === ',') {
      /* Enter in a form submits it, and a comma would otherwise land in the
         field as the first character of the next tag. */
      event.preventDefault()
      commit(shell)
    }

    if (event.key === 'Backspace' && field.value === '') {
      shell.querySelectorAll('[data-tag]').item(values(shell).length - 1)?.remove()
      placeholder(shell)
    }
  })

  /* Committing on blur as well, because a tag typed and left sitting in the box
     looks added and is not -- and the save button is a click away, which is
     exactly the blur that loses it. */
  document.addEventListener('focusout', (event) => {
    const shell = (event.target as Element | null)?.closest<HTMLElement>('[data-tag-field]')?.closest(SHELL)

    if (shell) commit(shell)
  })

  document.addEventListener('click', (event) => {
    const remove = (event.target as Element | null)?.closest('[data-tag-remove]')
    const shell = remove?.closest(SHELL)

    if (!remove || !shell) return

    remove.closest('[data-tag]')?.remove()
    placeholder(shell)
  })
  })
}
