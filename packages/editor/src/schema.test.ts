import { Node } from 'prosemirror-model'
import { expect, test } from 'vitest'
import document from '../../cms/tests/Fixtures/document.json'
import { schema } from './schema'

const load = (json: unknown) => Node.fromJSON(schema, json).check()

// The fixture the PHP renderer is checked against, so the two schemas cannot
// drift apart without one of the two tests saying so.
test('opens the document the PHP renderer draws', () => {
  const doc = Node.fromJSON(schema, document)

  expect(() => doc.check()).not.toThrow()
  expect(doc.toJSON()).toEqual(document)
})

test('refuses what Document::problem() refuses', () => {
  const paragraph = (text: object) => ({ type: 'doc', content: [{ type: 'paragraph', content: [text] }] })

  expect(() => load({ type: 'doc', content: [{ type: 'heading', attrs: { level: 1 }, content: [{ type: 'text', text: 'x' }] }] })).toThrow(RangeError)
  expect(() => load({ type: 'doc', content: [{ type: 'ordered_list', attrs: { order: 1.5 }, content: [{ type: 'list_item', content: [{ type: 'paragraph' }] }] }] })).toThrow(RangeError)
  expect(() => load({ type: 'doc', content: [{ type: 'text', text: 'x' }] })).toThrow(RangeError)
  expect(() => load({ type: 'doc', content: [{ type: 'code_block', content: [{ type: 'text', text: 'x', marks: [{ type: 'strong' }] }] }] })).toThrow(RangeError)
  expect(() => load(paragraph({ type: 'text', text: 'x', marks: [{ type: 'em' }, { type: 'strong' }, { type: 'em' }] }))).toThrow(RangeError)
  // Marks in any order: ProseMirror sorts them as it loads them, as the PHP
  // renderer sorts them to nest them.
  expect(() => load(paragraph({ type: 'text', text: 'x', marks: [{ type: 'strong' }, { type: 'link', attrs: { href: '/x' } }] }))).not.toThrow()
  expect(() => load(paragraph({ type: 'text', text: 'x', marks: [{ type: 'link', attrs: { href: 'java\tscript:alert(1)' } }] }))).toThrow(RangeError)
  expect(() => load({ type: 'doc', content: [] })).toThrow(RangeError)
})
