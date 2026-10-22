import { fresh, once } from './dom'

/*
 | A copy in the browser of a form left unsaved, so a closed tab, a crash or
 | an ended session does not take the work with it. While the form is dirty,
 | what it would post, less its CSRF token, is kept in local storage under
 | the form's `data-copy` -- the account's id, the entry's admin address and
 | the locale -- with the time. Opening the form again as that account, with
 | a copy that differs from what is drawn, offers it: Restore posts it to the
 | form's own action with `intent=restore`, which draws the form from it,
 | unsaved; Dismiss drops it.
 |
 | A save names its copy in the tab's session storage as it goes. The server
 | marks the page a save lands on, `data-saved` on the body, only once the
 | save has written something, so that page drops the copy and a save refused,
 | or one that never ran for an ended session, keeps it. Every page clears the
 | name. Discarding the draft drops the copy, and signing out drops every one,
 | so the next person at the browser finds none.
 */

const prefix = 'mainstay:copy:'
const saving = 'mainstay:saving'

type Pairs = [string, string][]
type Copy = { at: string; data: Pairs }

const posted = (form: HTMLFormElement): Pairs =>
  [...new FormData(form)].filter((pair): pair is [string, string] => pair[0] !== '_token' && typeof pair[1] === 'string')

const same = (a: Pairs, b: Pairs) => new URLSearchParams(a).toString() === new URLSearchParams(b).toString()

/* Storage can be full or off -- private browsing -- and the form works on
   without a copy. */
function attempt<T>(run: () => T): T | undefined {
  try {
    return run()
  } catch {
    return undefined
  }
}

function read(key: string): Copy | null {
  const copy = attempt(() => JSON.parse(localStorage.getItem(key) ?? 'null') as Copy | null)

  return copy && typeof copy.at === 'string' && Array.isArray(copy.data) ? copy : null
}

const drop = (key: string) => attempt(() => localStorage.removeItem(key))

function when(at: string): string {
  const date = new Date(at)
  const time = date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })

  return date.toDateString() === new Date().toDateString() ? time : `${date.toLocaleDateString()} ${time}`
}

/* The copy posted as the form, as a form of its own: the entry's holds
   what is drawn. */
function restore(form: HTMLFormElement, copy: Copy): void {
  const post = Object.assign(document.createElement('form'), { method: 'post', action: form.action, hidden: true })
  const token = form.querySelector<HTMLInputElement>('input[name="_token"]')?.value ?? ''

  for (const [name, value] of [...copy.data, ['_token', token], ['intent', 'restore']]) {
    post.append(Object.assign(document.createElement('input'), { type: 'hidden', name, value }))
  }

  document.body.append(post)
  post.submit()
}

export function formCopies(root: ParentNode = document): void {
  attempt(() => {
    const key = sessionStorage.getItem(saving)

    sessionStorage.removeItem(saving)
    if (key !== null && document.body.hasAttribute('data-saved')) localStorage.removeItem(key)
  })

  once('form-copies', () => {
    document.addEventListener('submit', (event) => {
      const form = event.target as Element

      if (form.matches('[data-sign-out]')) {
        for (const key of attempt(() => Object.keys(localStorage)) ?? []) {
          if (key.startsWith(prefix)) drop(key)
        }
      }

      if (form.matches('[data-discard]')) {
        const copied = document.querySelector<HTMLFormElement>('form[data-copy]')

        if (copied) drop(prefix + copied.dataset.copy)
      }
    })
  })

  for (const form of fresh<HTMLFormElement>(root, 'form[data-copy]')) {
    const key = prefix + form.dataset.copy
    const copy = read(key)
    const offer = form.querySelector<HTMLElement>('[data-copy-offer]')

    if (copy && offer && !same(copy.data, posted(form))) {
      const time = offer.querySelector('[data-copy-time]')

      if (time) time.textContent = when(copy.at)

      offer.hidden = false
      offer.querySelector('[data-copy-restore]')?.addEventListener('click', () => restore(form, copy))
      offer.querySelector('[data-copy-dismiss]')?.addEventListener('click', () => {
        drop(key)
        offer.hidden = true
      })
    }

    /* After the dirty guard, which marks the form first. */
    const keep = () => {
      if (!form.hasAttribute('data-dirty')) {
        drop(key)

        return
      }

      attempt(() => localStorage.setItem(key, JSON.stringify({ at: new Date().toISOString(), data: posted(form) } satisfies Copy)))
    }

    form.addEventListener('input', keep)
    form.addEventListener('change', keep)
    form.addEventListener('submit', () => attempt(() => sessionStorage.setItem(saving, key)))
  }
}
