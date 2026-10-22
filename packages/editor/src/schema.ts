import { type Attrs, Schema } from 'prosemirror-model'

/*
 | The closed node schema, and the one packages/cms/src/Content/Document.php
 | checks and renders: the same names, nesting and attributes, so a document a
 | seeder writes by hand is one this opens. schema.test.ts holds the two to one
 | fixture. ProseMirror's own names, as prosemirror-schema-basic and -list give
 | them, written out rather than installed. No images until media, no tables.
 |
 | parseDOM is the mapping pasted HTML goes through: what it does not name is
 | dropped, since the schema is closed.
 */

/* Document::linkable(): a web, mail or phone address, or a path. Asked of the
   text before a colon, which a browser reads as the scheme once it has dropped
   the tabs and newlines inside it. */
export const linkable = (href: unknown): href is string =>
  typeof href === 'string' &&
  href !== '' &&
  !/[\x00-\x1F\x7F]/.test(href) &&
  (/^(?:https?|mailto|tel):/i.test(href) || !/^[^/?#]*:/.test(href))

const levels = [1, 2, 3, 4, 5, 6]

export const schema = new Schema({
  nodes: {
    // `flow` rather than ProseMirror's `block`, which the content schema
    // below names its site block.
    doc: { content: 'flow+' },
    paragraph: {
      group: 'flow',
      content: 'inline*',
      parseDOM: [{ tag: 'p' }],
      toDOM: () => ['p', 0],
    },
    // Every level: a page whose view leaves the title out takes its h1 from
    // the text.
    heading: {
      group: 'flow',
      content: 'inline*',
      defining: true,
      attrs: {
        level: {
          default: 2,
          validate: (level: unknown) => {
            if (!levels.includes(level as number)) throw new RangeError(`A heading's level is 1 to 6, not ${level}`)
          },
        },
      },
      parseDOM: levels.map((level) => ({ tag: `h${level}`, attrs: { level } })),
      toDOM: (node) => [`h${node.attrs.level}`, 0],
    },
    blockquote: {
      group: 'flow',
      content: 'flow+',
      defining: true,
      parseDOM: [{ tag: 'blockquote' }],
      toDOM: () => ['blockquote', 0],
    },
    code_block: {
      group: 'flow',
      content: 'text*',
      marks: '',
      code: true,
      defining: true,
      parseDOM: [{ tag: 'pre', preserveWhitespace: 'full' }],
      toDOM: () => ['pre', ['code', 0]],
    },
    bullet_list: {
      group: 'flow',
      content: 'list_item+',
      parseDOM: [{ tag: 'ul' }],
      toDOM: () => ['ul', 0],
    },
    ordered_list: {
      group: 'flow',
      content: 'list_item+',
      attrs: {
        order: {
          default: 1,
          validate: (order: unknown) => {
            if (!Number.isInteger(order)) throw new RangeError(`An ordered list starts at a whole number, not ${order}`)
          },
        },
      },
      parseDOM: [
        {
          tag: 'ol',
          // A start of 0 is a start; only one that is not a number is none.
          getAttrs: (dom) => {
            const start = Number.parseInt(dom.getAttribute('start') ?? '', 10)

            return { order: Number.isNaN(start) ? 1 : start }
          },
        },
      ],
      toDOM: (node) => (node.attrs.order === 1 ? ['ol', 0] : ['ol', { start: node.attrs.order }, 0]),
    },
    list_item: {
      content: 'paragraph flow*',
      defining: true,
      parseDOM: [{ tag: 'li' }],
      toDOM: () => ['li', 0],
    },
    horizontal_rule: {
      group: 'flow',
      parseDOM: [{ tag: 'hr' }],
      toDOM: () => ['hr'],
    },
    hard_break: {
      group: 'inline',
      inline: true,
      selectable: false,
      parseDOM: [{ tag: 'br' }],
      toDOM: () => ['br'],
    },
    text: { group: 'inline' },
  },
  // In this order, which is the order ProseMirror sorts and nests them in: a
  // link outermost, so a link over a bold word and a plain one is one link.
  marks: {
    link: {
      attrs: {
        href: {
          validate: (href: unknown) => {
            if (!linkable(href)) throw new RangeError(`A link leads to a web, mail or phone address or a path, not ${href}`)
          },
        },
      },
      inclusive: false,
      parseDOM: [{ tag: 'a[href]', getAttrs: (dom) => (linkable(dom.getAttribute('href')) ? { href: dom.getAttribute('href') } : false) }],
      toDOM: (mark) => ['a', { href: mark.attrs.href }, 0],
    },
    em: {
      parseDOM: [{ tag: 'em' }, { tag: 'i' }],
      toDOM: () => ['em', 0],
    },
    strong: {
      parseDOM: [{ tag: 'strong' }, { tag: 'b' }],
      toDOM: () => ['strong', 0],
    },
    code: {
      parseDOM: [{ tag: 'code' }],
      toDOM: () => ['code', 0],
    },
  },
})

/*
 | A page's content: the same text, and at its top level the site's blocks,
 | which Content.php holds as `{type: 'block', attrs: {id, type, data}}`. The
 | editor draws one as the HTML the site's view drew; copied, it is an element
 | carrying its attributes, which is what pasting it reads.
 */
const block = (value: unknown): Attrs | false => {
  try {
    const attrs = JSON.parse(String(value)) as Attrs | null

    return typeof attrs?.type === 'string' ? attrs : false
  } catch {
    return false
  }
}

type Nodes = typeof schema extends Schema<infer N> ? N : never
type Marks = typeof schema extends Schema<string, infer M> ? M : never

export const contentSchema = new Schema<Nodes | 'block', Marks>({
  nodes: schema.spec.nodes.update('doc', { content: '(flow | block)+' }).addToEnd('block', {
    atom: true,
    attrs: { id: { default: null }, type: { validate: 'string' }, data: { default: {} } },
    parseDOM: [{ tag: 'div[data-mainstay-block]', getAttrs: (dom) => block(dom.getAttribute('data-mainstay-block')) }],
    toDOM: (node) => ['div', { 'data-mainstay-block': JSON.stringify(node.attrs) }],
  }),
  marks: schema.spec.marks,
})
