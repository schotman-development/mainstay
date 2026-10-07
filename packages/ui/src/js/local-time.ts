import { fresh } from './dom'

/*
 | A moment typed in the editor's own zone.
 |
 | The server stores UTC and draws a `datetime-local` holding it, named, so a
 | page without this posts UTC and says so. Here the stored instant is shown
 | in the browser's zone, the name moves to a hidden input, and that posts
 | what was typed with the browser's offset -- which the server turns back
 | into UTC. The hint saying UTC goes, since it no longer is.
 */

const pad = (n: number) => String(n).padStart(2, '0')

/* `2026-10-07T14:30:45` for the input, in the browser's zone: to the second,
   as the server draws it, so an untouched moment posts back as itself. */
function local(date: Date): string {
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}:${pad(date.getSeconds())}`
}

/* `2026-10-07T14:30:00+02:00`: what was typed, and where. */
function stamped(value: string): string {
  if (value === '') return ''

  const offset = -new Date(value).getTimezoneOffset()
  const sign = offset < 0 ? '-' : '+'

  /* To the second: an input may hand back minutes alone, or milliseconds. */
  const moment = value.length === 16 ? `${value}:00` : value.slice(0, 19)

  return `${moment}${sign}${pad(Math.floor(Math.abs(offset) / 60))}:${pad(Math.abs(offset) % 60)}`
}

export function localTimes(root: ParentNode = document): void {
  for (const input of fresh<HTMLInputElement>(root, 'input[type="datetime-local"][data-utc]')) {
    const hidden = document.createElement('input')

    hidden.type = 'hidden'
    hidden.name = input.name
    input.removeAttribute('name')
    input.after(hidden)

    if (input.value !== '') {
      const instant = new Date(`${input.value}Z`)

      input.value = local(instant)
    }

    hidden.value = stamped(input.value)

    document.getElementById(input.dataset.utc ?? '')?.remove()

    /* Ahead of the form's own listeners, which read FormData. */
    input.addEventListener('input', () => {
      hidden.value = stamped(input.value)
    })
  }
}
