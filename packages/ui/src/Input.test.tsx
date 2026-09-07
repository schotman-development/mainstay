import { act } from 'react'
import { createRoot } from 'react-dom/client'
import { expect, test } from 'vitest'
import { Checkbox } from './Checkbox'
import { TagInput } from './TagInput'
import { controlClass } from './Input'

function mount(node: React.ReactNode) {
  const host = document.createElement('div')
  document.body.append(host)

  const root = createRoot(host)
  act(() => root.render(node))

  return host
}

function type(input: HTMLInputElement, value: string) {
  const setter = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value')!.set!
  act(() => {
    setter.call(input, value)
    input.dispatchEvent(new Event('input', { bubbles: true }))
  })
}

function press(input: HTMLInputElement, key: string) {
  act(() => {
    input.dispatchEvent(new KeyboardEvent('keydown', { key, bubbles: true }))
  })
}

/* The whole reason Checkbox exists rather than a bare <input type="checkbox">:
   indeterminate is a DOM property with no attribute, so JSX cannot set it and a
   part-selected page would show an empty box. */
test('indeterminate reaches the DOM, where JSX cannot put it', () => {
  const host = mount(<Checkbox label="Select all" checked={false} indeterminate onChange={() => {}} />)
  const box = host.querySelector('input')!

  expect(box.indeterminate).toBe(true)
  expect(box.checked).toBe(false)
  expect(box.getAttribute('aria-label')).toBe('Select all')
})

test('indeterminate clears when it goes false', () => {
  const host = document.createElement('div')
  document.body.append(host)
  const root = createRoot(host)

  act(() => root.render(<Checkbox label="Select all" checked indeterminate onChange={() => {}} />))
  expect(host.querySelector('input')!.indeterminate).toBe(true)

  act(() => root.render(<Checkbox label="Select all" checked indeterminate={false} onChange={() => {}} />))
  expect(host.querySelector('input')!.indeterminate).toBe(false)
})

test('a tag commits on Enter and on comma', () => {
  let tags: string[] = []
  const host = mount(<TagInput tags={tags} onChange={(next) => (tags = next)} />)
  const input = host.querySelector('input')!

  type(input, 'editor')
  press(input, 'Enter')
  expect(tags).toEqual(['editor'])

  type(input, 'release')
  press(input, ',')
  expect(tags).toEqual(['release'])
})

/* "Editor" after "editor" would otherwise be a second tag that filters to a
   different set of entries. */
test('a tag that differs only in case is not added twice', () => {
  let tags = ['editor']
  const host = mount(<TagInput tags={tags} onChange={(next) => (tags = next)} />)
  const input = host.querySelector('input')!

  type(input, 'EDITOR')
  press(input, 'Enter')

  expect(tags).toEqual(['editor'])
})

/* A tag typed and left sitting in the box looks added and is not, and the save
   button is exactly one blur away. */
test('a tag left in the box commits on blur', () => {
  let tags: string[] = []
  const host = mount(<TagInput tags={tags} onChange={(next) => (tags = next)} />)
  const input = host.querySelector('input')!

  type(input, '  spacing  ')
  /* React delegates onBlur to the native focusout, which is the one that
     bubbles to its root listener. A plain 'blur' event never reaches it. */
  act(() => input.dispatchEvent(new FocusEvent('focusout', { bubbles: true })))

  expect(tags).toEqual(['spacing'])
})

test('Backspace on an empty input takes the last tag back', () => {
  let tags = ['one', 'two']
  const host = mount(<TagInput tags={tags} onChange={(next) => (tags = next)} />)
  const input = host.querySelector('input')!

  press(input, 'Backspace')
  expect(tags).toEqual(['one'])
})

test('Backspace with something typed leaves the tags alone', () => {
  let tags = ['one', 'two']
  let called = false
  const host = mount(
    <TagInput
      tags={tags}
      onChange={(next) => {
        called = true
        tags = next
      }}
    />,
  )
  const input = host.querySelector('input')!

  type(input, 'x')
  press(input, 'Backspace')

  expect(called).toBe(false)
  expect(tags).toEqual(['one', 'two'])
})

/* A bare control draws no border and no focus ring, because the InputShell
   around it draws both for the group. Two rings is the bug this prevents. */
test('bare drops the border, the ground and the focus ring', () => {
  const bare = controlClass({ bare: true })

  expect(bare).not.toContain('border-border')
  expect(bare).not.toContain('focus-visible:outline-accent')
  expect(bare).toContain('bg-transparent')

  expect(controlClass()).toContain('border-border')
  expect(controlClass()).toContain('focus-visible:outline-accent')
})

test('ground picks the colour the control sits against', () => {
  expect(controlClass({ ground: 'surface' })).toContain('bg-surface')
  expect(controlClass({ ground: 'canvas' })).toContain('bg-canvas')
})

/*
 | The three below all guard the same trap, which has already bitten once: a
 | class in the base cannot be overridden from className. Tailwind emits one
 | rule per utility at equal specificity, so the cascade is decided by
 | stylesheet order -- `.w-full` after `.w-48`, `.py-1.5` after `.py-0.5` --
 | and the caller loses no matter what it writes. The base therefore has to be
 | asked not to claim these, which is what fullWidth and size 'none' are for.
 */
test('fullWidth off leaves the width to the caller', () => {
  expect(controlClass({ fullWidth: false }, 'w-48')).not.toContain('w-full')
  expect(controlClass({}, 'w-48')).toContain('w-full')
})

test('bare brings no padding, so a shell sets the rhythm', () => {
  const bare = controlClass({ bare: true }, 'py-0.5')

  expect(bare).not.toContain('py-1.5')
  expect(bare).not.toContain('px-2.5')
  expect(bare).toContain('py-0.5')
})

test("size 'none' keeps the frame and drops the spacing", () => {
  const combobox = controlClass({ size: 'none' }, 'h-6.5 pl-8 pr-12')

  expect(combobox).not.toContain('px-2.5')
  expect(combobox).not.toContain('py-1.5')
  expect(combobox).toContain('border-border')
  expect(combobox).toContain('focus-visible:outline-accent')
})

/*
 | The exact class sets the four real call sites had before they were moved onto
 | this function. Order does not matter; membership does.
 */
test.each([
  [
    'the list search box',
    controlClass({ size: 'sm', ground: 'surface', fullWidth: false }, 'w-48'),
    'w-48 rounded-control border border-border bg-surface px-2.5 py-1 text-sm placeholder:text-muted focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent',
  ],
  [
    'a form control',
    controlClass(),
    'w-full rounded-control border border-border bg-canvas px-2.5 py-1.5 text-sm placeholder:text-muted/70 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent',
  ],
  [
    'the slug input inside its prefix shell',
    controlClass({ bare: true }, 'min-w-0 rounded-r-control px-2.5 py-1.5 font-mono text-xs'),
    'w-full min-w-0 rounded-r-control bg-transparent px-2.5 py-1.5 font-mono text-xs focus:outline-none',
  ],
  [
    'the tag input inside its chip shell',
    controlClass({ bare: true, fullWidth: false }, 'min-w-24 flex-1 py-0.5 text-sm placeholder:text-muted/70'),
    'min-w-24 flex-1 bg-transparent py-0.5 text-sm placeholder:text-muted/70 focus:outline-none',
  ],
  [
    "the command centre's combobox",
    controlClass({ size: 'none' }, 'h-6.5 pl-8 pr-12 text-xs text-ink placeholder:text-muted'),
    'w-full rounded-control border border-border bg-canvas h-6.5 pl-8 pr-12 text-xs text-ink placeholder:text-muted focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent',
  ],
])('%s keeps the classes it had before', (_name, got, want) => {
  const classes = (value: string) => value.split(/\s+/).filter(Boolean).sort()

  expect(classes(got)).toEqual(classes(want))
})
