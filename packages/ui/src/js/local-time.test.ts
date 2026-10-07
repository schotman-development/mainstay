import { beforeEach, expect, test } from 'vitest'
import { localTimes } from './local-time'

/* A zone with an offset, and a different one in summer and winter, so a sign
   or a shift gone wrong shows on a machine that runs in UTC, as CI does. */
process.env.TZ = 'Europe/Amsterdam'

/* The stamp a browser in this zone would post for a typed local time. */
function offset(local: string): string {
  const minutes = -new Date(local).getTimezoneOffset()
  const pad = (n: number) => String(n).padStart(2, '0')

  return `${minutes < 0 ? '-' : '+'}${pad(Math.floor(Math.abs(minutes) / 60))}:${pad(Math.abs(minutes) % 60)}`
}

function field(value: string) {
  document.body.innerHTML = `
    <form>
      <input type="datetime-local" name="publishedAt" value="${value}" data-utc="publishedAt-utc">
      <p id="publishedAt-utc">In UTC.</p>
    </form>`

  localTimes()

  return {
    shown: document.querySelector<HTMLInputElement>('[type=datetime-local]')!,
    posted: () => new FormData(document.querySelector('form')!).get('publishedAt'),
  }
}

beforeEach(() => (document.body.innerHTML = ''))

test('the stored UTC instant is shown in the browser zone and posted back as that instant', () => {
  const { shown, posted } = field('2026-10-07T12:30:45')

  expect(shown.value.slice(0, 19)).toBe('2026-10-07T14:30:45')
  expect(posted()).toBe('2026-10-07T14:30:45+02:00')
  expect(shown.hasAttribute('name')).toBe(false)
  expect(document.getElementById('publishedAt-utc')).toBeNull()
})

test('what is typed is posted with the browser offset', () => {
  const { shown, posted } = field('')

  shown.value = '2026-12-24T18:00'
  shown.dispatchEvent(new Event('input', { bubbles: true }))

  expect(posted()).toBe('2026-12-24T18:00:00+01:00')
  expect(offset('2026-12-24T18:00')).toBe('+01:00')
})

test('an empty field posts nothing to parse', () => {
  const { posted } = field('')

  expect(posted()).toBe('')
})
