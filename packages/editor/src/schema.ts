import { Schema } from 'prosemirror-model'

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

const levels = [2, 3, 4]

export const schema = new Schema({
  nodes: {
    doc: { content: 'block+' },
    paragraph: {
      group: 'block',
      content: 'inline*',
      parseDOM: [{ tag: 'p' }],
      toDOM: () => ['p', 0],
    },
    // h1 is the page's title, which is not the body's to write.
    heading: {
      group: 'block',
      content: 'inline*',
      defining: true,
      attrs: {
        level: {
          default: 2,
          validate: (level: unknown) => {
            if (!levels.includes(level as number)) throw new RangeError(`A heading's level is 2, 3 or 4, not ${level}`)
          },
        },
      },
      parseDOM: [
        { tag: 'h1', attrs: { level: 2 } },
        ...levels.map((level) => ({ tag: `h${level}`, attrs: { level } })),
      ],
      toDOM: (node) => [`h${node.attrs.level}`, 0],
    },
    blockquote: {
      group: 'block',
      content: 'block+',
      defining: true,
      parseDOM: [{ tag: 'blockquote' }],
      toDOM: () => ['blockquote', 0],
    },
    code_block: {
      group: 'block',
      content: 'text*',
      marks: '',
      code: true,
      defining: true,
      parseDOM: [{ tag: 'pre', preserveWhitespace: 'full' }],
      toDOM: () => ['pre', ['code', 0]],
    },
    bullet_list: {
      group: 'block',
      content: 'list_item+',
      parseDOM: [{ tag: 'ul' }],
      toDOM: () => ['ul', 0],
    },
    ordered_list: {
      group: 'block',
      content: 'list_item+',
      attrs: {
        order: {
          default: 1,
          validate: (order: unknown) => {
            if (!Number.isInteger(order)) throw new RangeError(`An ordered list starts at a whole number, not ${order}`)
          },
        },
      },
      parseDOM: [{ tag: 'ol', getAttrs: (dom) => ({ order: Number.parseInt(dom.getAttribute('start') ?? '1', 10) || 1 }) }],
      toDOM: (node) => (node.attrs.order === 1 ? ['ol', 0] : ['ol', { start: node.attrs.order }, 0]),
    },
    list_item: {
      content: 'paragraph block*',
      defining: true,
      parseDOM: [{ tag: 'li' }],
      toDOM: () => ['li', 0],
    },
    horizontal_rule: {
      group: 'block',
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
