import { baseKeymap, chainCommands } from 'prosemirror-commands'
import { history, isHistoryTransaction, redo, undo } from 'prosemirror-history'
import { keymap } from 'prosemirror-keymap'
import { DOMSerializer, Node, NodeRange } from 'prosemirror-model'
import { wrapRangeInList } from 'prosemirror-schema-list'
import { AllSelection, type Command, EditorState, NodeSelection, Plugin, Selection, TextSelection, type Transaction } from 'prosemirror-state'
import { Decoration, DecorationSet, type DecorationAttrs, EditorView, type NodeViewConstructor } from 'prosemirror-view'
import prosemirror from 'prosemirror-view/style/prosemirror.css?raw'
import { kit } from './editor'
import { contentSchema } from './schema'

/*
 | The Content tab: the page the site draws from the form as it stands, in a
 | frame the admin drives, and the editor on the content in it. Opening the
 | tab, and opening it again once Details changed, posts the form to the
 | canvas route and writes the page it answers into the frame. The editor
 | mounts on the element the content's script sits in, and every change goes
 | into Details' raw content, the field's one input. Every control is outside
 | the frame, where canvas.blade.php draws it: the toolbar floating by the
 | current block, which drags it too; Undo, Redo and Add block in the tabs'
 | bar; the menu of blocks to add; and the dialog a selected site block's
 | settings are in, which the server draws. A block's @field text is typed in
 | place, the blocks inside one are selected on the page, and an image it
 | prints is replaced and edited there. No script runs in the frame; this
 | one, around it, cancels its links.
 */

const { nodes } = contentSchema
const { tools, keys } = kit(contentSchema)

/* A block, where it is, and what holds it: the document, or the site block
   it is inside, at `depth`. */
type Current = { node: Node; pos: number; index: number; parent: Node; depth: number }

const at = (doc: Node, index: number) => doc.resolve(0).posAtIndex(index)

/* The block the block commands act on, outlined in the page, the toolbar
   floating by it: a site block selected, at any depth, or else the
   top-level block the selection starts in. */
function current(state: EditorState): Current {
  const { selection } = state

  if (selection instanceof NodeSelection && selection.node.type === nodes.block) {
    const { $from } = selection

    return { node: selection.node, pos: selection.from, index: $from.index(), parent: $from.parent, depth: $from.depth }
  }

  const index = Math.min(selection.$from.index(0), state.doc.childCount - 1)

  return { index, pos: at(state.doc, index), node: state.doc.child(index), parent: state.doc, depth: 0 }
}

/* Every site block in `doc`, the ones inside another too, in its order. */
const blocks = (doc: Node, each: (node: Node, pos: number) => void): void =>
  doc.descendants((node, pos) => {
    if (node.type !== nodes.block) return false

    each(node, pos)
  })

/* Whether the selection is on a site block inside another, where text has
   no place to go. */
const nested = (state: EditorState): boolean => state.selection instanceof NodeSelection && state.selection.$from.depth > 0

/* Where the selection goes in the block at `pos`: onto it when it is a site
   block or a rule, into its first text otherwise. */
const into = (doc: Node, pos: number): Selection => (doc.nodeAt(pos)?.isAtom ? NodeSelection.create(doc, pos) : Selection.near(doc.resolve(pos + 1)))

/* The block moved to before the one at `to` in what holds it, in one step
   undo takes back, the selection going with it; null where it would stay. */
function moved(state: EditorState, block: Current, to: number): Transaction | null {
  const { index, node, pos: from } = block

  if (to === index || to === index + 1) return null

  const { doc, selection } = state
  const tr = state.tr.delete(from, from + node.nodeSize)
  const pos = tr.mapping.map(doc.resolve(from).posAtIndex(to))
  const shift = (old: number) => pos + old - from

  tr.insert(pos, node)

  return tr
    .setSelection(
      selection instanceof NodeSelection
        ? NodeSelection.create(tr.doc, pos)
        : selection.from > from && selection.to < from + node.nodeSize
          ? TextSelection.create(tr.doc, shift(selection.anchor), shift(selection.head))
          : into(tr.doc, pos),
    )
    .scrollIntoView()
}

const move =
  (by: -1 | 1): Command =>
  (state, dispatch) => {
    const block = current(state)
    const to = by < 0 ? block.index - 1 : block.index + 2
    const tr = to < 0 || to > block.parent.childCount ? null : moved(state, block, to)

    if (tr) dispatch?.(tr)

    return tr !== null
  }

/* The copy keeps the id; ids() gives it one of its own. */
const duplicate: Command = (state, dispatch) => {
  const { node, pos } = current(state)
  const tr = state.tr.insert(pos + node.nodeSize, node)

  dispatch?.(tr.setSelection(into(tr.doc, pos + node.nodeSize)).scrollIntoView())

  return true
}

/* The last block goes too, an empty paragraph in its place, as a content
   holds one block at least. The last one inside a site block leaves it
   empty, and selected. */
const remove: Command = (state, dispatch) => {
  const { node, pos, index, depth } = current(state)
  const last = depth === 0 && state.doc.childCount === 1

  if (last && node.type === nodes.paragraph && node.content.size === 0) return false

  if (dispatch) {
    const tr = last ? state.tr.replaceWith(pos, pos + node.nodeSize, nodes.paragraph.create()) : state.tr.delete(pos, pos + node.nodeSize)
    const $pos = tr.doc.resolve(pos)
    const left = $pos.parent.childCount

    dispatch(tr.setSelection(left === 0 ? NodeSelection.create(tr.doc, $pos.before()) : into(tr.doc, $pos.posAtIndex(Math.min(index, left - 1)))).scrollIntoView())
  }

  return true
}

const kinds = ['paragraph', 'heading', 'blockquote', 'code_block', 'bullet_list', 'ordered_list', 'horizontal_rule']
const wrappers = [nodes.blockquote, nodes.bullet_list, nodes.ordered_list]

/* What the current block is, as Turn into names it: '' for a site block or a
   rule, which turn into nothing and have no text tools. */
function kind(state: EditorState): string {
  const { node } = current(state)

  if (node.type === nodes.heading) return `heading-${node.attrs.level}`

  return node.isTextblock || wrappers.includes(node.type) ? node.type.name : ''
}

/*
 | Turn into: the current block made another kind. A text block keeps the
 | cursor where it is; a quote or a list is taken apart into its text blocks
 | first, each one made the kind asked for, and wrapped again where that is a
 | quote -- as they are -- or a list, an item each.
 */
const turn =
  (to: string): Command =>
  (state, dispatch) => {
    const [name = '', level] = to.split('-')
    const type = kinds.includes(name) ? nodes[name] : undefined
    const was = kind(state)

    if (!type || type === nodes.horizontal_rule || was === '' || was === to) return false
    if (!dispatch) return true

    const { node, pos } = current(state)
    const { tr } = state
    let end = pos + node.nodeSize

    if (!node.isTextblock) {
      const texts: Node[] = []

      node.descendants((child) => {
        if (child.isTextblock) texts.push(child)

        return !child.isTextblock
      })
      tr.replaceWith(pos, end, texts).setSelection(into(tr.doc, pos))
      end = pos + texts.reduce((size, text) => size + text.nodeSize, 0)
    }

    if (type !== nodes.blockquote) {
      const steps = tr.steps.length

      tr.setBlockType(pos, end, wrappers.includes(type) ? nodes.paragraph : type, level === undefined ? null : { level: Number(level) })
      end = tr.mapping.slice(steps).map(end)
    }

    const range = new NodeRange(tr.doc.resolve(pos), tr.doc.resolve(end), 0)

    if (type === nodes.blockquote) tr.wrap(range, [{ type }])
    else if (wrappers.includes(type)) wrapRangeInList(tr, range, type)

    dispatch(tr.scrollIntoView())

    return true
  }

/* A new block of a kind the Add block menu names, or `block:{handle}`, a
   site block of that type with no id yet -- holding one of `inner`, the
   first kind it holds, where it holds any. */
function made(kind: string, inner?: string): Node | null {
  if (kind.startsWith('block:')) return nodes.block.create({ type: kind.slice(6) }, inner === undefined ? null : nodes.block.create({ type: inner }))

  return kinds.includes(kind) ? (nodes[kind]?.createAndFill() ?? null) : null
}

/* A block added after the current one, in what holds it, or in its place
   when that is an empty paragraph -- where `/` opens the menu. */
const insert =
  (kind: string, inner?: string): Command =>
  (state, dispatch) => {
    const block = made(kind, inner)
    const { node, pos, index, parent } = current(state)

    if (!block || !parent.canReplaceWith(index + 1, index + 1, block.type)) return false

    if (dispatch) {
      const empty = node.type === nodes.paragraph && node.content.size === 0
      const place = empty ? pos : pos + node.nodeSize
      const tr = state.tr.replaceWith(place, empty ? pos + node.nodeSize : place, block)

      dispatch(tr.setSelection(into(tr.doc, place)).scrollIntoView())
    }

    return true
  }

/* A block of `kind` added last inside the site block at `pos`, which stays
   selected. */
const append =
  (pos: number, kind: string, inner?: string): Command =>
  (state, dispatch) => {
    const holder = state.doc.nodeAt(pos)
    const block = made(`block:${kind}`, inner)

    if (!holder || !block) return false

    dispatch?.(state.tr.insert(pos + holder.nodeSize - 1, block).scrollIntoView())

    return true
  }

/* Enter on a selected site block or rule starts a paragraph after it; on
   one inside another, where a paragraph has no place, it does nothing --
   ProseMirror's own would split the block around it in two. */
const after: Command = (state, dispatch) => {
  const { selection } = state

  if (nested(state)) return true
  if (!(selection instanceof NodeSelection) || selection.$from.depth !== 0) return false

  const tr = state.tr.insert(selection.to, nodes.paragraph.create())

  dispatch?.(tr.setSelection(TextSelection.create(tr.doc, selection.to + 1)).scrollIntoView())

  return true
}

/* Enter at the end of a code block that already ends in an empty line
   leaves it, for a paragraph after it. */
const leave: Command = (state, dispatch) => {
  const { $from, empty } = state.selection
  const { parent } = $from

  if (!empty || !parent.type.spec.code || $from.parentOffset !== parent.content.size || !parent.textContent.endsWith('\n')) return false

  if (dispatch) {
    const tr = state.tr.delete($from.pos - 1, $from.pos)
    const pos = tr.mapping.map($from.after())

    tr.insert(pos, nodes.paragraph.create())
    dispatch(tr.setSelection(TextSelection.create(tr.doc, pos + 1)).scrollIntoView())
  }

  return true
}

/* Backspace in an empty block of the page's own removes it, the cursor going
   to the end of the one before, or to the start of the next when it was the
   first. */
const emptied: Command = (state, dispatch) => {
  const { $from, empty } = state.selection

  if (!empty || $from.depth !== 1 || !$from.parent.isTextblock || $from.parent.content.size > 0 || state.doc.childCount === 1) return false

  if (dispatch) {
    const pos = $from.before(1)
    const tr = state.tr.delete(pos, pos + $from.parent.nodeSize)

    dispatch(tr.setSelection($from.index(0) === 0 ? Selection.atStart(tr.doc) : Selection.near(tr.doc.resolve(pos), -1)).scrollIntoView())
  }

  return true
}

const random = () => Array.from(crypto.getRandomValues(new Uint8Array(13)), (byte) => byte.toString(16).padStart(2, '0')).join('')

/*
 | Every site block its own id, as Content.php's distinct rule asks, the ones
 | inside another too: one added without, and the second of two -- a
 | duplicate, a paste -- given one, which draws the HTML the first one drew,
 | its @field text and images marked as its own. The block that was there
 | keeps its own, so a paste before it does not take it.
 */
function ids(html: Map<string, string>): Plugin {
  return new Plugin({
    appendTransaction(transactions, old, state) {
      if (!transactions.some((tr) => tr.docChanged)) return null

      const there = new Set<Node>()
      const holders = new Map<string, number>()
      const { tr } = state

      blocks(old.doc, (node) => there.add(node))
      blocks(state.doc, (node, pos) => {
        if (node.attrs.id && there.has(node) && !holders.has(node.attrs.id)) holders.set(node.attrs.id, pos)
      })
      blocks(state.doc, (node, pos) => {
        const id: string | null = node.attrs.id || null

        if (id !== null && (holders.get(id) ?? holders.set(id, pos).get(id)) === pos) return

        const given = random()

        if (id !== null && html.has(id)) html.set(given, html.get(id)!.replaceAll(`data-mainstay-item="${id}"`, `data-mainstay-item="${given}"`))
        tr.setNodeAttribute(pos, 'id', given)
      })

      return tr.docChanged ? tr : null
    },
  })
}

type Data = Record<string, unknown>

/* Data as JSON, each object's keys in order: the server answers a block's
   data in an order of its own. */
const keyed = (data: unknown): string =>
  JSON.stringify(data, (_, each: unknown) => (each && typeof each === 'object' && !Array.isArray(each) ? Object.fromEntries(Object.entries(each).sort(([a], [b]) => (a < b ? -1 : 1))) : each))

/* What @field text says, its lines kept where its field takes them. */
const said = (span: HTMLElement): string => {
  /* jsdom has no innerText. */
  const text = (span.innerText as string | undefined) ?? span.textContent ?? ''

  return span.hasAttribute('data-mainstay-lines') ? text : text.replace(/\s*\n\s*/g, ' ')
}

/* What the admin adds to the page: ProseMirror's own, the current block's
   outline -- inside a block inside another, which the blocks beside it
   would cover, and as on any block where it is a list item, which
   ProseMirror's own marks as one -- a refused block's mark, the placeholder of a block that
   draws nothing, a block's HTML spaced as on the site rather than with the
   line breaks ProseMirror keeps in typed text, @field text, keeping them,
   and showing its field's label while it is empty, and a block's image,
   which is clicked to be replaced or edited. */
const css = `${prosemirror}
.ProseMirror { outline: none }
.mainstay-current { outline: 1px dashed rgb(37 99 235 / 0.6); outline-offset: 4px }
.mainstay-invalid { outline: 2px solid rgb(220 38 38); outline-offset: 4px }
.ProseMirror-selectednode { outline: 2px solid rgb(37 99 235); outline-offset: 4px }
[data-mainstay-holds] > :is(.mainstay-current, .mainstay-invalid, .ProseMirror-selectednode) { outline-offset: -2px }
[data-mainstay-holds] > li.ProseMirror-selectednode { outline: 2px solid rgb(37 99 235) }
[data-mainstay-holds] > li.ProseMirror-selectednode::after { content: none }
[data-mainstay-placeholder] { padding: 1rem; border: 1px dashed #94a3b8; border-radius: 4px; color: #475569; font: 14px/1.4 system-ui, sans-serif; text-align: center }
[contenteditable=false] { white-space: normal }
[data-mainstay-field] { outline: none; cursor: text; white-space: pre-wrap }
[data-mainstay-field]:empty::before { content: attr(data-mainstay-label); opacity: 0.5 }
[data-mainstay-image] { cursor: pointer }`

const commands: Record<string, Command> = {
  undo,
  redo,
  strong: tools.strong!.run,
  em: tools.em!.run,
  code: tools.code!.run,
  link: tools.link!.run,
  add: () => true,
  settings: (state) => current(state).node.type === nodes.block,
  up: move(-1),
  down: move(1),
  duplicate,
  delete: remove,
}

const parse = (html: string, page: Document): DocumentFragment => {
  const template = page.createElement('template')

  template.innerHTML = html

  return page.importNode(template.content, true)
}

/* The content as the site draws it, for a page the editor cannot mount on:
   the text by the schema, which draws the tags Document.php renders, and
   each site block as its view drew it, the blocks inside it in its place
   for them. */
export function render(doc: Node, html: Map<string, string>, page: Document): DocumentFragment {
  const serializer = DOMSerializer.fromSchema(contentSchema)
  const fragment = page.createDocumentFragment()
  const drawn = (node: Node): DocumentFragment => {
    const markup = parse(html.get(node.attrs.id) ?? '', page)
    const inner = page.createDocumentFragment()

    node.forEach((child) => inner.append(drawn(child)))
    markup.querySelector('template[data-mainstay-inner]')?.replaceWith(inner)

    return markup
  }

  doc.forEach((node) => fragment.append(node.type === nodes.block ? drawn(node) : serializer.serializeNode(node, { document: page })))

  return fragment
}

type Line = { top: number; left: number; width: number; height: number }

/*
 | Where a block dragged to (`x`, `y`) in the frame lands among the blocks
 | beside it, in what holds it: before the first one the point is before, or
 | last; and the line drawn there. Blocks one under another are passed by
 | their middle across; blocks side by side, as a grid's are, by their
 | middle down, on their row.
 */
function landing(view: EditorView, block: Current, x: number, y: number): { index: number; line: Line } | null {
  const $pos = view.state.doc.resolve(block.pos)
  const rects = Array.from({ length: block.parent.childCount }, (_, index) => (view.nodeDOM($pos.posAtIndex(index)) as Element | null)?.getBoundingClientRect())
  const row = rects.some((rect, index) => index > 0 && rect && rects[index - 1] && rect.top < rects[index - 1]!.bottom - 1)
  let last: DOMRect | undefined

  for (const [index, rect] of rects.entries()) {
    if (!rect) continue

    const before = row ? y < rect.top || (y <= rect.bottom && x < rect.left + rect.width / 2) : y < rect.top + rect.height / 2

    if (before) return { index, line: row ? { top: rect.top, left: rect.left - 2, width: 2, height: rect.height } : { top: rect.top - 1, left: rect.left, width: rect.width, height: 2 } }

    last = rect
  }

  return last ? { index: rects.length, line: row ? { top: last.top, left: last.right, width: 2, height: last.height } : { top: last.bottom - 1, left: last.left, width: last.width, height: 2 } } : null
}

const wired = new WeakMap<HTMLElement, () => EditorView | null>()

/* What the block route answers: as much of it as it could draw. */
type Answer = { block?: { id: string; type: string; data: Data }; html?: string; form?: string | null; messages?: string[] }

/* A block the content holds: its name, and the blocks it holds inside it. */
type Kind = { label: string; inner: string[] }

/*
 | Wires one `[data-canvas]`, once, handing back what gives the editor on its
 | page, while there is one: a second call, the first one's. `wire` gives the
 | admin's behaviour to what the server draws into a block's settings and
 | the image dialog.
 */
export function canvas(element: HTMLElement, wire: (root: HTMLElement) => void = () => {}): (() => EditorView | null) | undefined {
  const form = element.closest('form')
  const textarea = form?.querySelector<HTMLTextAreaElement>('textarea[name="content"]')
  const header = document.getElementById(element.dataset.header ?? '')
  const toolbar = element.querySelector<HTMLElement>('[data-canvas-toolbar]')
  const message = element.querySelector<HTMLElement>('[data-canvas-message]')
  const stage = element.querySelector<HTMLElement>('[data-canvas-stage]')
  const frame = element.querySelector<HTMLIFrameElement>('[data-canvas-frame]')
  const grip = element.querySelector<HTMLElement>('[data-canvas-grip]')
  const line = element.querySelector<HTMLElement>('[data-canvas-line]')
  const menu = element.querySelector<HTMLElement>('[data-canvas-menu]')
  const add = header?.querySelector<HTMLButtonElement>('button[data-command="add"]')
  const turner = toolbar?.querySelector<HTMLSelectElement>('select[data-command="turn"]')
  const texts = toolbar?.querySelector<HTMLElement>('[data-canvas-text]')
  const images = toolbar?.querySelector<HTMLElement>('[data-canvas-image]')
  const gear = toolbar?.querySelector<HTMLElement>('button[data-command="settings"]')
  const url = element.dataset.canvasUrl
  const blocked = element.dataset.blockUrl
  const sidebar = blocked ? (document.getElementById(element.dataset.sidebar ?? '') as HTMLFormElement | null) : null
  const settings = sidebar?.closest('dialog')
  const body = sidebar?.querySelector<HTMLElement>('[data-sidebar-body]')
  const told = sidebar?.querySelector<HTMLElement>('[data-sidebar-messages]')
  const title = sidebar?.querySelector<HTMLElement>('[data-sidebar-title]')
  const inside = sidebar?.querySelector<HTMLElement>('[data-sidebar-inner]')
  const photo = element.dataset.media ? (document.getElementById('mainstay-image') as HTMLDialogElement | null) : null
  const photoBody = photo?.querySelector<HTMLElement>('[data-image-body]')

  if (wired.has(element)) return wired.get(element)
  if (!form || !textarea || !header || !toolbar || !message || !stage || !frame || !grip || !line || !menu || !add || !turner || !texts || !images || !url) return undefined

  const known = JSON.parse(element.dataset.blocks ?? '{}') as Record<string, Kind>
  const outermost = JSON.parse(element.dataset.top ?? '[]') as string[]
  const label = (type: string) => known[type]?.label ?? type
  /* A refused save's errors, by the block they are in: marked until it changes. */
  const invalid = JSON.parse(element.dataset.invalid ?? '{}') as Record<string, { label: string; messages: Record<string, string[]> }>
  const panel = element.closest<HTMLElement>('[role="tabpanel"]')
  const html = new Map<string, string>()
  /* By block, how often its HTML was replaced, which redraws it, and the
     blocks to redraw once the text typed in them loses the focus. */
  const versions = new Map<string, number>()
  const held = new Set<string>()
  /* By block, the last change posted. */
  const changes = new Map<string, number>()
  let state: EditorState | null = null
  let view: EditorView | null = null
  /* The document the editor opened and the text it opened from, which a
     document put back as it was writes again, so the form is clean. */
  let opened: { doc: Node; text: string } | null = null
  /* What the editor last left in the textarea: anything else is a hand edit,
     which the editor opens rather than keeping its own. */
  let written: string | null = null
  let posted: string | null = null
  let asked = 0
  let dragging: Current | null = null
  let slashed = false
  /* The toolbar keeps out of the way until the page is pointed at, and
     while it is typed in, as Gutenberg's does. */
  let quiet = true
  /* The block the settings are for, the last time they were asked for, and
     the change waiting to be posted. */
  let selected: string | null = null
  let looked = 0
  let waiting: ReturnType<typeof setTimeout> | undefined
  /* Each stretch of typing in one @field, which undo takes back whole, and
     the one a change still to be answered was typed in. */
  let typing = 0
  let typed: string | undefined
  /* The image pointed at last, by its block and its field, and the block
     whose image the image dialog has open. */
  let pictured: { item: string; field: string } | null = null
  let editing: string | null = null

  const say = (text: string | null) => {
    message.textContent = text ?? ''
    message.hidden = text === null
  }

  const snapshot = () => JSON.stringify([...new FormData(form)].map(([name, value]) => [name, typeof value === 'string' ? value : value.name]))

  /* The block `id` names in the editor's document, and where. */
  const find = (id: string): { node: Node; pos: number } | null => {
    let found: { node: Node; pos: number } | null = null

    if (view) {
      blocks(view.state.doc, (node, pos) => {
        if (!found && node.attrs.id === id) found = { node, pos }
      })
    }

    return found
  }

  /* What the site blocks around `pos` hold, outermost first: what the
     block route draws one inside another in. */
  const ancestry = (pos: number): unknown[] => {
    const $pos = view!.state.doc.resolve(pos)

    return Array.from({ length: $pos.depth }, (_, depth) => $pos.node(depth + 1).attrs)
  }

  /* The image the toolbar replaces and edits: in the selected block, the one
     pointed at last, or its first. */
  const image = (): { field: string; media: unknown } | null => {
    const at = selected === null ? null : find(selected)
    const dom = at && view ? (view.nodeDOM(at.pos) as HTMLElement | null) : null
    const own = (dom ? [dom, ...dom.querySelectorAll<HTMLElement>('[data-mainstay-image]')] : [])
      .filter((each) => each.matches('[data-mainstay-image]') && each.dataset.mainstayItem === selected)
      .map((each) => each.dataset.mainstayImage ?? '')
    const field = pictured?.item === selected && own.includes(pictured.field) ? pictured.field : own[0]

    return at && field !== undefined ? { field, media: (at.node.attrs.data as Data)[field] } : null
  }

  /* The toolbar by the current block, over the frame: above it, kept at the
     top while the block runs on under it, and gone with the block out of
     view. Left where it is during a drag, which hiding the grip would end. */
  const place = () => {
    if (dragging !== null) return

    const dom = view && !quiet ? (view.nodeDOM(current(view.state).pos) as Element | null) : null
    const rect = dom?.getBoundingClientRect()

    toolbar.hidden = !rect || rect.bottom <= 0 || rect.top >= frame.clientHeight
    if (!rect || toolbar.hidden) return

    toolbar.style.top = `${Math.min(Math.max(0, rect.top - toolbar.offsetHeight - 6), rect.bottom - toolbar.offsetHeight)}px`
    toolbar.style.left = `${Math.max(0, Math.min(rect.left, frame.clientWidth - toolbar.offsetWidth))}px`
  }

  /* The toolbar back once the page is pointed at again. */
  const wake = () => {
    if (!quiet) return

    quiet = false
    place()
  }

  const sync = () => {
    header.hidden = view === null || panel?.hidden === true

    if (!view) {
      toolbar.hidden = true

      return
    }

    for (const button of [...header.querySelectorAll<HTMLButtonElement>('button[data-command]'), ...toolbar.querySelectorAll<HTMLButtonElement>('button[data-command]')]) {
      const name = button.dataset.command ?? ''
      const active = tools[name]?.active

      if (commands[name]) button.disabled = !commands[name](view.state)
      if (active) button.setAttribute('aria-pressed', String(active(view.state)))
    }

    turner.value = kind(view.state)
    turner.disabled = texts.hidden = turner.value === ''
    images.hidden = !photo || image() === null
    if (gear) gear.hidden = !sidebar || !commands.settings!(view.state)
    place()
  }

  const ended = () => {
    dragging = null
    line.hidden = true
    place()
  }

  const items = () => [...menu.querySelectorAll<HTMLButtonElement>('[data-insert]')].filter((item) => !item.hidden)

  /* The menu's items for where the current block is: inside a site block,
     the blocks that one holds; at the top, text and the blocks the content
     holds there. */
  const offer = () => {
    const block = view ? current(view.state) : null
    const holder: string | null = block && block.depth > 0 ? block.parent.attrs.type : null
    const allowed = holder === null ? outermost : (known[holder]?.inner ?? [])

    for (const item of menu.querySelectorAll<HTMLButtonElement>('[data-insert]')) {
      const kind = item.dataset.insert ?? ''

      item.hidden = kind.startsWith('block:') ? !allowed.includes(kind.slice(6)) : holder !== null
    }

    for (const group of menu.querySelectorAll<HTMLElement>('[data-menu-group]')) group.hidden = group.querySelector('[data-insert]:not([hidden])') === null
  }

  /* The Add block menu at a point on the admin's page, kept inside the tab. */
  const open = (top: number, left: number, slash: boolean) => {
    const box = element.getBoundingClientRect()

    offer()
    slashed = slash
    menu.hidden = false
    menu.style.top = `${top - box.top}px`
    menu.style.left = `${Math.max(0, Math.min(left - box.left, box.width - menu.offsetWidth))}px`
    add.setAttribute('aria-expanded', 'true')
    items()[0]?.focus()
  }

  const close = () => {
    menu.hidden = true
    add.setAttribute('aria-expanded', 'false')
  }

  /* `/` at the start of an empty paragraph: the menu, at the cursor. */
  const slash: Command = (state) => {
    const { $from, empty } = state.selection

    if (!view || !empty || $from.depth !== 1 || $from.parent.type !== nodes.paragraph || $from.parent.content.size > 0) return false

    const caret = view.coordsAtPos($from.pos)
    const box = frame.getBoundingClientRect()

    open(box.top + caret.bottom + 4, box.left + caret.left, true)

    return true
  }

  /*
   | The current block outlined; a block a refused save named marked until it
   | changes; and a block whose HTML was replaced given its count, which makes
   | ProseMirror ask its view to update, and the view draw it again.
   */
  const marks = new Plugin({
    props: {
      decorations(state) {
        const { node, pos } = current(state)
        const found = [Decoration.node(pos, pos + node.nodeSize, { class: 'mainstay-current' })]

        blocks(state.doc, (child, offset) => {
          const attrs: DecorationAttrs = {}

          if (invalid[child.attrs.id]) attrs.class = 'mainstay-invalid'
          if (versions.has(child.attrs.id)) attrs['data-mainstay-drawn'] = String(versions.get(child.attrs.id))
          if (Object.keys(attrs).length > 0) found.push(Decoration.node(offset, offset + child.nodeSize, attrs))
        })

        return DecorationSet.create(state.doc, found)
      },
      /* A click selects the innermost site block under it, as the one
         around it would be selected otherwise. */
      handleClickOn(view, _, node, pos) {
        if (node.type !== nodes.block) return false

        view.dispatch(view.state.tr.setSelection(NodeSelection.create(view.state.doc, pos)))

        return true
      },
      /* Text typed or pasted over a site block inside another has no place
         there: ProseMirror's own would split the block around it in two. A
         copied block goes in. */
      handleTextInput: (view) => nested(view.state),
      handlePaste: (view, _, slice) => nested(view.state) && !slice.content.content.every((node) => node.type === nodes.block),
    },
  })

  /*
   | A site block as its view drew it, an island the editor does not edit: the
   | element itself where the HTML is one, so the page's own CSS spaces it, and
   | wrapped where it is more. Drawing nothing -- or nothing yet, added since
   | the page was drawn -- it is a placeholder naming it. The element its
   | @inner() marked holds the blocks inside it, each drawn as its own island,
   | and nothing else. None of it is editable but its @field text, which is
   | typed in place, the events in it the admin's alone, and says what the
   | block's data does, whoever changed it.
   */
  const island: NodeViewConstructor = (node, view) => {
    const page = view.dom.ownerDocument
    const id: string = node.attrs.id
    const version = versions.get(id) ?? 0
    const drawn = parse(html.get(id) ?? '', page)
    const only = drawn.firstElementChild
    const alone = only !== null && !only.matches('template') && [...drawn.childNodes].every((child) => child === only || (child.nodeType !== 1 && (child.textContent ?? '').trim() === ''))
    let dom = only as HTMLElement | null

    if ((html.get(id) ?? '').trim() === '') {
      dom = Object.assign(page.createElement('div'), { textContent: label(node.attrs.type) })
      dom.setAttribute('data-mainstay-placeholder', '')
    } else if (!alone) {
      dom = page.createElement('div')
      dom.append(drawn)
    }

    const contentDOM = dom!.querySelector('template[data-mainstay-inner]')?.parentElement ?? undefined
    const spans = [...dom!.querySelectorAll<HTMLElement>('[data-mainstay-field]')].filter((span) => span.dataset.mainstayItem === id)
    const fill = (data: Data) => {
      for (const span of spans) {
        const text = String(data[span.dataset.mainstayField ?? ''] ?? '')

        if (said(span) !== text) span.textContent = text
      }
    }

    contentDOM?.replaceChildren()
    contentDOM?.setAttribute('data-mainstay-holds', '')
    dom!.setAttribute('contenteditable', 'false')
    for (const span of spans) span.setAttribute('contenteditable', 'plaintext-only')
    fill(node.attrs.data)

    return {
      dom: dom!,
      contentDOM,
      update: (next) => {
        if (next.type !== node.type || next.attrs.id !== id || (versions.get(id) ?? 0) !== version) return false

        fill(next.attrs.data)

        return true
      },
      stopEvent: (event) => (event.target as Element | null)?.closest?.('[data-mainstay-field]') != null,
      ignoreMutation: () => true,
      /* Not made draggable, as ProseMirror makes a selected node: a drag in
         its text selects the text. The toolbar's grip moves it. */
      selectNode: () => dom!.classList.add('ProseMirror-selectednode'),
      deselectNode: () => dom!.classList.remove('ProseMirror-selectednode'),
    }
  }

  const plugins = [history(), keymap({ Enter: chainCommands(after, leave), Backspace: emptied, '/': slash }), keymap(keys), keymap(baseKeymap), ids(html), marks]

  /* A block's data as given, in one step of its own: the settings', or
     `group`'s, a stretch of typing in place. */
  const store = (id: string, data: Data, group?: string) => {
    const at = find(id)

    if (view && at && keyed(at.node.attrs.data) !== keyed(data)) {
      view.dispatch(view.state.tr.setNodeAttribute(at.pos, 'data', data).setMeta('composition', group))
    }
  }

  /* Each block whose HTML was replaced drawn again, but one whose text is
     being typed in, which is drawn once the focus leaves it. */
  const flush = () => {
    /* The frame keeps its active element when the focus goes outside it. */
    const page = frame.contentDocument
    const active = page?.hasFocus() ? page.activeElement?.closest('[data-mainstay-field]') : null
    let drawn = false

    for (const id of held) {
      const at = find(id)

      if (!view || (active && at && (view.nodeDOM(at.pos) as Element | null)?.contains(active))) continue

      held.delete(id)
      versions.set(id, (versions.get(id) ?? 0) + 1)
      drawn = true
    }

    if (drawn) view?.dispatch(view.state.tr)
  }

  const redraw = (id: string, markup: string) => {
    if (html.get(id) === markup) return

    html.set(id, markup)
    held.add(id)
    flush()
  }

  /* No longer named by a refused save: changed since. */
  const forgive = (id: string) => {
    const list = element.querySelector('[data-canvas-invalid]')

    delete invalid[id]
    for (const each of element.querySelectorAll(`[data-invalid-block="${id}"]`)) each.remove()
    if (list && list.children.length === 0) list.remove()
  }

  /* What the server says of the selected block, above its fields. */
  const tell = (messages: string[] | undefined) => {
    if (!told) return

    told.replaceChildren(...(messages ?? []).map((text) => Object.assign(document.createElement('p'), { textContent: text })))
    told.hidden = !messages?.length
  }

  /* A block posted to the block route: answered, or why not. */
  const send = async (data: FormData): Promise<Answer> => {
    data.set('_token', form.querySelector<HTMLInputElement>('input[name="_token"]')?.value ?? '')

    try {
      const response = await fetch(blocked!, { method: 'POST', body: data, headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, redirect: 'manual' })

      if (response.type === 'opaqueredirect' || response.status === 401 || response.status === 419) {
        return { messages: ['Your session has ended. Sign in again in another tab, then select the block again: what you changed is kept in this browser.'] }
      }

      return ((await response.json().catch(() => null)) as Answer | null) ?? { messages: [`The block could not be drawn: the server answered ${response.status}.`] }
    } catch {
      return { messages: ['The block could not be reached. Select it again to try once more.'] }
    }
  }

  /* The settings' fields, as the server drew them for the block. */
  const show = (markup: string) => {
    body!.innerHTML = markup
    wire(body!)
  }

  /* The settings' name, and their list of the blocks inside the selected
     one, each selected on the page from there; with `kinds`, a button
     adding each kind it holds. */
  const listed = (kinds = false) => {
    const at = selected === null ? null : find(selected)
    const holds = at ? (known[at.node.attrs.type]?.inner ?? []) : []
    const list = inside?.querySelector<HTMLElement>('[data-sidebar-list]')
    const adder = inside?.querySelector<HTMLElement>('[data-sidebar-add]')
    const row = inside?.querySelector<HTMLTemplateElement>('template[data-sidebar-item]')
    const button = inside?.querySelector<HTMLTemplateElement>('template[data-sidebar-adder]')

    if (title) title.textContent = at ? label(at.node.attrs.type) : 'Block'
    if (!inside || !list || !adder || !row || !button) return

    inside.hidden = holds.length === 0
    list.replaceChildren()
    at?.node.forEach((child) => {
      const item = row.content.cloneNode(true) as DocumentFragment
      const select = item.querySelector<HTMLElement>('[data-inner-select]')!
      const named = Object.values(child.attrs.data as Data).find((value) => typeof value === 'string' && value.trim() !== '')

      select.dataset.innerSelect = child.attrs.id
      select.textContent = named === undefined ? label(child.attrs.type) : `${label(child.attrs.type)}: ${String(named)}`
      list.append(item)
    })

    if (!kinds) return

    adder.replaceChildren(
      ...holds.map((kind) => {
        const each = (button.content.cloneNode(true) as DocumentFragment).firstElementChild as HTMLElement

        each.dataset.innerAdd = kind
        each.textContent = `Add ${label(kind).toLowerCase()}`

        return each
      }),
    )
  }

  /*
   | A block as the document holds it, posted for its settings once it is
   | selected -- with a refused save's messages, while it is what was
   | refused -- and for its HTML once an undo or a redo changed it, or its
   | image did. Changed again before the answer, it is posted again.
   */
  const look = async (id: string): Promise<void> => {
    const at = find(id)
    const ask = id === selected ? ++looked : looked
    const data = new FormData()

    if (!at) return

    data.set('block', JSON.stringify(at.node.attrs))
    data.set('ancestors', JSON.stringify(ancestry(at.pos)))
    if (invalid[id]) data.set('errors', JSON.stringify(invalid[id].messages))

    const answer = await send(data)
    const now = find(id)

    if (answer.html !== undefined && now?.node.attrs.data === at.node.attrs.data) redraw(id, answer.html)
    if (id !== selected || ask !== looked) return

    /* Settings drawn from data typed over since would post it back. */
    if (now && now.node.attrs.data !== at.node.attrs.data) return look(id)

    if (typeof answer.form === 'string') show(answer.form)
    tell(answer.form ? undefined : answer.messages)
  }

  /*
   | The settings posted after one of its fields changed. Its answer is the
   | block's data, each field through its own conversion, and its HTML,
   | unless the block changed again since, whose change answers instead.
   */
  const change = async (): Promise<void> => {
    clearTimeout(waiting)
    waiting = undefined

    const id = selected
    const at = id === null ? null : find(id)

    if (!sidebar || id === null || !at) return

    const data = new FormData(sidebar)
    const ask = (changes.get(id) ?? 0) + 1
    /* The answer to typing is part of it. */
    const group = typed

    typed = undefined
    data.set('ancestors', JSON.stringify(ancestry(at.pos)))
    changes.set(id, ask)

    const answer = await send(data)
    const now = find(id)

    if (changes.get(id) !== ask || !now || now.node.attrs.data !== at.node.attrs.data) return

    if (answer.block) store(id, answer.block.data, group)
    if (answer.html !== undefined) redraw(id, answer.html)
    if (id !== selected) return

    if (typeof answer.form === 'string') show(answer.form)
    else if (!answer.messages) for (const each of body!.querySelectorAll('[id$="-error"]')) each.remove()
    tell(answer.messages)
  }

  /* A change posted once the typing pauses. */
  const later = () => {
    clearTimeout(waiting)
    waiting = setTimeout(() => void change(), 300)
  }

  /* The settings made for the block `id` names, or closed for none. A
     change still waiting is posted for the block it was made in. */
  const choose = (id: string | null) => {
    if (waiting !== undefined) void change()

    selected = id
    if (!sidebar || !body) return

    if (id === null) settings?.close()
    body.replaceChildren()
    tell(undefined)
    listed(true)
    if (id !== null) void look(id)
  }

  /* The innermost site block whose element holds `inside`, and where. */
  const holding = (inside: Element): { node: Node; pos: number } | null => {
    let found: { node: Node; pos: number } | null = null

    if (view) {
      blocks(view.state.doc, (node, pos) => {
        if ((view!.nodeDOM(pos) as Element | null)?.contains(inside)) found = { node, pos }
      })
    }

    return found
  }

  /* A field of the selected block's set to `text`, typed in place or in its
     settings: its data, and the other one of the two. */
  const set = (field: string, text: string) => {
    const at = selected === null ? null : find(selected)

    if (!at) return

    typed = `field-${typing}`
    store(selected!, { ...(at.node.attrs.data as Data), [field]: text }, typed)

    for (const input of sidebar?.querySelectorAll<HTMLInputElement | HTMLTextAreaElement>('input[data-mainstay-field], textarea[data-mainstay-field]') ?? []) {
      if (input.dataset.mainstayItem === selected && input.dataset.mainstayField === field && input.value !== text) input.value = text
    }

    later()
  }

  /* The library's picker, for the image the toolbar is on: the one picked
     goes into the block's data, in a step of its own, and the block is drawn
     again with it. image-field.ts opens it. */
  const pick = () => {
    const shown = image()
    const id = selected

    if (!shown || id === null) return

    const done = (media: string) => {
      const at = find(id)

      if (!at || !/^\d+$/.test(media)) return

      store(id, { ...(at.node.attrs.data as Data), [shown.field]: Number(media) })
      void look(id)
    }

    element.dispatchEvent(new CustomEvent('mainstay:pick', { bubbles: true, detail: { picker: element.dataset.picker, done } }))
  }

  /* The image's own form -- its alt text and focal point -- in the image
     dialog, saved there, which draws its block again. */
  const edit = async (): Promise<void> => {
    const shown = image()

    if (!shown || shown.media == null || !photo || !photoBody) return

    editing = selected
    photoBody.textContent = 'Loading the image…'
    photo.showModal()

    try {
      const response = await fetch(`${element.dataset.media}/${String(shown.media)}?dialog=1`, { headers: { Accept: 'text/html' } })

      if (!response.ok) {
        photoBody.textContent = response.status === 403 ? 'This image is not open to you.' : 'The image could not be loaded.'

        return
      }

      photoBody.innerHTML = await response.text()
      wire(photoBody)
    } catch {
      photoBody.textContent = 'The image could not be reached. Try once more.'
    }
  }

  /* What the selection is on, when it is a site block. */
  const chosen = (state: EditorState): string | null => (state.selection instanceof NodeSelection && state.selection.node.type === nodes.block ? state.selection.node.attrs.id : null)

  function dispatchTransaction(this: EditorView, tr: Transaction): void {
    const was = new Map<string, unknown>()
    const changed: string[] = []

    blocks(this.state.doc, (node) => was.set(node.attrs.id, node.attrs.data))
    state = this.state.apply(tr)

    /* A block changed is no longer what a refused save named; one an undo
       or a redo changed is drawn again. */
    blocks(state.doc, (node) => {
      if (!was.has(node.attrs.id) || was.get(node.attrs.id) === node.attrs.data) return
      if (invalid[node.attrs.id]) forgive(node.attrs.id)
      changed.push(node.attrs.id)
    })

    this.updateState(state)
    if (isHistoryTransaction(tr)) for (const id of changed) void look(id)

    if (tr.docChanged) {
      let missing = false

      textarea!.value = written = opened !== null && state.doc.eq(opened.doc) ? opened.text : JSON.stringify(state.doc.toJSON(), null, 4)
      textarea!.dispatchEvent(new Event('input', { bubbles: true }))
      posted = snapshot()

      /* A site block the page has not drawn -- added, or pasted from another
         entry -- is drawn by drawing the page again, once. */
      blocks(state.doc, (node) => {
        if (html.has(node.attrs.id)) return

        missing = true
        html.set(node.attrs.id, '')
      })
      if (missing) void draw()
    }

    if (chosen(state) !== selected) choose(chosen(state))
    else if (tr.docChanged) listed()
    sync()
  }

  /*
   | The editor on the page just written, on the element the content's script
   | sits in. It opens the document the page was drawn from the first time,
   | and after a hand edit in Details; otherwise it keeps the state it had,
   | history and all. A page that prints the content beside something else,
   | or not at all, is left read-only.
   */
  const mount = (page: Document): void => {
    const script = page.querySelector('script[data-mainstay-content]')

    if (!script) return say('This page does not print its content, so there is nothing here to edit; it shows the rest of Details as it stands.')

    const given = JSON.parse(script.textContent ?? '') as { doc: unknown; blocks: Record<string, string> }

    for (const [id, markup] of Object.entries(given.blocks)) html.set(id, markup)

    if (state === null || textarea.value !== written) {
      try {
        const doc = Node.fromJSON(contentSchema, given.doc)

        doc.check()
        state = EditorState.create({ doc, plugins })
        opened = { doc, text: textarea.value }
        written = textarea.value
        choose(null)
      } catch (error) {
        script.remove()

        return say(`The content does not open in the editor: ${(error as Error).message}`)
      }
    }

    /* Drawn now, a block the page left out draws nothing. */
    blocks(state.doc, (node) => {
      if (!html.has(node.attrs.id)) html.set(node.attrs.id, '')
    })

    const parent = script.parentElement!
    const beside = [...parent.childNodes].some((child) => child !== script && (child.nodeType === 1 || (child.nodeType === 3 && (child.textContent ?? '').trim() !== '')))

    if (beside) {
      script.replaceWith(render(state.doc, html, page))

      return say('This page prints its content beside other things in one element, so it is shown read-only here; its raw content is in Details.')
    }

    say(null)
    script.remove()
    view = new EditorView({ mount: parent as HTMLElement }, { state, nodeViews: { block: island }, dispatchTransaction })
  }

  /* Every listener the admin puts on the frame, again after each write,
     which drops them. */
  const listen = (page: Document): void => {
    const cancel = (event: Event) => {
      if ((event.target as Element | null)?.closest?.('a[href], area[href]')) event.preventDefault()
    }

    page.addEventListener('click', cancel, true)
    page.addEventListener('auxclick', cancel, true)
    page.addEventListener('scroll', place)
    page.addEventListener('mousemove', wake)

    /* Before ProseMirror selects what was pressed on: the image the toolbar
       is to be on. */
    page.addEventListener(
      'pointerdown',
      (event) => {
        const image = (event.target as Element | null)?.closest?.<HTMLElement>('[data-mainstay-image]')

        close()
        pictured = image ? { item: image.dataset.mainstayItem ?? '', field: image.dataset.mainstayImage ?? '' } : null
        wake()
      },
      true,
    )

    /* @field text: selecting its block as it is focused, one line where its
       field holds one, and every change into the block's data. */
    page.addEventListener('focusin', (event) => {
      const span = (event.target as Element | null)?.closest?.('[data-mainstay-field]')
      const at = span ? holding(span) : null

      typing++
      if (!view || !at || (chosen(view.state) === at.node.attrs.id && selected === at.node.attrs.id)) return

      /* On it already, as a page starting with a site block opens, but never
         drawn so: off it first, for ProseMirror to draw it selected. */
      if (chosen(view.state) === at.node.attrs.id) view.dispatch(view.state.tr.setSelection(new AllSelection(view.state.doc)))
      view.dispatch(view.state.tr.setSelection(NodeSelection.create(view.state.doc, at.pos)))
    })
    page.addEventListener('focusout', () => setTimeout(flush))
    page.addEventListener('keydown', (event) => {
      const span = (event.target as Element | null)?.closest?.('[data-mainstay-field]')

      if (span && event.key === 'Enter' && !span.hasAttribute('data-mainstay-lines')) event.preventDefault()

      /* Typing hides the toolbar until the page is pointed at again. */
      if (event.key.length === 1 && !event.ctrlKey && !event.metaKey && !event.altKey && !quiet) {
        quiet = true
        place()
      }
    })
    page.addEventListener('input', (event) => {
      const span = (event.target as Element | null)?.closest?.<HTMLElement>('[data-mainstay-field]')

      if (!span || selected === null || holding(span)?.node.attrs.id !== selected) return
      if (said(span) === '') span.replaceChildren()

      set(span.dataset.mainstayField ?? '', said(span))
    })

    /* During a grip drag, the drop is the admin's alone: ProseMirror's own
       handling never hears it. */
    page.addEventListener(
      'dragover',
      (event) => {
        if (dragging === null || !view) return

        const landed = landing(view, dragging, event.clientX, event.clientY)

        event.preventDefault()
        event.stopPropagation()
        line.hidden = landed === null
        if (landed) Object.assign(line.style, { top: `${landed.line.top}px`, left: `${landed.line.left}px`, width: `${landed.line.width}px`, height: `${landed.line.height}px` })
      },
      true,
    )
    page.addEventListener(
      'drop',
      (event) => {
        if (dragging === null || !view) return

        const landed = landing(view, dragging, event.clientX, event.clientY)
        const tr = landed ? moved(view.state, dragging, landed.index) : null

        event.preventDefault()
        event.stopPropagation()
        ended()
        if (tr) view.dispatch(tr)
        view.focus()
      },
      true,
    )
  }

  /* The page written into the frame, its scroll kept. */
  const write = (text: string): void => {
    const page = frame.contentDocument
    const inner = frame.contentWindow

    if (!page || !inner) return

    const [x, y] = [inner.scrollX, inner.scrollY]
    /* Again once its stylesheets and images are in, which move it. */
    const keep = () => {
      inner.scrollTo(x, y)
      place()
    }

    view?.destroy()
    view = null
    frame.addEventListener('load', keep, { once: true })
    page.open()
    page.write(text)
    page.close()

    /* The page's <main> alone, the one the content is in: the header, menu
       and footer around it are the site's layout, not this entry. Its
       styles stay. A page with none is drawn whole. */
    for (let node = page.querySelector('script[data-mainstay-content]')?.closest('main') ?? page.querySelector('main'); node && node !== page.body; node = node.parentElement) {
      for (const other of [...node.parentElement!.children]) if (other !== node && !other.matches('link, style')) other.remove()
    }

    page.head.append(Object.assign(page.createElement('style'), { textContent: css }))
    listen(page)
    stage.hidden = false
    keep()
    mount(page)
    sync()
  }

  /* Anything but a page: said in the tab, the page taken away, and the
     next opening of the tab trying again. */
  const fail = (text: string): void => {
    view?.destroy()
    view = null
    posted = null
    choose(null)
    stage.hidden = true
    say(text)
    sync()
  }

  /* The page posted for, as the form now holds it. Only the last one asked
     for is written. */
  const draw = async (): Promise<void> => {
    const ask = ++asked

    posted = snapshot()
    if (stage.hidden) say('Drawing the page…')

    try {
      const response = await fetch(url, {
        method: 'POST',
        body: new FormData(form),
        headers: { Accept: 'text/html, application/json', 'X-Requested-With': 'XMLHttpRequest' },
        redirect: 'manual',
      })

      if (ask !== asked) return

      if (response.type === 'opaqueredirect' || response.status === 401 || response.status === 419) {
        return fail('Your session has ended. Sign in again in another tab, then open this tab again: what you changed is kept in this browser.')
      }

      if (!response.ok) {
        const answer = (await response.json().catch(() => null)) as { messages?: string[] } | null

        return fail(answer?.messages?.join(' ') ?? `The page could not be drawn: the server answered ${response.status}.`)
      }

      const text = await response.text()

      if (ask === asked) write(text)
    } catch {
      if (ask === asked) fail('The page could not be reached. Open the tab again to try once more.')
    }
  }

  /* The toolbar's buttons and the tabs' bar's alike. */
  const run = (event: Event) => {
    const button = (event.target as Element | null)?.closest<HTMLButtonElement>('button[data-command]')
    const name = button?.dataset.command ?? ''

    if (!view || !button) return

    if (name === 'add') {
      const box = add.getBoundingClientRect()

      return menu.hidden ? open(box.bottom + 4, box.left, false) : close()
    }

    if (name === 'settings') return settings?.showModal()
    if (name === 'replace') return pick()
    if (name === 'image') return void edit()

    commands[name]?.(view.state, view.dispatch, view)
    if (name !== 'link') view.focus()
  }

  header.addEventListener('click', run)
  toolbar.addEventListener('click', run)

  turner.addEventListener('change', () => {
    if (!view) return

    turn(turner.value)(view.state, view.dispatch, view)
    view.focus()
  })

  /* A click on an item keeps the focus where it is, so the menu is still
     there for the click: Safari does not focus a button it clicks. */
  menu.addEventListener('pointerdown', (event) => event.preventDefault())

  menu.addEventListener('click', (event) => {
    const item = (event.target as Element | null)?.closest<HTMLButtonElement>('[data-insert]')
    const kind = item?.dataset.insert ?? ''

    if (!item || !view) return

    close()
    insert(kind, kind.startsWith('block:') ? known[kind.slice(6)]?.inner[0] : undefined)(view.state, view.dispatch, view)
    view.focus()
  })

  /* Arrows between the items; Escape back to where it opened, typing the
     `/` that opened it. */
  menu.addEventListener('keydown', (event) => {
    const all = items()
    const from = all.indexOf(document.activeElement as HTMLButtonElement)
    const to = { ArrowDown: from + 1, ArrowUp: from - 1, Home: 0, End: all.length - 1 }[event.key]

    if (event.key === 'Escape') {
      event.preventDefault()
      close()

      if (!slashed) return add.focus()

      view?.dispatch(view.state.tr.insertText('/'))
      view?.focus()
    } else if (to !== undefined) {
      event.preventDefault()
      all[(to + all.length) % all.length]?.focus()
    }
  })

  menu.addEventListener('focusout', (event) => {
    const to = event.relatedTarget as globalThis.Node | null

    if (!menu.contains(to) && to !== add) close()
  })

  grip.addEventListener('dragstart', (event) => {
    if (!view) return event.preventDefault()

    dragging = current(view.state)
    event.dataTransfer?.setData('text/plain', '')
    if (event.dataTransfer) event.dataTransfer.effectAllowed = 'move'
  })

  grip.addEventListener('dragend', ended)
  window.addEventListener('resize', place)

  /* Opening the tab draws the page when Details changed since it was last
     drawn; the editor's own changes are not Details'. Leaving it closes the
     block's settings. */
  document.addEventListener('tabchange', (event) => {
    const here = (event as CustomEvent<string>).detail === panel?.id

    header.hidden = !here || view === null
    if (!here) settings?.close()
    if (here && snapshot() !== posted) void draw()
  })

  /* The settings: text into the page at once, and every change posted once
     the typing pauses. A block inside is selected on the page from its
     list, which closes them, and one is added from its button. They never
     submit. */
  sidebar?.addEventListener('submit', (event) => event.preventDefault())
  sidebar?.addEventListener('focusin', () => typing++)
  sidebar?.addEventListener('input', (event) => {
    const input = event.target as HTMLInputElement

    if (input.dataset?.mainstayItem === selected && input.dataset.mainstayField !== undefined) set(input.dataset.mainstayField, input.value)
    else later()
  })
  sidebar?.addEventListener('click', (event) => {
    const target = event.target as Element | null
    const inner = find(target?.closest<HTMLElement>('[data-inner-select]')?.dataset.innerSelect ?? '')
    const kind = target?.closest<HTMLElement>('[data-inner-add]')?.dataset.innerAdd
    const at = selected === null ? null : find(selected)

    if (!view || !at) return

    if (inner) {
      settings?.close()
      quiet = false
      view.dispatch(view.state.tr.setSelection(NodeSelection.create(view.state.doc, inner.pos)).scrollIntoView())
      view.focus()
    } else if (kind !== undefined) {
      append(at.pos, kind, known[kind]?.inner[0])(view.state, view.dispatch, view)
    }
  })

  /* The image dialog's form, posted for JSON: once it is saved, the block
     is drawn again with the image as it now is. */
  photoBody?.addEventListener('submit', async (event) => {
    const sent = (event.target as Element | null)?.closest<HTMLFormElement>('form[data-image-form]')
    const id = editing

    if (!sent) return

    event.preventDefault()

    const response = await fetch(sent.action, { method: 'POST', body: new FormData(sent), headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } }).catch(() => null)

    if (response?.ok) {
      photo?.close()
      if (id !== null) void look(id)

      return
    }

    const answer = (await response?.json().catch(() => null)) as { message?: string; errors?: Record<string, string[]> } | null
    const shown = sent.querySelector<HTMLElement>('[data-image-messages]')

    if (!shown) return

    shown.replaceChildren(...(answer?.errors ? Object.values(answer.errors).flat() : [answer?.message ?? 'The image could not be saved.']).map((text) => Object.assign(document.createElement('p'), { textContent: text })))
    shown.hidden = false
  })

  wired.set(element, () => view)
  if (!panel?.hidden) void draw()

  return wired.get(element)
}

/* Every Content tab under `root` not yet wired, `wire` given to each. */
export function canvases(root: ParentNode = document, wire?: (root: HTMLElement) => void): void {
  for (const element of root.querySelectorAll<HTMLElement>('[data-canvas]')) canvas(element, wire)
}
