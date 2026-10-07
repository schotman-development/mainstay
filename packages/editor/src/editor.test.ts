import { undo } from 'prosemirror-history'
import { TextSelection } from 'prosemirror-state'
import type { EditorView } from 'prosemirror-view'
import { beforeEach, expect, test, vi } from 'vitest'
import fixture from '../../cms/tests/Fixtures/document.json'
import { editor } from './editor'
import { schema } from './schema'

/* The markup the rich text field's component draws, and the link dialog it
   pushes outside the form. jsdom has the dialog and not its modal part. */
beforeEach(() => {
  document.body.innerHTML = `
    <form>
      <div data-rich-text data-label="Body">
        <input type="hidden" name="body">
        <div data-rich-text-toolbar hidden>
          <select data-command="block">
            <option value="paragraph">Paragraph</option>
            <option value="heading-2">Heading 2</option>
            <option value="heading-3">Heading 3</option>
            <option value="heading-4">Heading 4</option>
          </select>
          <button type="button" data-command="strong">B</button>
          <button type="button" data-command="em">I</button>
          <button type="button" data-command="code">Code</button>
          <button type="button" data-command="link">Link</button>
          <button type="button" data-command="bullet_list">Bullets</button>
          <button type="button" data-command="ordered_list">Numbers</button>
          <button type="button" data-command="blockquote">Quote</button>
          <button type="button" data-command="code_block">Code block</button>
          <button type="button" data-command="horizontal_rule">Rule</button>
        </div>
        <div data-rich-text-body><p>Drawn by the server</p></div>
      </div>
    </form>
    <dialog id="mainstay-link">
      <form method="dialog">
        <input name="href">
        <p data-link-error hidden></p>
        <button type="submit" value="remove">Remove link</button>
        <button type="submit" value="apply">Apply</button>
      </form>
    </dialog>`

  const dialog = document.querySelector('dialog')!
  dialog.showModal = function () {
    this.setAttribute('open', '')
  }
})

/* Nor does it lay anything out, which scrolling the cursor into view asks. */
Range.prototype.getClientRects = () => ({ length: 0 }) as DOMRectList
Range.prototype.getBoundingClientRect = () => document.body.getBoundingClientRect()

const input = () => document.querySelector<HTMLInputElement>('input[name="body"]')!

function open(json: string): EditorView {
  input().value = json

  return editor(document.querySelector<HTMLElement>('[data-rich-text]')!)!
}

const paragraph = (text: string) => JSON.stringify({ type: 'doc', content: [{ type: 'paragraph', content: [{ type: 'text', text }] }] })

const select = (view: EditorView, from: number, to: number) =>
  view.dispatch(view.state.tr.setSelection(TextSelection.create(view.state.doc, from, to)))

const key = (view: EditorView, init: KeyboardEventInit) =>
  view.dom.dispatchEvent(new KeyboardEvent('keydown', { bubbles: true, cancelable: true, ...init }))

const tool = (command: string) => document.querySelector<HTMLButtonElement>(`[data-command="${command}"]`)!

const marks = (view: EditorView) => view.state.doc.firstChild!.firstChild!.marks.map((mark) => mark.type.name)

/* A tree with its keys sorted, as a database hands a json column back. */
const sorted = (value: unknown): unknown =>
  Array.isArray(value)
    ? value.map(sorted)
    : value !== null && typeof value === 'object'
      ? Object.fromEntries(Object.entries(value).sort(([a], [b]) => (a < b ? -1 : 1)).map(([key, inner]) => [key, sorted(inner)]))
      : value

test('opens the fixture, and writes it back as it was drawn', () => {
  const drawn = JSON.stringify(sorted(fixture))
  const view = open(drawn)

  expect(view.dom).toBe(document.querySelector('[data-rich-text-body]'))
  expect(view.dom.getAttribute('contenteditable')).toBe('true')
  expect(document.querySelector<HTMLElement>('[data-rich-text-toolbar]')!.hidden).toBe(false)
  /* Untouched, the input is what the server drew, so a save leaves it out. */
  expect(input().value).toBe(drawn)

  view.dispatch(view.state.tr.insertText('x', 1))

  expect(JSON.parse(input().value)).not.toEqual(fixture)

  /* Put back as it was, it is the same bytes again, in the column's key
     order rather than the editor's. */
  undo(view.state, view.dispatch)

  expect(input().value).toBe(drawn)
})

test('mounts once', () => {
  const view = open(paragraph('One'))

  expect(editor(document.querySelector<HTMLElement>('[data-rich-text]')!)).toBe(view)
  expect(document.querySelectorAll('.ProseMirror')).toHaveLength(1)
})

test('writes a change into the input, tells the form, and an emptied one as nothing', () => {
  const view = open('')
  let heard = 0

  document.querySelector('form')!.addEventListener('input', () => heard++)

  view.dispatch(view.state.tr.insertText('Hello', 1))

  expect(JSON.parse(input().value)).toEqual(JSON.parse(paragraph('Hello')))
  expect(heard).toBe(1)

  view.dispatch(view.state.tr.delete(1, 6))

  expect(input().value).toBe('')
  expect(heard).toBe(2)
})

test('a document emptied writes nothing, where it was drawn holding something', () => {
  const view = open(paragraph('Hello'))

  view.dispatch(view.state.tr.delete(1, 6))

  expect(input().value).toBe('')
})

test('the toolbar marks, wraps and sets the block, and shows what is on', () => {
  const view = open(paragraph('Hello'))
  const bold = document.querySelector<HTMLButtonElement>('[data-command="strong"]')!

  select(view, 1, 6)
  bold.click()

  expect(view.state.doc.firstChild!.firstChild!.marks.map((mark) => mark.type.name)).toEqual(['strong'])
  expect(bold.getAttribute('aria-pressed')).toBe('true')

  const block = document.querySelector<HTMLSelectElement>('[data-command="block"]')!

  block.value = 'heading-2'
  block.dispatchEvent(new Event('change'))

  expect(view.state.doc.firstChild!.type.name).toBe('heading')
  expect(block.value).toBe('heading-2')

  block.value = 'paragraph'
  block.dispatchEvent(new Event('change'))
  document.querySelector<HTMLButtonElement>('[data-command="bullet_list"]')!.click()

  expect(view.state.doc.firstChild!.type.name).toBe('bullet_list')
  expect(document.querySelector('[data-command="bullet_list"]')!.getAttribute('aria-pressed')).toBe('true')
})

test('Enter splits a list item, and Mod-] and Mod-[ indent and outdent it', () => {
  const view = open(paragraph('One'))

  document.querySelector<HTMLButtonElement>('[data-command="bullet_list"]')!.click()
  select(view, 6, 6)
  key(view, { key: 'Enter', keyCode: 13 })
  view.dispatch(view.state.tr.insertText('Two'))

  const list = () => view.state.doc.firstChild!

  expect(list().childCount).toBe(2)
  expect(list().child(1).textContent).toBe('Two')

  key(view, { key: ']', ctrlKey: true })

  expect(list().childCount).toBe(1)
  expect(list().child(0).lastChild!.type.name).toBe('bullet_list')

  key(view, { key: '[', ctrlKey: true })

  expect(list().childCount).toBe(2)
})

test('a link asks for its address, refuses one linkable() refuses, and removes', () => {
  const view = open(paragraph('Hello'))
  const dialog = document.querySelector('dialog')!
  const form = dialog.querySelector('form')!
  const href = form.querySelector<HTMLInputElement>('[name="href"]')!
  const submit = (value: string) => form.dispatchEvent(new SubmitEvent('submit', { cancelable: true, submitter: form.querySelector(`[value="${value}"]`) }))
  const links = () => view.state.doc.firstChild!.firstChild!.marks.filter((mark) => mark.type.name === 'link').map((mark) => mark.attrs.href)

  const error = form.querySelector<HTMLElement>('[data-link-error]')!
  const remove = form.querySelector<HTMLElement>('[value="remove"]')!
  const focus = vi.spyOn(view, 'focus')

  select(view, 1, 6)
  document.querySelector<HTMLButtonElement>('[data-command="link"]')!.click()

  expect(dialog.open).toBe(true)
  expect(href.value).toBe('')
  expect(remove.hidden).toBe(true)

  href.value = 'java\tscript:alert(1)'

  expect(submit('apply')).toBe(false)
  expect(form.querySelector<HTMLElement>('[data-link-error]')!.hidden).toBe(false)
  expect(links()).toEqual([])

  href.value = ' /about '
  submit('apply')

  expect(links()).toEqual(['/about'])

  /* Closed, the editor has the focus back. */
  dialog.dispatchEvent(new Event('close'))
  expect(focus).toHaveBeenCalled()

  /* Opened again from part of it, it edits the whole link, and the last
     refusal is gone. */
  select(view, 2, 4)
  document.querySelector<HTMLButtonElement>('[data-command="link"]')!.click()

  expect([href.value, error.hidden, remove.hidden]).toEqual(['/about', true, false])

  href.value = '/docs'
  submit('apply')

  expect(view.state.doc.firstChild!.childCount).toBe(1)
  expect(links()).toEqual(['/docs'])

  /* And from a cursor inside it, removes all of it. */
  select(view, 3, 3)
  document.querySelector<HTMLButtonElement>('[data-command="link"]')!.click()
  submit('remove')

  expect(view.state.doc.firstChild!.childCount).toBe(1)
  expect(links()).toEqual([])
})

test('leaves the input alone when only the selection moves', () => {
  const view = open(paragraph('Hello'))
  let heard = 0

  document.querySelector('form')!.addEventListener('input', () => heard++)
  select(view, 1, 3)

  expect(input().value).toBe(paragraph('Hello'))
  expect(heard).toBe(0)
})

test('leaves a document the schema cannot open as drawn, read-only', () => {
  for (const json of ['{"type":"doc","content":[{"type":"image"}]}', '{"type":"doc","content":[]}']) {
    document.querySelector('[data-rich-text-body]')!.innerHTML = '<p>Drawn by the server</p>'

    expect(open(json)).toBeUndefined()
    expect(document.querySelector<HTMLElement>('[data-rich-text-toolbar]')!.hidden).toBe(true)
    expect(document.querySelector('[data-rich-text-body]')!.textContent).toBe('Drawn by the server')
    expect(input().value).toBe(json)
  }
})

test('every tool turns on, and off again', () => {
  const view = open(paragraph('Hello'))

  for (const mark of ['em', 'code']) {
    select(view, 1, 6)
    tool(mark).click()
    expect(marks(view)).toEqual([mark])
    tool(mark).click()
    expect(marks(view)).toEqual([])
  }

  for (const wrapper of ['ordered_list', 'blockquote', 'bullet_list', 'code_block']) {
    select(view, 3, 3)
    tool(wrapper).click()
    expect(view.state.doc.firstChild!.type.name).toBe(wrapper)
    expect(tool(wrapper).getAttribute('aria-pressed')).toBe('true')
    /* A code block holds unmarked text, so a mark is not on offer there. */
    expect(tool('strong').disabled).toBe(wrapper === 'code_block')
    tool(wrapper).click()
    expect(view.state.doc.firstChild!.type.name).toBe('paragraph')
  }

  const block = document.querySelector<HTMLSelectElement>('[data-command="block"]')!

  const focus = vi.spyOn(view, 'focus')

  tool('em').click()
  expect(focus).toHaveBeenCalled()

  for (const level of [3, 4]) {
    block.value = `heading-${level}`
    block.dispatchEvent(new Event('change'))
    expect(view.state.doc.firstChild!.toJSON()).toMatchObject({ type: 'heading', attrs: { level } })
  }

  /* The select follows the cursor into another kind of block. */
  view.dispatch(view.state.tr.insert(view.state.doc.content.size, schema.nodes.paragraph.create()))
  select(view, view.state.doc.content.size - 1, view.state.doc.content.size - 1)
  expect(block.value).toBe('paragraph')
  select(view, 2, 2)
  expect(block.value).toBe('heading-4')

  tool('horizontal_rule').click()
  expect(view.state.doc.toJSON().content.map((node: { type: string }) => node.type)).toContain('horizontal_rule')
})

test('the keys: Mod-b, Mod-i, Mod-`, Shift-Enter and Mod-k', () => {
  const view = open(paragraph('Hello'))

  select(view, 1, 6)
  key(view, { key: 'b', ctrlKey: true })
  key(view, { key: 'i', ctrlKey: true })
  key(view, { key: '`', ctrlKey: true })
  expect(marks(view)).toEqual(['em', 'strong', 'code'])

  select(view, 3, 3)
  key(view, { key: 'Enter', keyCode: 13, shiftKey: true })
  expect(view.state.doc.firstChild!.toJSON().content.map((node: { type: string }) => node.type)).toContain('hard_break')

  key(view, { key: 'k', ctrlKey: true })
  expect(document.querySelector('dialog')!.open).toBe(true)
})

test('a link with nothing selected inserts its address as its text', () => {
  const view = open(paragraph('Hello '))
  const form = document.querySelector<HTMLFormElement>('dialog form')!

  select(view, 7, 7)
  tool('link').click()
  form.querySelector<HTMLInputElement>('[name="href"]')!.value = 'mailto:hi@example.com'
  form.dispatchEvent(new SubmitEvent('submit', { cancelable: true, submitter: form.querySelector('[value="apply"]') }))

  const text = view.state.doc.firstChild!.lastChild!

  expect(text.text).toBe('mailto:hi@example.com')
  expect(text.marks.map((mark) => mark.attrs.href)).toEqual(['mailto:hi@example.com'])
})
