import { fresh } from './dom'

/*
 | "Is my work safe?" -- the line beside the buttons, and the browser's own
 | warning if you try to leave with the answer still no.
 |
 | A Blade screen only ever renders the saved entry, so dirty is what the form
 | reports about itself: FormData against the snapshot taken when the page
 | loaded. The server says what it knows -- a draft waiting -- in the hint's
 | `data-save-hint`, which the line goes back to once the form is clean.
 |
 | The page is marked `data-dirty` too, since the buttons sit in the shell's
 | bar outside the form: Save draft leads while there are unsaved edits and
 | Publish once there are none, in CSS, from that one attribute.
 |
 | A control a script writes -- an editor, a reorder, a pick -- dispatches
 | `input` after it, so it is heard here as typing is.
 */

function snapshot(form: HTMLFormElement): string {
  return new URLSearchParams(new FormData(form) as unknown as Record<string, string>).toString()
}

export function dirtyForms(root: ParentNode = document): void {
  for (const form of fresh<HTMLFormElement>(root, 'form[data-dirty-form]')) {
    let saved = snapshot(form)

    const check = () => {
      const dirty = snapshot(form) !== saved

      form.toggleAttribute('data-dirty', dirty)
      document.body.toggleAttribute('data-dirty', dirty)

      for (const hint of document.querySelectorAll<HTMLElement>('[data-save-hint]')) {
        hint.textContent = dirty ? 'Unsaved changes' : (hint.dataset.saveHint ?? '')
      }

      return dirty
    }

    form.addEventListener('input', check)
    form.addEventListener('change', check)

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
