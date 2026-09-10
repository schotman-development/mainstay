import { fresh } from './dom'

/*
 | "Is my work safe?" -- the line beside the Save button, and the browser's own
 | warning if you try to leave with the answer still no.
 |
 | React diffed two copies of the entry held in state. A Blade screen only ever
 | renders the saved one, so dirty is what the form reports about itself:
 | FormData against the snapshot taken when the page loaded. Same question, one
 | side of it now living in the browser.
 |
 | EntryForm::saveState is still where the answer belongs -- it runs once per
 | render, so the hint is all it can hand over today. Anything it decides about
 | the button's label or variant needs carrying across here too.
 */

function snapshot(form: HTMLFormElement): string {
  return new URLSearchParams(new FormData(form) as unknown as Record<string, string>).toString()
}

export function dirtyForms(root: ParentNode = document): void {
  for (const form of fresh<HTMLFormElement>(root, 'form[data-dirty-form]')) {
    let saved = snapshot(form)

    const check = () => {
      const dirty = snapshot(form) !== saved

      for (const hint of document.querySelectorAll('[data-save-hint]')) {
        hint.textContent = dirty ? 'Unsaved changes' : ''
      }

      for (const discard of document.querySelectorAll('[data-discard]')) {
        discard.toggleAttribute('disabled', !dirty)
      }

      return dirty
    }

    form.addEventListener('input', check)
    form.addEventListener('change', check)

    /* Discard puts the form back the way the server sent it. reset() is the
       platform's own answer -- it restores every control's default, which is
       exactly what was rendered -- and the tag chips are markup, so they come
       back with it only if nothing removed them; those are re-rendered from the
       snapshot instead. */
    for (const discard of document.querySelectorAll('[data-discard]')) {
      discard.addEventListener('click', () => {
        form.reset()

        for (const [name, value] of new URLSearchParams(saved)) {
          const field = form.elements.namedItem(name)

          if (field instanceof HTMLInputElement && field.type === 'hidden') field.value = value
        }

        check()
      })
    }

    /* Submitting is the thing that makes the form clean again, so the warning
       below must not fire on the navigation it causes. */
    form.addEventListener('submit', () => {
      saved = snapshot(form)
      check()
    })

    window.addEventListener('beforeunload', (event) => {
      if (check()) event.preventDefault()
    })
  }
}
