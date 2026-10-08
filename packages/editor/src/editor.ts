import { baseKeymap, chainCommands, exitCode, lift, setBlockType, toggleMark, wrapIn } from 'prosemirror-commands'
import { history, redo, undo } from 'prosemirror-history'
import { keymap } from 'prosemirror-keymap'
import { type MarkType, Node, type NodeType } from 'prosemirror-model'
import { liftListItem, sinkListItem, splitListItem, wrapInList } from 'prosemirror-schema-list'
import { type Command, EditorState } from 'prosemirror-state'
import { EditorView } from 'prosemirror-view'
import { linkable, schema } from './schema'

/*
 | A rich text field's editor, mounted on the markup the field's component
 | draws: a hidden input holding the document as JSON, a toolbar, and the
 | document rendered read-only, which is all a browser without JavaScript gets.
 | The editor takes over the rendered element and writes the document back
 | into the input on every change, so the form posts it and the unsaved-changes
 | warning hears it. Until something changes, the input holds what the server
 | drew, byte for byte, and a save leaves the field out as unchanged.
 */

const { nodes, marks } = schema

type Tool = { run: Command; active?: (state: EditorState) => boolean }

const views = new WeakMap<HTMLElement, EditorView>()

/* Whether the selection sits inside a node of this type, at any depth. */
const inside = (type: NodeType) => (state: EditorState) => {
  const { $from } = state.selection

  for (let depth = $from.depth; depth > 0; depth--) {
    if ($from.node(depth).type === type) return true
  }

  return false
}

/* Whether what is typed next, or all of the selection, carries the mark. */
const marked = (type: MarkType) => (state: EditorState) => {
  const { from, to, empty, $from } = state.selection

  return empty ? type.isInSet(state.storedMarks ?? $from.marks()) !== undefined : state.doc.rangeHasMark(from, to, type)
}

/* On, or off again when it already is: a list or a quote lifted out of, a
   code block turned back into a paragraph. */
const toggle =
  (active: (state: EditorState) => boolean, on: Command, off: Command): Command =>
  (state, dispatch, view) =>
    (active(state) ? off : on)(state, dispatch, view)

/*
 | The link at the cursor, or at the start of the selection, with where it
 | runs: the whole link when the cursor or the selection is inside it, so
 | editing one edits all of it. A selection reaching past a link is the
 | selection, with the first link in it.
 */
type Range = { from: number; to: number }

function linked(state: EditorState): (Range & { href: string }) | null {
  const { from, to, empty, $from } = state.selection
  const mark = marks.link.isInSet(empty ? $from.marks() : ($from.nodeAfter?.marks ?? []))
  const start = $from.start()
  let run: Range | null = null
  let around = null as Range | null

  $from.parent.forEach((child, offset) => {
    const at = start + offset

    if (!mark?.isInSet(child.marks)) {
      run = null

      return
    }

    run = run !== null && run.to === at ? { from: run.from, to: at + child.nodeSize } : { from: at, to: at + child.nodeSize }

    if (run.from <= from && from <= run.to) around = run
  })

  if (mark && around !== null && to <= around.to) return { ...around, href: mark.attrs.href }

  if (empty) return null

  let href = null as string | null

  state.doc.nodesBetween(from, to, (node) => {
    href ??= marks.link.isInSet(node.marks)?.attrs.href ?? null
  })

  return href === null ? null : { from, to, href }
}

/*
 | Asks for a link's address in the admin's link dialog, drawn once outside the
 | entry's form so its own form can submit. An address linkable() refuses keeps
 | the dialog open saying so, as the schema would refuse the mark. On a link it
 | edits or removes the whole link; with nothing selected it inserts the
 | address as the link's text.
 */
function link(view: EditorView): void {
  const dialog = document.getElementById('mainstay-link')
  const form = dialog?.querySelector('form')
  const input = form?.elements.namedItem('href')
  const error = form?.querySelector<HTMLElement>('[data-link-error]')
  const remove = form?.querySelector<HTMLElement>('button[value="remove"]')

  if (!(dialog instanceof HTMLDialogElement) || !form || !(input instanceof HTMLInputElement)) return

  const found = linked(view.state)

  input.value = found?.href ?? ''
  if (error) error.hidden = true
  if (remove) remove.hidden = found === null

  /* One dialog serves every editor on the page: whichever opened it last
     answers it. */
  form.onsubmit = (event) => {
    const { from, to } = found ?? view.state.selection
    const { tr } = view.state

    if ((event.submitter as HTMLButtonElement | null)?.value === 'remove') {
      view.dispatch(tr.removeMark(from, to, marks.link))

      return
    }

    const href = input.value.trim()

    if (!linkable(href)) {
      event.preventDefault()
      if (error) error.hidden = false
      input.focus()

      return
    }

    const end = from === to ? from + href.length : to

    if (from === to) tr.insertText(href, from)

    view.dispatch(tr.removeMark(from, end, marks.link).addMark(from, end, marks.link.create({ href })))
  }

  dialog.addEventListener('close', () => view.focus(), { once: true })
  dialog.showModal()
}

const list = (type: NodeType) => toggle(inside(type), wrapInList(type), liftListItem(nodes.list_item))

/* The toolbar's buttons, by their `data-command`: the schema's nodes and
   marks, and no more. */
const tools: Record<string, Tool> = {
  strong: { run: toggleMark(marks.strong), active: marked(marks.strong) },
  em: { run: toggleMark(marks.em), active: marked(marks.em) },
  code: { run: toggleMark(marks.code), active: marked(marks.code) },
  link: {
    run: (state, dispatch, view) => {
      if (!toggleMark(marks.link)(state)) return false
      if (dispatch && view) link(view)

      return true
    },
    active: marked(marks.link),
  },
  bullet_list: { run: list(nodes.bullet_list), active: inside(nodes.bullet_list) },
  ordered_list: { run: list(nodes.ordered_list), active: inside(nodes.ordered_list) },
  blockquote: { run: toggle(inside(nodes.blockquote), wrapIn(nodes.blockquote), lift), active: inside(nodes.blockquote) },
  code_block: {
    run: toggle(inside(nodes.code_block), setBlockType(nodes.code_block), setBlockType(nodes.paragraph)),
    active: inside(nodes.code_block),
  },
  /* A document holds blocks, so there is always somewhere for a rule. */
  horizontal_rule: {
    run: (state, dispatch) => {
      dispatch?.(state.tr.replaceSelectionWith(nodes.horizontal_rule.create()).scrollIntoView())

      return true
    },
  },
}

/* The toolbar's select: what kind of text block the cursor is in. */
const blocks: Record<string, Command> = {
  paragraph: setBlockType(nodes.paragraph),
  'heading-2': setBlockType(nodes.heading, { level: 2 }),
  'heading-3': setBlockType(nodes.heading, { level: 3 }),
  'heading-4': setBlockType(nodes.heading, { level: 4 }),
}

const block = (state: EditorState): string => {
  const { parent } = state.selection.$from

  if (parent.type === nodes.paragraph) return 'paragraph'

  return parent.type === nodes.heading ? `heading-${parent.attrs.level}` : ''
}

/* Nothing written: one empty paragraph, which the field stores as no
   document rather than as a paragraph printing nothing. */
const blank = (doc: Node): boolean => doc.childCount === 1 && doc.firstChild?.type === nodes.paragraph && doc.firstChild.content.size === 0

const plugins = [
  history(),
  keymap({
    'Mod-z': undo,
    'Mod-y': redo,
    'Shift-Mod-z': redo,
    'Mod-b': tools.strong!.run,
    'Mod-i': tools.em!.run,
    'Mod-`': tools.code!.run,
    'Mod-k': tools.link!.run,
    /* Enter splits a list item, and leaves the rest to the base keymap. Tab
       is left to move focus, as everywhere else in the form. */
    Enter: splitListItem(nodes.list_item),
    'Mod-[': liftListItem(nodes.list_item),
    'Mod-]': sinkListItem(nodes.list_item),
    'Shift-Enter': chainCommands(exitCode, (state, dispatch) => {
      dispatch?.(state.tr.replaceSelectionWith(nodes.hard_break.create()).scrollIntoView())

      return true
    }),
  }),
  keymap(baseKeymap),
]

/*
 | Mounts the editor on one field's `[data-rich-text]`, once: a second call
 | hands back the view the first made. A document the schema cannot open --
 | written around the layer -- is left as drawn, read-only, posting what it
 | holds.
 */
export function editor(element: HTMLElement): EditorView | undefined {
  const mounted = views.get(element)

  if (mounted) return mounted

  const input = element.querySelector<HTMLInputElement>('input[type="hidden"]')
  const body = element.querySelector<HTMLElement>('[data-rich-text-body]')
  const toolbar = element.querySelector<HTMLElement>('[data-rich-text-toolbar]')

  if (!input || !body || !toolbar) return undefined

  let doc: Node

  try {
    doc = input.value.trim() === '' ? nodes.doc.createAndFill()! : Node.fromJSON(schema, JSON.parse(input.value))
    doc.check()
  } catch {
    return undefined
  }

  const select = toolbar.querySelector<HTMLSelectElement>('select[data-command="block"]')

  const sync = (state: EditorState) => {
    for (const button of toolbar.querySelectorAll<HTMLButtonElement>('button[data-command]')) {
      const tool = tools[button.dataset.command ?? '']

      if (!tool) continue

      button.disabled = !tool.run(state)
      if (tool.active) button.setAttribute('aria-pressed', String(tool.active(state)))
    }

    if (select) select.value = block(state)
  }

  /* What the server drew, which a document put back as it was writes
     again: the same bytes, so the form is clean and a save leaves it out. */
  const drawn = input.value
  const opened = doc

  body.replaceChildren()

  const view: EditorView = new EditorView(
    { mount: body },
    {
      state: EditorState.create({ doc, plugins }),
      attributes: { role: 'textbox', 'aria-multiline': 'true', 'aria-label': element.dataset.label ?? '' },
      dispatchTransaction(transaction) {
        view.updateState(view.state.apply(transaction))

        if (transaction.docChanged) {
          input.value = view.state.doc.eq(opened) ? drawn : blank(view.state.doc) ? '' : JSON.stringify(view.state.doc.toJSON())
          input.dispatchEvent(new Event('input', { bubbles: true }))
        }

        sync(view.state)
      },
    },
  )

  toolbar.addEventListener('click', (event) => {
    const button = (event.target as Element | null)?.closest<HTMLButtonElement>('button[data-command]')
    const tool = tools[button?.dataset.command ?? '']

    if (!tool) return

    tool.run(view.state, view.dispatch, view)
    if (button?.dataset.command !== 'link') view.focus()
  })

  select?.addEventListener('change', () => {
    blocks[select.value]?.(view.state, view.dispatch, view)
    sync(view.state)
    view.focus()
  })

  sync(view.state)
  toolbar.hidden = false
  views.set(element, view)

  return view
}

/* Every rich text field under `root` not yet mounted. */
export function editors(root: ParentNode = document): void {
  for (const element of root.querySelectorAll<HTMLElement>('[data-rich-text]')) editor(element)
}
