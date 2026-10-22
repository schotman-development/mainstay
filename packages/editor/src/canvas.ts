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
 | the frame, where canvas.blade.php draws it: the toolbar, the menu of blocks
 | to add, the grip a block is dragged by, and the sidebar a selected site
 | block's fields are in, which the server draws. A block's @field text is
 | typed in place. No script runs in the frame; this one, around it, cancels
 | its links.
 */

const { nodes } = contentSchema
const { tools, keys } = kit(contentSchema)

type Current = { node: Node; pos: number; index: number }

const at = (doc: Node, index: number) => doc.resolve(0).posAtIndex(index)

/* The top-level block the selection starts in: the one the block commands
   act on, outlined in the page and lined up with the grip. */
function current(state: EditorState): Current {
  const index = Math.min(state.selection.$from.index(0), state.doc.childCount - 1)

  return { index, pos: at(state.doc, index), node: state.doc.child(index) }
}

/* Where the selection goes in the block at `pos`: onto it when it is a site
   block or a rule, into its first text otherwise. */
const into = (doc: Node, pos: number): Selection => (doc.nodeAt(pos)?.isAtom ? NodeSelection.create(doc, pos) : Selection.near(doc.resolve(pos + 1)))

/* The block at `index` moved to before the one at `to`, in one step undo
   takes back, the selection going with it; null where it would stay. */
function moved(state: EditorState, index: number, to: number): Transaction | null {
  if (to === index || to === index + 1) return null

  const { doc, selection } = state
  const node = doc.child(index)
  const from = at(doc, index)
  const tr = state.tr.delete(from, from + node.nodeSize)
  const pos = tr.mapping.map(at(doc, to))
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
    const { index } = current(state)
    const to = by < 0 ? index - 1 : index + 2
    const tr = to < 0 || to > state.doc.childCount ? null : moved(state, index, to)

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
   holds one block at least. */
const remove: Command = (state, dispatch) => {
  const { node, pos, index } = current(state)

  if (state.doc.childCount === 1 && node.type === nodes.paragraph && node.content.size === 0) return false

  if (dispatch) {
    const tr = state.doc.childCount === 1 ? state.tr.replaceWith(pos, pos + node.nodeSize, nodes.paragraph.create()) : state.tr.delete(pos, pos + node.nodeSize)

    dispatch(tr.setSelection(into(tr.doc, at(tr.doc, Math.min(index, tr.doc.childCount - 1)))).scrollIntoView())
  }

  return true
}

const kinds = ['paragraph', 'heading', 'blockquote', 'code_block', 'bullet_list', 'ordered_list', 'horizontal_rule']
const wrappers = [nodes.blockquote, nodes.bullet_list, nodes.ordered_list]

/* What the current block is, as Turn into names it: '' for a site block or a
   rule, which turn into nothing. */
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
   site block of that type with no id yet. */
function made(kind: string): Node | null {
  if (kind.startsWith('block:')) return nodes.block.create({ type: kind.slice(6) })

  return kinds.includes(kind) ? (nodes[kind]?.createAndFill() ?? null) : null
}

/* A block added after the current one, or in its place when that is an
   empty paragraph -- where `/` opens the menu. */
const insert =
  (kind: string): Command =>
  (state, dispatch) => {
    const block = made(kind)

    if (!block) return false

    if (dispatch) {
      const { node, pos } = current(state)
      const empty = node.type === nodes.paragraph && node.content.size === 0
      const place = empty ? pos : pos + node.nodeSize
      const tr = state.tr.replaceWith(place, empty ? pos + node.nodeSize : place, block)

      dispatch(tr.setSelection(into(tr.doc, place)).scrollIntoView())
    }

    return true
  }

/* Enter on a selected site block or rule starts a paragraph after it. */
const after: Command = (state, dispatch) => {
  const { selection } = state

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
 | Every site block its own id, as Content.php's distinct rule asks: one added
 | without, and the second of two -- a duplicate, a paste -- given one, which
 | draws the HTML the first one drew, its @field text marked as its own. The
 | block that was there keeps its own, so a paste before it does not take it.
 */
function ids(html: Map<string, string>): Plugin {
  return new Plugin({
    appendTransaction(transactions, old, state) {
      if (!transactions.some((tr) => tr.docChanged)) return null

      const there = new Set<Node>()
      const holders = new Map<string, number>()
      const { tr } = state

      old.doc.forEach((node) => there.add(node))
      state.doc.forEach((node, offset) => {
        if (node.type === nodes.block && node.attrs.id && there.has(node) && !holders.has(node.attrs.id)) holders.set(node.attrs.id, offset)
      })
      state.doc.forEach((node, offset) => {
        const id: string | null = node.attrs.id || null

        if (node.type !== nodes.block || (id !== null && (holders.get(id) ?? holders.set(id, offset).get(id)) === offset)) return

        const given = random()

        if (id !== null && html.has(id)) html.set(given, html.get(id)!.replaceAll(`data-mainstay-item="${id}"`, `data-mainstay-item="${given}"`))
        tr.setNodeAttribute(offset, 'id', given)
      })

      return tr.docChanged ? tr : null
    },
  })
}

type Data = Record<string, unknown>

/* The data of the item `id` names in a site block's -- the block itself, or
   an item of a list inside it, at any depth -- or undefined. */
function itemOf(data: Data, block: string, id: string): Data | undefined {
  if (id === block) return data

  for (const list of Object.values(data)) {
    for (const each of Array.isArray(list) ? (list as unknown[]) : []) {
      const nested = each as { id?: unknown; data?: unknown } | null

      if (nested && typeof nested.data === 'object' && nested.data !== null) {
        const found = itemOf(nested.data as Data, String(nested.id), id)

        if (found) return found
      }
    }
  }

  return undefined
}

/* A block's data with the field `field` of the item `id` holding `value`. */
function edited(data: Data, block: string, id: string, field: string, value: string): Data {
  const copy = structuredClone(data)
  const item = itemOf(copy, block, id)

  if (item) item[field] = value

  return copy
}

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
   outline, a refused block's mark, the placeholder of a block that draws
   nothing, and @field text, showing its field's label while it is empty. */
const css = `${prosemirror}
.ProseMirror { outline: none }
.mainstay-current { outline: 1px dashed rgb(37 99 235 / 0.6); outline-offset: 4px }
.mainstay-invalid { outline: 2px solid rgb(220 38 38); outline-offset: 4px }
.ProseMirror-selectednode { outline: 2px solid rgb(37 99 235); outline-offset: 4px }
[data-mainstay-placeholder] { padding: 1rem; border: 1px dashed #94a3b8; border-radius: 4px; color: #475569; font: 14px/1.4 system-ui, sans-serif; text-align: center }
[data-mainstay-field] { outline: none; cursor: text }
[data-mainstay-field]:empty::before { content: attr(data-mainstay-label); opacity: 0.5 }
[data-mainstay-lines] { white-space: pre-wrap }`

const commands: Record<string, Command> = {
  undo,
  redo,
  strong: tools.strong!.run,
  em: tools.em!.run,
  code: tools.code!.run,
  link: tools.link!.run,
  add: () => true,
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
   each site block as its view drew it. */
export function render(doc: Node, html: Map<string, string>, page: Document): DocumentFragment {
  const serializer = DOMSerializer.fromSchema(contentSchema)
  const fragment = page.createDocumentFragment()

  doc.forEach((node) => fragment.append(node.type === nodes.block ? parse(html.get(node.attrs.id) ?? '', page) : serializer.serializeNode(node, { document: page })))

  return fragment
}

/* Where a block dragged to `y` in the frame lands: before the first block
   whose middle is below it, or last; and the line drawn there. */
function landing(view: EditorView, y: number): { index: number; y: number } {
  const { doc } = view.state
  let pos = 0
  let bottom = 0

  for (let index = 0; index < doc.childCount; index++) {
    const rect = (view.nodeDOM(pos) as Element | null)?.getBoundingClientRect()

    pos += doc.child(index).nodeSize

    if (!rect) continue
    if (y < rect.top + rect.height / 2) return { index, y: rect.top }

    bottom = rect.bottom
  }

  return { index: doc.childCount, y: bottom }
}

const wired = new WeakMap<HTMLElement, () => EditorView | null>()

/* What the block route answers: as much of it as it could draw. */
type Answer = { block?: { id: string; type: string; data: Data }; html?: string; form?: string | null; messages?: string[] }

/*
 | Wires one `[data-canvas]`, once, handing back what gives the editor on its
 | page, while there is one: a second call, the first one's. `wire` gives the
 | admin's behaviour to what the server draws into a block's sidebar.
 */
export function canvas(element: HTMLElement, wire: (root: HTMLElement) => void = () => {}): (() => EditorView | null) | undefined {
  const form = element.closest('form')
  const textarea = form?.querySelector<HTMLTextAreaElement>('textarea[name="content"]')
  const toolbar = element.querySelector<HTMLElement>('[data-canvas-toolbar]')
  const message = element.querySelector<HTMLElement>('[data-canvas-message]')
  const stage = element.querySelector<HTMLElement>('[data-canvas-stage]')
  const frame = element.querySelector<HTMLIFrameElement>('[data-canvas-frame]')
  const grip = element.querySelector<HTMLElement>('[data-canvas-grip]')
  const line = element.querySelector<HTMLElement>('[data-canvas-line]')
  const menu = element.querySelector<HTMLElement>('[data-canvas-menu]')
  const add = toolbar?.querySelector<HTMLButtonElement>('button[data-command="add"]')
  const turner = toolbar?.querySelector<HTMLSelectElement>('select[data-command="turn"]')
  const url = element.dataset.canvasUrl
  const blocked = element.dataset.blockUrl
  const sidebar = blocked ? (document.getElementById(element.dataset.sidebar ?? '') as HTMLFormElement | null) : null
  const body = sidebar?.querySelector<HTMLElement>('[data-sidebar-body]')
  const told = sidebar?.querySelector<HTMLElement>('[data-sidebar-messages]')

  if (wired.has(element)) return wired.get(element)
  if (!form || !textarea || !toolbar || !message || !stage || !frame || !grip || !line || !menu || !add || !turner || !url) return undefined

  const labels = JSON.parse(element.dataset.blocks ?? '{}') as Record<string, string>
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
  let dragging: number | null = null
  let slashed = false
  /* The block the sidebar is for, the last time it was asked for, and
     the change waiting to be posted. */
  let selected: string | null = null
  let looked = 0
  let waiting: ReturnType<typeof setTimeout> | undefined
  /* Each stretch of typing in one @field, which undo takes back whole, and
     the one a change still to be answered was typed in. */
  let typing = 0
  let typed: string | undefined

  const say = (text: string | null) => {
    message.textContent = text ?? ''
    message.hidden = text === null
  }

  const snapshot = () => JSON.stringify([...new FormData(form)].map(([name, value]) => [name, typeof value === 'string' ? value : value.name]))

  /* The grip beside the current block, while that is in view; left where it
     is during a drag, which hiding the grip would end. */
  const place = () => {
    if (dragging !== null) return

    const dom = view ? (view.nodeDOM(current(view.state).pos) as Element | null) : null
    const top = dom?.getBoundingClientRect().top ?? -1

    grip.hidden = top < 0 || top > frame.clientHeight
    grip.style.top = `${top}px`
  }

  const sync = () => {
    toolbar.hidden = view === null

    if (!view) {
      grip.hidden = true

      return
    }

    for (const button of toolbar.querySelectorAll<HTMLButtonElement>('button[data-command]')) {
      const name = button.dataset.command ?? ''
      const active = tools[name]?.active

      button.disabled = !commands[name]?.(view.state)
      if (active) button.setAttribute('aria-pressed', String(active(view.state)))
    }

    turner.value = kind(view.state)
    turner.disabled = turner.value === ''
    place()
  }

  const ended = () => {
    dragging = null
    line.hidden = true
    place()
  }

  const items = () => [...menu.querySelectorAll<HTMLButtonElement>('[data-insert]')]

  /* The Add block menu at a point on the admin's page. */
  const open = (top: number, left: number, slash: boolean) => {
    const box = element.getBoundingClientRect()

    slashed = slash
    menu.style.top = `${top - box.top}px`
    menu.style.left = `${left - box.left}px`
    menu.hidden = false
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

        state.doc.forEach((child, offset) => {
          const attrs: DecorationAttrs = {}

          if (child.type !== nodes.block) return
          if (invalid[child.attrs.id]) attrs.class = 'mainstay-invalid'
          if (versions.has(child.attrs.id)) attrs['data-mainstay-drawn'] = String(versions.get(child.attrs.id))
          if (Object.keys(attrs).length > 0) found.push(Decoration.node(offset, offset + child.nodeSize, attrs))
        })

        return DecorationSet.create(state.doc, found)
      },
    },
  })

  /*
   | A site block as its view drew it, an island the editor does not edit: the
   | element itself where the HTML is one, so the page's own CSS spaces it, and
   | wrapped where it is more. Drawing nothing -- or nothing yet, added since
   | the page was drawn -- it is a placeholder naming it. Its @field text is
   | typed in place, the events in it the admin's alone, and says what the
   | block's data does, whoever changed it.
   */
  const island: NodeViewConstructor = (node, view) => {
    const page = view.dom.ownerDocument
    const id: string = node.attrs.id
    const version = versions.get(id) ?? 0
    const drawn = parse(html.get(id) ?? '', page)
    const only = drawn.firstElementChild
    const alone = only !== null && [...drawn.childNodes].every((child) => child === only || (child.nodeType !== 1 && (child.textContent ?? '').trim() === ''))
    let dom = only as HTMLElement | null

    if ((html.get(id) ?? '').trim() === '') {
      dom = Object.assign(page.createElement('div'), { textContent: labels[node.attrs.type] ?? node.attrs.type })
      dom.setAttribute('data-mainstay-placeholder', '')
    } else if (!alone) {
      dom = page.createElement('div')
      dom.append(drawn)
    }

    const spans = [...dom!.querySelectorAll<HTMLElement>('[data-mainstay-field]')]
    const fill = (data: Data) => {
      for (const span of spans) {
        const item = itemOf(data, id, span.dataset.mainstayItem ?? '')
        const text = String(item?.[span.dataset.mainstayField ?? ''] ?? '')

        if (item && said(span) !== text) span.textContent = text
      }
    }

    for (const span of spans) span.setAttribute('contenteditable', 'plaintext-only')
    fill(node.attrs.data)

    return {
      dom: dom!,
      update: (next) => {
        if (next.type !== node.type || next.attrs.id !== id || (versions.get(id) ?? 0) !== version) return false

        fill(next.attrs.data)

        return true
      },
      stopEvent: (event) => (event.target as Element | null)?.closest?.('[data-mainstay-field]') != null,
      ignoreMutation: () => true,
      /* Not made draggable, as ProseMirror makes a selected node: a drag in
         its text selects the text. The grip moves it. */
      selectNode: () => dom!.classList.add('ProseMirror-selectednode'),
      deselectNode: () => dom!.classList.remove('ProseMirror-selectednode'),
    }
  }

  const plugins = [history(), keymap({ Enter: chainCommands(after, leave), Backspace: emptied, '/': slash }), keymap(keys), keymap(baseKeymap), ids(html), marks]

  /* The block `id` names in the editor's document, and where. */
  const find = (id: string): { node: Node; pos: number } | null => {
    let found: { node: Node; pos: number } | null = null

    view?.state.doc.forEach((node, pos) => {
      if (node.type === nodes.block && node.attrs.id === id) found ??= { node, pos }
    })

    return found
  }

  /* A block's data as given, in one step of its own: the sidebar's, or
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

  /* The sidebar's fields, as the server drew them for the block. */
  const show = (markup: string) => {
    body!.innerHTML = markup
    wire(body!)
  }

  /*
   | A block as the document holds it, posted for its sidebar once it is
   | selected -- with a refused save's messages, while it is what was
   | refused -- and for its HTML once an undo or a redo changed it.
   */
  const look = async (id: string): Promise<void> => {
    const at = find(id)
    const ask = id === selected ? ++looked : looked
    const data = new FormData()

    if (!at) return

    data.set('block', JSON.stringify(at.node.attrs))
    if (invalid[id]) data.set('errors', JSON.stringify(invalid[id].messages))

    const answer = await send(data)
    const now = find(id)

    if (answer.html !== undefined && now?.node.attrs.data === at.node.attrs.data) redraw(id, answer.html)
    if (id !== selected || ask !== looked) return

    if (typeof answer.form === 'string') show(answer.form)
    tell(answer.form ? undefined : answer.messages)
  }

  /*
   | The sidebar posted after one of its fields changed, `op` naming an item
   | of a list in it to add, move or remove. Its answer is the block's data,
   | each field through its own conversion, and its HTML, unless the block
   | changed again since, whose change answers instead.
   */
  const change = async (op?: HTMLElement): Promise<void> => {
    clearTimeout(waiting)
    waiting = undefined

    const id = selected
    const at = id === null ? null : find(id)

    if (!sidebar || id === null || !at) return

    const data = new FormData(sidebar)
    const ask = (changes.get(id) ?? 0) + 1
    /* The answer to typing is part of it; a list's control is a step of
       its own. */
    const group = op ? undefined : typed

    typed = undefined

    for (const [key, value] of Object.entries({ op: op?.dataset.blockOp, list: op?.dataset.list, index: op?.dataset.index, kind: op?.dataset.kind })) {
      if (value !== undefined) data.set(key, value)
    }

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

  /* The sidebar opened for the block `id` names, or closed for none. A
     change still waiting is posted for the block it was made in. */
  const choose = (id: string | null) => {
    if (waiting !== undefined) void change()

    selected = id
    if (!sidebar || !body) return

    sidebar.hidden = id === null || panel?.hidden === true
    body.replaceChildren()
    tell(undefined)
    if (id !== null) void look(id)
  }

  /* The site block whose element holds `inside`, and where. */
  const holding = (inside: Element): { node: Node; pos: number } | null => {
    let found: { node: Node; pos: number } | null = null

    view?.state.doc.forEach((node, pos) => {
      if (node.type === nodes.block && (view!.nodeDOM(pos) as Element | null)?.contains(inside)) found ??= { node, pos }
    })

    return found
  }

  /* A field of the selected block's set to `text`, typed in place or in its
     sidebar: its data, and the other one of the two. */
  const set = (item: string, field: string, text: string) => {
    const at = selected === null ? null : find(selected)

    if (!at) return

    typed = `field-${typing}`
    store(selected!, edited(at.node.attrs.data, selected!, item, field, text), typed)

    for (const input of sidebar?.querySelectorAll<HTMLInputElement | HTMLTextAreaElement>('input[data-mainstay-item], textarea[data-mainstay-item]') ?? []) {
      if (input.dataset.mainstayItem === item && input.dataset.mainstayField === field && input.value !== text) input.value = text
    }

    later()
  }

  /* What the selection is on, when it is a site block. */
  const chosen = (state: EditorState): string | null => (state.selection instanceof NodeSelection && state.selection.node.type === nodes.block ? state.selection.node.attrs.id : null)

  function dispatchTransaction(this: EditorView, tr: Transaction): void {
    const was = new Map<string, unknown>()
    const changed: string[] = []

    this.state.doc.forEach((node) => node.type === nodes.block && was.set(node.attrs.id, node.attrs.data))
    state = this.state.apply(tr)

    /* A block changed is no longer what a refused save named; one an undo
       or a redo changed is drawn again. */
    state.doc.forEach((node) => {
      if (node.type !== nodes.block || !was.has(node.attrs.id) || was.get(node.attrs.id) === node.attrs.data) return
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
         entry -- is drawn by drawing the page again. */
      state.doc.forEach((node) => (missing ||= node.type === nodes.block && !html.has(node.attrs.id)))
      if (missing) void draw()
    }

    if (chosen(state) !== selected) choose(chosen(state))
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
    state.doc.forEach((node) => {
      if (node.type === nodes.block && !html.has(node.attrs.id)) html.set(node.attrs.id, '')
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
    page.addEventListener('pointerdown', close)
    page.addEventListener('scroll', place)

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
    })
    page.addEventListener('input', (event) => {
      const span = (event.target as Element | null)?.closest?.<HTMLElement>('[data-mainstay-field]')

      if (!span || selected === null || holding(span)?.node.attrs.id !== selected) return
      if (said(span) === '') span.replaceChildren()

      set(span.dataset.mainstayItem ?? '', span.dataset.mainstayField ?? '', said(span))
    })

    /* During a grip drag, the drop is the admin's alone: ProseMirror's own
       handling never hears it. */
    page.addEventListener(
      'dragover',
      (event) => {
        if (dragging === null || !view) return

        event.preventDefault()
        event.stopPropagation()
        line.style.top = `${landing(view, event.clientY).y}px`
        line.hidden = false
      },
      true,
    )
    page.addEventListener(
      'drop',
      (event) => {
        if (dragging === null || !view) return

        const tr = moved(view.state, dragging, landing(view, event.clientY).index)

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

  toolbar.addEventListener('click', (event) => {
    const button = (event.target as Element | null)?.closest<HTMLButtonElement>('button[data-command]')
    const name = button?.dataset.command ?? ''

    if (!view || !button) return

    if (name === 'add') {
      const box = add.getBoundingClientRect()

      return menu.hidden ? open(box.bottom + 4, box.left, false) : close()
    }

    commands[name]?.(view.state, view.dispatch, view)
    if (name !== 'link') view.focus()
  })

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

    if (!item || !view) return

    close()
    insert(item.dataset.insert ?? '')(view.state, view.dispatch, view)
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

    dragging = current(view.state).index
    event.dataTransfer?.setData('text/plain', '')
    if (event.dataTransfer) event.dataTransfer.effectAllowed = 'move'
  })

  grip.addEventListener('dragend', ended)
  window.addEventListener('resize', place)

  /* Opening the tab draws the page when Details changed since it was last
     drawn; the editor's own changes are not Details'. */
  document.addEventListener('tabchange', (event) => {
    const here = (event as CustomEvent<string>).detail === panel?.id

    if (sidebar) sidebar.hidden = !here || selected === null
    if (here && snapshot() !== posted) void draw()
  })

  /* The sidebar: text into the page at once, every change posted once the
     typing pauses, and a list's controls at once. It never submits. */
  sidebar?.addEventListener('submit', (event) => event.preventDefault())
  sidebar?.addEventListener('focusin', () => typing++)
  sidebar?.addEventListener('input', (event) => {
    const input = event.target as HTMLInputElement

    if (input.dataset?.mainstayItem !== undefined && input.dataset.mainstayField !== undefined) set(input.dataset.mainstayItem, input.dataset.mainstayField, input.value)
    else later()
  })
  sidebar?.addEventListener('click', (event) => {
    const op = (event.target as Element | null)?.closest<HTMLElement>('[data-block-op]')

    if (op) void change(op)
  })

  wired.set(element, () => view)
  if (!panel?.hidden) void draw()

  return wired.get(element)
}

/* Every Content tab under `root` not yet wired, `wire` given to each. */
export function canvases(root: ParentNode = document, wire?: (root: HTMLElement) => void): void {
  for (const element of root.querySelectorAll<HTMLElement>('[data-canvas]')) canvas(element, wire)
}
