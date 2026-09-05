import { Schema } from 'prosemirror-model'

/*
 | Deliberately the smallest schema that is still a valid document: prose only,
 | no blocks. Block nodes are what make this a page builder rather than a text
 | box, and they cannot be designed before the field system they render.
 */
export const schema = new Schema({
  nodes: {
    doc: { content: 'block+' },
    paragraph: {
      group: 'block',
      content: 'inline*',
      parseDOM: [{ tag: 'p' }],
      toDOM: () => ['p', 0],
    },
    text: { group: 'inline' },
  },
  marks: {},
})
