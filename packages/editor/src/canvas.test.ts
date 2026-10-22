import { undo, undoDepth } from 'prosemirror-history'
import { NodeSelection, TextSelection } from 'prosemirror-state'
import type { EditorView } from 'prosemirror-view'
import { afterEach, beforeEach, expect, test, vi } from 'vitest'
import { canvas } from './canvas'

/* The edit form as form.blade.php draws it for a type with a page: Details'
   fields, the raw content among them, and the Content tab's markup from
   canvas.blade.php. */
beforeEach(() => {
  document.body.innerHTML = `
    <form method="post" action="/admin/page/5">
      <input type="hidden" name="_token" value="token">
      <div id="details" role="tabpanel">
        <input name="title" value="Home">
        <textarea name="content"></textarea>
      </div>
      <div id="content" role="tabpanel" hidden>
        <div data-canvas data-canvas-url="/admin/page/5/canvas" data-blocks='{"callout":"Callout","latest":"Latest articles"}'>
          <div data-canvas-toolbar hidden>
            <button type="button" data-command="undo">Undo</button>
            <button type="button" data-command="redo">Redo</button>
            <button type="button" data-command="strong">B</button>
            <button type="button" data-command="link">Link</button>
            <button type="button" data-command="add" aria-expanded="false">Add block</button>
            <select data-command="turn">
              <option value="paragraph">Paragraph</option>
              <option value="heading-2">Heading 2</option>
              <option value="blockquote">Quote</option>
              <option value="code_block">Code block</option>
              <option value="bullet_list">Bulleted list</option>
              <option value="ordered_list">Numbered list</option>
              <option value="" hidden></option>
            </select>
            <button type="button" data-command="up">Move up</button>
            <button type="button" data-command="down">Move down</button>
            <button type="button" data-command="duplicate">Duplicate</button>
            <button type="button" data-command="delete">Delete</button>
          </div>
          <p data-canvas-message>The page is edited with JavaScript, which is off.</p>
          <div data-canvas-stage hidden>
            <button type="button" data-canvas-grip draggable="true" hidden>Drag</button>
            <iframe data-canvas-frame sandbox="allow-same-origin"></iframe>
            <div data-canvas-line hidden></div>
          </div>
          <div data-canvas-menu hidden>
            <button type="button" data-insert="paragraph">Paragraph</button>
            <button type="button" data-insert="heading">Heading</button>
            <button type="button" data-insert="block:latest">Latest articles</button>
          </div>
        </div>
      </div>
    </form>`
})

afterEach(() => vi.unstubAllGlobals())

type Doc = { type: string; attrs?: Record<string, unknown>; content?: Doc[]; text?: string }

const text = (type: string, words: string, attrs?: Record<string, unknown>): Doc => ({ type, ...(attrs ? { attrs } : {}), content: [{ type: 'text', text: words }] })
const doc = (...content: Doc[]): Doc => ({ type: 'doc', content })
const callout = (id: string): Doc => ({ type: 'block', attrs: { id, type: 'callout', data: { heading: 'Mind the gap' } } })

/* The page the canvas route answers: the site's layout around the content,
   which prints its script -- encoded as Document.php encodes it -- alone in
   its element, or beside something else. */
const page = (content: Doc, blocks: Record<string, string> = {}, around = (script: string) => `<div class="prose">${script}</div>`) => {
  const json = JSON.stringify({ doc: content, blocks }).replace(/</g, '\\u003C').replace(/>/g, '\\u003E').replace(/&/g, '\\u0026')

  return `<!doctype html><html><head><title>Home</title></head><body><header><nav><a href="/about">About</a></nav></header><div class="wrap"><aside>Beside</aside><main><a href="/tags/news">News</a>${around(`<script type="application/json" data-mainstay-content>${json}</script>`)}</main></div><footer>Footer</footer></body></html>`
}

const answers: Response[] = []
const posted: FormData[] = []
/* The block route's, apart: a block selected posts there between pages. */
const replies: Response[] = []
const sent: FormData[] = []

/* fetch answering each post with the next answer given, and keeping what
   was posted. */
function serve(...given: Array<string | Response>): void {
  answers.push(...given.map((answer) => (typeof answer === 'string' ? new Response(answer, { headers: { 'Content-Type': 'text/html' } }) : answer)))
  vi.stubGlobal(
    'fetch',
    vi.fn(async (url: string, init: RequestInit) => {
      if (url.endsWith('/block')) {
        sent.push(init.body as FormData)

        return replies.shift() ?? new Response('', { status: 500 })
      }

      posted.push(init.body as FormData)

      return answers.shift() ?? new Response('', { status: 500 })
    }),
  )
}

const json = (body: unknown, status = 200) => new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })

beforeEach(() => {
  answers.length = 0
  posted.length = 0
  replies.length = 0
  sent.length = 0
})

const $ = <E extends Element = HTMLElement>(selector: string) => document.querySelector<E>(selector)!
const frame = () => $<HTMLIFrameElement>('[data-canvas-frame]')
const inner = () => frame().contentDocument!
const message = () => $('[data-canvas-message]')
const textarea = () => $<HTMLTextAreaElement>('textarea[name="content"]')
const saved = () => JSON.parse(textarea().value) as Doc
const settle = () => new Promise((resolve) => setTimeout(resolve, 0))

/* The tab opened, as tabs.ts opens it. */
async function show(): Promise<void> {
  $('#details').hidden = true
  $('#content').hidden = false
  document.dispatchEvent(new CustomEvent('tabchange', { detail: 'content' }))
  await settle()
}

/* jsdom lays nothing out and scrolls nothing: not around the frame, where
   ProseMirror measures text, nor in it. */
Range.prototype.getClientRects = () => ({ length: 0 }) as DOMRectList
Range.prototype.getBoundingClientRect = () => document.body.getBoundingClientRect()

function unlaid(): void {
  const window = frame().contentWindow! as Window & typeof globalThis

  window.scrollTo = () => {}
  window.Range.prototype.getClientRects = () => ({ length: 0 }) as DOMRectList
  window.Range.prototype.getBoundingClientRect = () => ({ top: 0, bottom: 0, left: 0, right: 0, width: 0, height: 0 }) as DOMRect
}

async function opened(content: Doc, blocks: Record<string, string> = {}): Promise<EditorView> {
  textarea().value = JSON.stringify(content)
  serve(page(content, blocks))

  const view = canvas($('[data-canvas]'))!

  unlaid()
  await show()

  return view()!
}

const tool = (command: string) => $<HTMLButtonElement>(`[data-command="${command}"]`)
const types = () => saved().content!.map((node) => (node.type === 'block' ? `block:${node.attrs!.type}` : node.type))
const at = (view: EditorView, pos: number) => view.dispatch(view.state.tr.setSelection(TextSelection.create(view.state.doc, pos)))
const key = (view: EditorView, init: KeyboardEventInit) =>
  view.dom.dispatchEvent(new (view.dom.ownerDocument.defaultView as Window & typeof globalThis).KeyboardEvent('keydown', { bubbles: true, cancelable: true, ...init }))

test('posts the form on opening the tab, and again only once Details changed', async () => {
  textarea().value = JSON.stringify(doc(text('paragraph', 'Hi')))
  serve(page(doc(text('paragraph', 'Hi'))), page(doc(text('paragraph', 'Hi'))))
  canvas($('[data-canvas]'))
  unlaid()
  await settle()

  expect(fetch).not.toHaveBeenCalled()

  await show()

  expect(fetch).toHaveBeenCalledTimes(1)
  expect(vi.mocked(fetch).mock.calls[0]![0]).toBe('/admin/page/5/canvas')
  expect(vi.mocked(fetch).mock.calls[0]![1]).toMatchObject({ method: 'POST', redirect: 'manual', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
  expect([posted[0]!.get('_token'), posted[0]!.get('title')]).toEqual(['token', 'Home'])

  await show()

  expect(fetch).toHaveBeenCalledTimes(1)

  $<HTMLInputElement>('input[name="title"]').value = 'Welcome'
  await show()

  expect(fetch).toHaveBeenCalledTimes(2)
  expect(posted[1]!.get('title')).toBe('Welcome')
})

test("mounts the editor on the element the content's script sits in, the rest of the page's main as drawn, the layout around it left out", async () => {
  const view = await opened(doc(text('heading', 'Welcome', { level: 1 }), text('paragraph', 'Kept </script> inside')))
  const prose = inner().querySelector('.prose')!

  expect(view.dom).toBe(prose)
  expect([...prose.classList]).toEqual(['prose', 'ProseMirror'])
  expect(prose.innerHTML).toBe('<h1 class="mainstay-current">Welcome</h1><p>Kept &lt;/script&gt; inside</p>')
  expect(inner().querySelector('main > a')!.closest('[contenteditable]')).toBeNull()
  expect(inner().body.innerHTML).toMatch(/^<div class="wrap"><main><a href="\/tags\/news">News<\/a><div class="prose ProseMirror"/)
  expect(inner().body.textContent).not.toMatch(/About|Beside|Footer/)
  expect([message().hidden, $('[data-canvas-toolbar]').hidden, $('[data-canvas-stage]').hidden]).toEqual([true, false, false])

  /* A change goes into Details' raw content, which the dirty guard hears. */
  const heard = vi.fn()

  $('form').addEventListener('input', heard)
  view.dispatch(view.state.tr.insertText('!', 8))

  expect(saved().content![0]).toEqual(text('heading', 'Welcome!', { level: 1 }))
  expect(heard).toHaveBeenCalled()

  /* Put back as it was, it is the text it opened from again. */
  undo(view.state, view.dispatch)

  expect(textarea().value).toBe(JSON.stringify(doc(text('heading', 'Welcome', { level: 1 }), text('paragraph', 'Kept </script> inside'))))
})

test('draws a page that prints its content beside something else read-only, and says one that does not print it', async () => {
  textarea().value = JSON.stringify(doc(text('paragraph', 'Hi')))
  serve(
    page(doc(text('paragraph', 'Hi'), callout('c1')), { c1: '<aside>Callout</aside>' }, (script) => `<article><h1>Title</h1>${script}</article>`),
    new Response('<!doctype html><html><body><header>Menu</header><h1>No content here</h1><footer>Footer</footer></body></html>', { headers: { 'Content-Type': 'text/html' } }),
  )

  const view = canvas($('[data-canvas]'))!

  unlaid()
  await show()

  expect(view()).toBeNull()
  expect(inner().querySelector('article')!.innerHTML).toBe('<h1>Title</h1><p>Hi</p><aside>Callout</aside>')
  expect(message().textContent).toContain('shown read-only here')
  expect($('[data-canvas-toolbar]').hidden).toBe(true)

  $<HTMLInputElement>('input[name="title"]').value = 'Changed'
  await show()

  expect(inner().body.innerHTML).toBe('<header>Menu</header><h1>No content here</h1><footer>Footer</footer>')
  expect(message().textContent).toContain('does not print its content')
})

test("keeps the <main> the content is in, and the page's styles beside it", async () => {
  textarea().value = JSON.stringify(doc(text('paragraph', 'Hi')))
  serve(page(doc(text('paragraph', 'Hi')), {}, (script) => `</main><style>p {}</style><link rel="stylesheet" href="/late.css"><main><div class="prose">${script}</div>`))

  const view = canvas($('[data-canvas]'))!

  unlaid()
  await show()

  expect([...inner().querySelector('.wrap')!.children].map((element) => element.tagName)).toEqual(['STYLE', 'LINK', 'MAIN'])
  expect([inner().querySelector('main')!.textContent, view()?.dom.className]).toEqual(['Hi', 'prose ProseMirror'])
})

test('draws read-only a page whose element holds text beside the content', async () => {
  textarea().value = JSON.stringify(doc(text('paragraph', 'Hi')))
  serve(page(doc(text('paragraph', 'Hi')), {}, (script) => `<section>Read this first. ${script}</section>`))

  const view = canvas($('[data-canvas]'))!

  unlaid()
  await show()

  expect(view()).toBeNull()
  expect(inner().querySelector('section')!.innerHTML).toBe('Read this first. <p>Hi</p>')
})

test("cancels a link's click and middle click in the frame, each page written again", async () => {
  await opened(doc(text('paragraph', 'Hi')))

  for (let write = 0; write < 2; write++) {
    const link = inner().querySelector('main > a')!
    const click = new MouseEvent('click', { bubbles: true, cancelable: true })
    const middle = new MouseEvent('auxclick', { bubbles: true, cancelable: true, button: 1 })

    link.dispatchEvent(click)
    link.dispatchEvent(middle)

    expect([click.defaultPrevented, middle.defaultPrevented]).toEqual([true, true])

    serve(page(doc(text('paragraph', 'Hi'))))
    $<HTMLInputElement>('input[name="title"]').value = `Again ${write}`
    await show()
  }
})

test('draws at once when the Content tab is the one open, and writes only the last page asked for', async () => {
  let answer = (_: Response) => {}
  const late = new Promise<Response>((resolve) => (answer = resolve))

  textarea().value = JSON.stringify(doc(text('paragraph', 'Hi')))
  $('#details').hidden = true
  $('#content').hidden = false
  vi.stubGlobal('fetch', vi.fn().mockReturnValueOnce(late).mockResolvedValueOnce(new Response(page(doc(text('paragraph', 'Second'))), { headers: { 'Content-Type': 'text/html' } })))
  canvas($('[data-canvas]'))
  unlaid()

  expect(fetch).toHaveBeenCalledTimes(1)

  $<HTMLInputElement>('input[name="title"]').value = 'Changed'
  await show()
  answer(new Response(page(doc(text('paragraph', 'First'))), { headers: { 'Content-Type': 'text/html' } }))
  await settle()

  expect(inner().querySelector('.prose')!.textContent).toBe('Second')
})

test('keeps the scroll of the page it draws again', async () => {
  await opened(doc(text('paragraph', 'Hi')))

  const window = frame().contentWindow!
  const scrolled = vi.fn()

  Object.defineProperty(window, 'scrollY', { value: 400, configurable: true })
  window.scrollTo = scrolled as typeof window.scrollTo
  serve(page(doc(text('paragraph', 'Hi'))))
  $<HTMLInputElement>('input[name="title"]').value = 'Changed'
  await show()

  expect(scrolled).toHaveBeenCalledWith(0, 400)
})

test('shows any answer but a page as a message, and tries again on the next opening', async () => {
  textarea().value = JSON.stringify(doc(text('paragraph', 'Hi')))
  serve(
    Response.json({ messages: ['The page is drawn once it has Published on.'] }, { status: 422 }),
    new Response('', { status: 419 }),
    Response.json({ messages: ['The view broke here.'] }, { status: 500 }),
    /* What fetch hands back for the login redirect an ended session gets. */
    { type: 'opaqueredirect', status: 0, ok: false } as Response,
    page(doc(text('paragraph', 'Hi'))),
  )
  canvas($('[data-canvas]'))
  unlaid()

  await show()
  expect(message().textContent).toBe('The page is drawn once it has Published on.')
  expect($('[data-canvas-stage]').hidden).toBe(true)

  $<HTMLInputElement>('input[name="title"]').value = 'Two'
  await show()
  expect(message().textContent).toContain('Sign in again')

  $<HTMLInputElement>('input[name="title"]').value = 'Three'
  await show()
  expect(message().textContent).toBe('The view broke here.')

  await show()
  expect(message().textContent).toContain('Sign in again')

  /* Details unchanged, the tab opened again draws the page. */
  await show()
  expect([fetch, $('[data-canvas-stage]').hidden]).toEqual([expect.anything(), false])
  expect(fetch).toHaveBeenCalledTimes(5)
})

test('moves, duplicates, deletes and turns the current block from the toolbar', async () => {
  const view = await opened(doc(text('paragraph', 'One'), text('paragraph', 'Two'), callout('c1')), { c1: '<aside>Mind the gap</aside>' })

  at(view, 6)
  tool('up').click()
  expect(types()).toEqual(['paragraph', 'paragraph', 'block:callout'])
  expect(saved().content!.map((node) => node.content?.[0]?.text)).toEqual(['Two', 'One', undefined])
  expect(view.state.selection.$from.parent.textContent).toBe('Two')
  expect(tool('up').disabled).toBe(true)

  tool('down').click()
  tool('down').click()
  expect(types()).toEqual(['paragraph', 'block:callout', 'paragraph'])
  expect(tool('down').disabled).toBe(true)

  /* Each move one step for undo. */
  undo(view.state, view.dispatch)
  expect(types()).toEqual(['paragraph', 'paragraph', 'block:callout'])

  /* A site block is selected, duplicated with an id of its own drawing
     what the first drew, and deleted. */
  view.dispatch(view.state.tr.setSelection(NodeSelection.create(view.state.doc, 10)))
  expect(tool('turn').disabled).toBe(true)
  tool('duplicate').click()
  const [, , first, copy] = saved().content!

  expect([first!.attrs!.id, copy!.attrs!.type]).toEqual(['c1', 'callout'])
  expect(copy!.attrs!.id).not.toBe('c1')
  expect([...inner().querySelectorAll('aside')].map((aside) => aside.textContent)).toEqual(['Mind the gap', 'Mind the gap'])

  tool('delete').click()
  expect(types()).toEqual(['paragraph', 'paragraph', 'block:callout'])

  /* Turned: a paragraph into a heading, a list and back. */
  at(view, 2)
  $<HTMLSelectElement>('[data-command="turn"]').value = 'heading-2'
  $('[data-command="turn"]').dispatchEvent(new Event('change'))
  expect(saved().content![0]).toEqual(text('heading', 'One', { level: 2 }))

  $<HTMLSelectElement>('[data-command="turn"]').value = 'bullet_list'
  $('[data-command="turn"]').dispatchEvent(new Event('change'))
  expect(saved().content![0]).toEqual({ type: 'bullet_list', content: [{ type: 'list_item', content: [text('paragraph', 'One')] }] })
  expect($<HTMLSelectElement>('[data-command="turn"]').value).toBe('bullet_list')

  $<HTMLSelectElement>('[data-command="turn"]').value = 'paragraph'
  $('[data-command="turn"]').dispatchEvent(new Event('change'))
  expect(saved().content![0]).toEqual(text('paragraph', 'One'))
})

test('adds a block after the current one from the menu, or in place of an empty paragraph from `/`', async () => {
  const view = await opened(doc(text('paragraph', 'One'), { type: 'paragraph' }))

  at(view, 2)
  tool('add').click()
  expect([$('[data-canvas-menu]').hidden, tool('add').getAttribute('aria-expanded')]).toEqual([false, 'true'])
  expect(document.activeElement).toBe($('[data-insert="paragraph"]'))

  $('[data-insert="heading"]').click()
  expect(types()).toEqual(['paragraph', 'heading', 'paragraph'])
  expect($('[data-canvas-menu]').hidden).toBe(true)
  expect(view.state.selection.$from.parent.type.name).toBe('heading')

  /* `/` in a paragraph with text in it is a `/`. */
  at(view, 2)
  key(view, { key: '/' })
  expect($('[data-canvas-menu]').hidden).toBe(true)

  /* At the start of the empty paragraph it opens the menu there; Escape
     types the `/`. */
  at(view, view.state.doc.content.size - 1)
  key(view, { key: '/' })
  expect($('[data-canvas-menu]').hidden).toBe(false)

  $('[data-canvas-menu]').dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))
  expect([$('[data-canvas-menu]').hidden, saved().content![2]]).toEqual([true, text('paragraph', '/')])

  undo(view.state, view.dispatch)
  at(view, view.state.doc.content.size - 1)
  key(view, { key: '/' })
  $('[data-canvas-menu]').dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowDown', bubbles: true }))
  expect(document.activeElement).toBe($('[data-insert="heading"]'))

  /* A site block takes the empty paragraph's place, with an id, and the page
     is drawn again to show it. */
  serve(page(doc(), { added: '<section>No articles yet</section>' }))
  $('[data-insert="block:latest"]').click()
  expect(types()).toEqual(['paragraph', 'heading', 'block:latest'])
  expect(saved().content![2]!.attrs!.id).toMatch(/^[0-9a-f]{26}$/)
  expect(inner().querySelector('[data-mainstay-placeholder]')!.textContent).toBe('Latest articles')
  expect(posted).toHaveLength(2)
  expect(JSON.parse(posted[1]!.get('content') as string).content[2].attrs.type).toBe('latest')
})

test('starts a paragraph after a site block on Enter, and removes an empty block on Backspace', async () => {
  const view = await opened(doc(callout('c1'), text('paragraph', 'One')), { c1: '<aside>Mind the gap</aside>' })

  view.dispatch(view.state.tr.setSelection(NodeSelection.create(view.state.doc, 0)))
  key(view, { key: 'Enter' })
  expect(types()).toEqual(['block:callout', 'paragraph', 'paragraph'])
  expect(view.state.selection.from).toBe(2)

  key(view, { key: 'Backspace' })
  expect(types()).toEqual(['block:callout', 'paragraph'])
  expect(view.state.selection).toBeInstanceOf(NodeSelection)

  /* An empty item in a list is left to the list's own Backspace, which
     joins it to the item before. */
  view.dispatch(view.state.tr.insert(view.state.doc.content.size, view.state.schema.nodeFromJSON({ type: 'bullet_list', content: [{ type: 'list_item', content: [text('paragraph', 'Two')] }, { type: 'list_item', content: [{ type: 'paragraph' }] }] })))
  at(view, view.state.doc.content.size - 3)
  key(view, { key: 'Backspace' })
  expect(saved().content![2]).toEqual({ type: 'bullet_list', content: [{ type: 'list_item', content: [text('paragraph', 'Two'), { type: 'paragraph' }] }] })
})

test('gives a pasted block an id of its own, the one it was copied from keeping its', async () => {
  const view = await opened(doc(text('paragraph', 'One'), callout('c1')), { c1: '<aside>Mind the gap</aside>' })

  at(view, 1)
  view.pasteHTML(`<div data-mainstay-block='${JSON.stringify({ id: 'c1', type: 'callout', data: { heading: 'Mind the gap' } })}'></div>`, new Event('paste') as ClipboardEvent)

  const ids = saved().content!.filter((node) => node.type === 'block').map((node) => node.attrs!.id)

  expect(ids).toHaveLength(2)
  expect(ids[1]).toBe('c1')
  expect(ids[0]).not.toBe('c1')
})

test('moves a block dragged by its grip to where the line is drawn, in one step undo takes back', async () => {
  const view = await opened(doc(text('paragraph', 'One'), text('paragraph', 'Two'), text('paragraph', 'Three')))
  const blocks = [...view.dom.children] as HTMLElement[]

  blocks.forEach((block, index) => (block.getBoundingClientRect = () => ({ top: index * 40, bottom: index * 40 + 30, height: 30 }) as DOMRect))

  at(view, 2)
  $('[data-canvas-grip]').dispatchEvent(new Event('dragstart'))

  const over = new MouseEvent('dragover', { bubbles: true, cancelable: true, clientY: 70 })
  const heard = vi.fn()

  /* ProseMirror's own drag handling, on its element, never hears it. */
  view.dom.addEventListener('dragover', heard)
  view.dom.addEventListener('drop', heard)

  blocks[2]!.dispatchEvent(over)
  expect(over.defaultPrevented).toBe(true)
  expect([$('[data-canvas-line]').hidden, $('[data-canvas-line]').style.top]).toEqual([false, '80px'])

  blocks[2]!.dispatchEvent(new MouseEvent('drop', { bubbles: true, cancelable: true, clientY: 70 }))
  expect(saved().content!.map((node) => node.content![0]!.text)).toEqual(['Two', 'One', 'Three'])
  expect($('[data-canvas-line]').hidden).toBe(true)
  expect(heard).not.toHaveBeenCalled()

  undo(view.state, view.dispatch)
  expect(textarea().value).toBe(JSON.stringify(doc(text('paragraph', 'One'), text('paragraph', 'Two'), text('paragraph', 'Three'))))

  /* Dropped where it was, nothing changes, and there is nothing to undo. */
  const again = [...view.dom.children] as HTMLElement[]
  const depth = undoDepth(view.state)

  again.forEach((block, index) => (block.getBoundingClientRect = () => ({ top: index * 40, bottom: index * 40 + 30, height: 30 }) as DOMRect))
  at(view, 2)
  $('[data-canvas-grip]').dispatchEvent(new Event('dragstart'))
  again[0]!.dispatchEvent(new MouseEvent('drop', { bubbles: true, cancelable: true, clientY: 30 }))
  expect([undoDepth(view.state), $('[data-canvas-line]').hidden]).toEqual([depth, true])
})

test('keeps its state across a redraw, so undo reaches past it, and opens a hand edit in Details', async () => {
  const view = await opened(doc(text('paragraph', 'One')))

  view.dispatch(view.state.tr.insertText('!', 4))
  serve(page(doc(text('paragraph', 'One!'))))
  $<HTMLInputElement>('input[name="title"]').value = 'Changed'
  await show()

  const again = canvas($('[data-canvas]'))!()!

  expect(again).not.toBe(view)
  undo(again.state, again.dispatch)
  expect(saved()).toEqual(doc(text('paragraph', 'One')))

  /* Edited by hand in Details, the content opens as the page was drawn
     from it. */
  textarea().value = JSON.stringify(doc(text('paragraph', 'By hand')))
  serve(page(doc(text('paragraph', 'By hand'))))
  await show()

  expect(canvas($('[data-canvas]'))!()!.state.doc.textContent).toBe('By hand')
})

/* A site block with text printed by @field, and its sidebar, as the block
   route and block.blade.php draw them. */
const hero = (id = 'h1', data: Record<string, unknown> = { headline: 'Hello', tone: 'calm' }): Doc => ({ type: 'block', attrs: { id, type: 'hero', data } })
const drawn = (id = 'h1', headline = 'Hello', tone = 'calm') =>
  `<section class="hero ${tone}"><h1><span data-mainstay-field="headline" data-mainstay-item="${id}" data-mainstay-label="Headline">${headline}</span></h1><p><span data-mainstay-field="intro" data-mainstay-item="${id}" data-mainstay-label="Intro" data-mainstay-lines></span></p></section>`
const fields = (id = 'h1', headline = 'Hello') =>
  `<input type="hidden" name="id" value="${id}"><input type="hidden" name="type" value="hero"><input name="data[headline]" value="${headline}" data-mainstay-item="${id}" data-mainstay-field="headline"><select name="data[tone]"><option>calm</option><option>loud</option></select><button type="button" data-block-op="add" data-list="data[items]" data-kind="feature">Add feature</button>`

/* The sidebar beside the entry's form, as form.blade.php draws it. */
function sidebarred(invalid: Record<string, unknown> = {}): void {
  const element = $('[data-canvas]')

  element.dataset.blockUrl = '/admin/page/5/block'
  element.dataset.sidebar = 'mainstay-block'
  element.dataset.invalid = JSON.stringify(invalid)
  document.body.insertAdjacentHTML('beforeend', '<form id="mainstay-block" data-canvas-sidebar hidden><div data-sidebar-messages hidden></div><div data-sidebar-body></div></form>')
}

const sidebar = () => $<HTMLFormElement>('[data-canvas-sidebar]')
const field = (name: string) => inner().querySelector<HTMLElement>(`[data-mainstay-field="${name}"]`)!
const fire = (target: EventTarget, type: string, init: KeyboardEventInit = {}) => {
  const window = inner().defaultView as Window & typeof globalThis
  const event = type.startsWith('focus') ? new window.FocusEvent(type, { bubbles: true, ...init }) : type === 'keydown' ? new window.KeyboardEvent(type, { bubbles: true, cancelable: true, ...init }) : new window.Event(type, { bubbles: true, ...init })

  target.dispatchEvent(event)

  return event
}
const data = (index = 1) => saved().content![index]!.attrs!.data as Record<string, unknown>
const wait = (ms: number) => new Promise((resolve) => setTimeout(resolve, ms))

test('opens a site block\'s sidebar once it is selected, as the server draws it, and closes it for anything else', async () => {
  const wire = vi.fn()

  sidebarred()
  textarea().value = JSON.stringify(doc(text('paragraph', 'Intro'), hero()))
  serve(page(doc(text('paragraph', 'Intro'), hero()), { h1: drawn() }))
  replies.push(json({ block: hero().attrs, html: drawn(), form: fields() }))

  const view = canvas($('[data-canvas]'), wire)!

  unlaid()
  await show()
  expect(sidebar().hidden).toBe(true)

  view()!.dispatch(view()!.state.tr.setSelection(NodeSelection.create(view()!.state.doc, 7)))
  await settle()
  await settle()

  expect(sidebar().hidden).toBe(false)
  expect(Object.fromEntries(sent[0]!)).toEqual({ block: JSON.stringify(hero().attrs), _token: 'token' })
  expect(sidebar().querySelector<HTMLInputElement>('[name="data[headline]"]')!.value).toBe('Hello')
  expect(wire).toHaveBeenCalledWith(sidebar().querySelector('[data-sidebar-body]'))

  /* Details hides it, Content shows it again. */
  document.dispatchEvent(new CustomEvent('tabchange', { detail: 'details' }))
  expect(sidebar().hidden).toBe(true)
  document.dispatchEvent(new CustomEvent('tabchange', { detail: 'content' }))
  expect(sidebar().hidden).toBe(false)

  at(view()!, 2)
  expect([sidebar().hidden, sidebar().querySelector('[data-sidebar-body]')!.innerHTML]).toEqual([true, ''])
})

test('types @field text in place into the block\'s data and its sidebar, and the sidebar\'s into the page', async () => {
  sidebarred()
  textarea().value = JSON.stringify(doc(text('paragraph', 'Intro'), hero()))
  serve(page(doc(text('paragraph', 'Intro'), hero()), { h1: drawn() }))
  replies.push(json({ block: hero().attrs, html: drawn(), form: fields() }))

  const view = canvas($('[data-canvas]'))!

  unlaid()
  await show()

  const headline = field('headline')

  expect([headline.getAttribute('contenteditable'), field('intro').getAttribute('contenteditable')]).toEqual(['plaintext-only', 'plaintext-only'])

  /* Focused, its block is selected, and the sidebar opens. */
  fire(headline, 'focusin')
  await settle()
  await settle()
  expect([view()!.state.selection instanceof NodeSelection, view()!.state.selection.from, sidebar().hidden]).toEqual([true, 7, false])

  /* ProseMirror leaves the keys typed in it alone: Backspace deletes text,
     not the block. Enter adds no line to one-line text, and does to text
     with lines. */
  expect(fire(headline, 'keydown', { key: 'Backspace' }).defaultPrevented).toBe(false)
  expect(types()).toEqual(['paragraph', 'block:hero'])
  expect([fire(headline, 'keydown', { key: 'Enter' }).defaultPrevented, fire(field('intro'), 'keydown', { key: 'Enter' }).defaultPrevented]).toEqual([true, false])

  headline.textContent = 'Hello there'
  fire(headline, 'input')
  headline.textContent = 'Hello there!'
  fire(headline, 'input')
  expect([data().headline, sidebar().querySelector<HTMLInputElement>('[name="data[headline]"]')!.value]).toEqual(['Hello there!', 'Hello there!'])

  const input = sidebar().querySelector<HTMLInputElement>('[name="data[headline]"]')!

  input.focus()
  input.value = 'From the side'
  input.dispatchEvent(new Event('input', { bubbles: true }))
  expect([data().headline, field('headline').textContent]).toEqual(['From the side', 'From the side'])

  /* Undo takes back each stretch of typing whole. */
  undo(view()!.state, view()!.dispatch)
  expect(field('headline').textContent).toBe('Hello there!')
  undo(view()!.state, view()!.dispatch)
  expect([data().headline, field('headline').textContent]).toEqual(['Hello', 'Hello'])

  /* The change the typing posts, answered before the next test. */
  await wait(350)
})

test('takes an answer to typing in place as part of the typing, which one undo takes back', async () => {
  sidebarred()
  textarea().value = JSON.stringify(doc(text('paragraph', 'Intro'), hero()))
  serve(page(doc(text('paragraph', 'Intro'), hero()), { h1: drawn() }))
  replies.push(json({ block: hero().attrs, html: drawn(), form: fields() }))

  const view = canvas($('[data-canvas]'))!

  unlaid()
  await show()

  const headline = field('headline')

  fire(headline, 'focusin')
  await settle()
  await settle()

  /* The same data in the server's order changes nothing. */
  replies.push(json({ block: hero('h1', { tone: 'calm', headline: 'Hello there' }).attrs, html: drawn('h1', 'Hello there'), form: null }))
  headline.textContent = 'Hello there'
  fire(headline, 'input')
  await wait(350)
  expect([sent.length, Object.keys(data())]).toEqual([2, ['headline', 'tone']])

  /* Data the server converted joins the typing it answers. jsdom's frame
     never has the focus, so the block was drawn again. */
  replies.push(json({ block: hero('h1', { headline: 'Hello there!', intro: null, tone: 'calm' }).attrs, html: drawn('h1', 'Hello there!'), form: null }))
  field('headline').textContent = 'Hello there!'
  fire(field('headline'), 'input')
  await wait(350)
  expect(data()).toEqual({ headline: 'Hello there!', intro: null, tone: 'calm' })

  undo(view()!.state, view()!.dispatch)
  expect(data()).toEqual({ headline: 'Hello', tone: 'calm' })
})

test('posts a changed sidebar, putting the block\'s data and its HTML answered in place', async () => {
  sidebarred()
  textarea().value = JSON.stringify(doc(text('paragraph', 'Intro'), hero()))
  serve(page(doc(text('paragraph', 'Intro'), hero()), { h1: drawn() }))
  replies.push(json({ block: hero().attrs, html: drawn(), form: fields() }))

  const view = canvas($('[data-canvas]'))!

  unlaid()
  await show()
  view()!.dispatch(view()!.state.tr.setSelection(NodeSelection.create(view()!.state.doc, 7)))
  await settle()
  await settle()

  const tone = sidebar().querySelector<HTMLSelectElement>('[name="data[tone]"]')!

  replies.push(json({ block: hero('h1', { headline: 'Hello', tone: 'loud' }).attrs, html: drawn('h1', 'Hello', 'loud'), form: null }))
  tone.value = 'loud'
  tone.dispatchEvent(new Event('input', { bubbles: true }))
  expect(sent).toHaveLength(1)
  await wait(350)

  expect(Object.fromEntries(sent[1]!)).toEqual({ id: 'h1', type: 'hero', 'data[headline]': 'Hello', 'data[tone]': 'loud', _token: 'token' })
  expect([data().tone, inner().querySelectorAll('section.hero.loud').length, inner().querySelectorAll('section.hero').length]).toEqual(['loud', 1, 1])
  expect(view()!.state.selection.from).toBe(7)

  /* Undone, the block is posted as the document holds it, and drawn again. */
  replies.push(json({ block: hero().attrs, html: drawn(), form: fields() }))
  undo(view()!.state, view()!.dispatch)
  await settle()
  await settle()
  expect([JSON.parse(sent[2]!.get('block') as string).data.tone, inner().querySelectorAll('section.hero.calm').length]).toEqual(['calm', 1])

  /* A list's control posts what it asks, and the sidebar is drawn again. */
  replies.push(json({ block: hero().attrs, html: drawn(), form: `${fields()}<p data-added>Feature 1</p>` }))
  sidebar().querySelector<HTMLButtonElement>('[data-block-op]')!.click()
  await settle()
  await settle()
  expect([sent[3]!.get('op'), sent[3]!.get('list'), sent[3]!.get('kind'), sidebar().querySelectorAll('[data-added]').length]).toEqual(['add', 'data[items]', 'feature', 1])

  /* A change refused says why above the fields, and the next one that is
     not clears it. */
  replies.push(json({ messages: ['The Headline field is required.'], block: hero('h1', { headline: '', tone: 'calm' }).attrs, form: null }, 422))
  sidebar().querySelector('[name="data[tone]"]')!.dispatchEvent(new Event('input', { bubbles: true }))
  await wait(350)
  expect([$('[data-sidebar-messages]').hidden, $('[data-sidebar-messages]').textContent, data().headline]).toEqual([false, 'The Headline field is required.', ''])

  sidebar().querySelector('[data-sidebar-body]')!.insertAdjacentHTML('beforeend', '<p id="field-data-headline-error">The Headline field is required.</p>')
  replies.push(json({ block: hero().attrs, html: drawn(), form: null }))
  sidebar().querySelector('[name="data[tone]"]')!.dispatchEvent(new Event('input', { bubbles: true }))
  await wait(350)
  expect([$('[data-sidebar-messages]').hidden, data().headline, sidebar().querySelector('[id$="-error"]')]).toEqual([true, 'Hello', null])
})

test('types a nested item\'s @field into that item, its lines kept only where its field takes them', async () => {
  const grid: Doc = { type: 'block', attrs: { id: 'g1', type: 'grid', data: { heading: 'Top', items: [{ id: 'f1', type: 'feature', data: { title: 'One', text: 'Line' } }] } } }
  const html = '<section><h2><span data-mainstay-field="heading" data-mainstay-item="g1" data-mainstay-label="Heading">Top</span></h2><h3><span data-mainstay-field="title" data-mainstay-item="f1" data-mainstay-label="Title">One</span></h3><p><span data-mainstay-field="text" data-mainstay-item="f1" data-mainstay-label="Text" data-mainstay-lines>Line</span></p></section>'

  sidebarred()
  textarea().value = JSON.stringify(doc(text('paragraph', 'Intro'), grid))
  serve(page(doc(text('paragraph', 'Intro'), grid), { g1: html }))
  replies.push(json({ block: grid.attrs, html, form: '' }))
  canvas($('[data-canvas]'))

  unlaid()
  await show()

  for (const [name, typed] of [['title', 'One\nand two'], ['text', 'Line\nand more']] as const) {
    fire(field(name), 'focusin')
    await settle()
    await settle()
    field(name).textContent = typed
    fire(field(name), 'input')
  }

  expect(data()).toEqual({ heading: 'Top', items: [{ id: 'f1', type: 'feature', data: { title: 'One and two', text: 'Line\nand more' } }] })

  /* The change the typing posts, answered before the next test. */
  await wait(350)
})

test('drops an answer for a block changed again since it was posted', async () => {
  sidebarred()
  textarea().value = JSON.stringify(doc(text('paragraph', 'Intro'), hero()))
  serve(page(doc(text('paragraph', 'Intro'), hero()), { h1: drawn() }))
  replies.push(json({ block: hero().attrs, html: drawn(), form: fields() }))

  const view = canvas($('[data-canvas]'))!

  unlaid()
  await show()
  view()!.dispatch(view()!.state.tr.setSelection(NodeSelection.create(view()!.state.doc, 7)))
  await settle()
  await settle()

  let answer: (response: Response) => void = () => {}

  replies.push(new Promise<Response>((resolve) => (answer = resolve)) as unknown as Response)
  sidebar().querySelector<HTMLButtonElement>('[data-block-op]')!.click()
  field('headline').textContent = 'Typed meanwhile'
  fire(field('headline'), 'input')
  answer(json({ block: hero('h1', { headline: 'Hello', tone: 'calm', items: [] }).attrs, html: drawn('h1', 'Stale'), form: fields('h1', 'Stale') }))
  await settle()
  await settle()

  expect([data().headline, field('headline').textContent, sidebar().querySelector<HTMLInputElement>('[name="data[headline]"]')!.value]).toEqual(['Typed meanwhile', 'Typed meanwhile', 'Typed meanwhile'])
})

test('marks a block a refused save named, until it changes, and gives a copy its own text', async () => {
  sidebarred({ h1: { label: 'Hero', messages: { 'data.headline': ['The Headline field is required.'] } } })
  $('[data-canvas]').insertAdjacentHTML('afterbegin', '<ul data-canvas-invalid><li data-invalid-block="h1">Hero: The Headline field is required.</li></ul>')
  textarea().value = JSON.stringify(doc(text('paragraph', 'Intro'), hero('h1', { headline: '' })))
  serve(page(doc(text('paragraph', 'Intro'), hero('h1', { headline: '' })), { h1: drawn('h1', '') }))

  const view = canvas($('[data-canvas]'))!

  unlaid()
  await show()
  expect(inner().querySelector('section.hero')!.classList.contains('mainstay-invalid')).toBe(true)

  /* Selected, it is posted with the messages for its sidebar to show. */
  replies.push(json({ block: hero('h1', { headline: '' }).attrs, html: drawn('h1', ''), form: fields('h1', '') }))
  view()!.dispatch(view()!.state.tr.setSelection(NodeSelection.create(view()!.state.doc, 7)))
  await settle()
  await settle()
  expect(JSON.parse(sent[0]!.get('errors') as string)).toEqual({ 'data.headline': ['The Headline field is required.'] })

  /* Duplicated, the copy's text is its own. */
  tool('duplicate').click()
  await settle()

  const [first, copy] = [...inner().querySelectorAll<HTMLElement>('[data-mainstay-field="headline"]')]

  expect(copy!.dataset.mainstayItem).toBe(saved().content![2]!.attrs!.id)
  fire(copy!, 'focusin')
  copy!.textContent = 'Copy'
  fire(copy!, 'input')
  expect([data(1).headline, data(2).headline, first!.textContent]).toEqual(['', 'Copy', ''])
  expect(inner().querySelector('section.hero')!.classList.contains('mainstay-invalid')).toBe(true)

  fire(first!, 'focusin')
  first!.textContent = 'Fixed'
  fire(first!, 'input')
  expect([inner().querySelectorAll('.mainstay-invalid').length, $('[data-canvas]').querySelector('[data-canvas-invalid]')]).toEqual([0, null])
})
