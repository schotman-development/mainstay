import { expect, test } from 'vitest'
import { rankCommands, type Command } from './rankCommands'

const noop = () => {}

const commands: Command[] = [
  { id: 'pages', label: 'Pages', group: 'Navigate', run: noop },
  { id: 'new-page', label: 'New page', group: 'Create', run: noop },
  { id: 'settings', label: 'Settings', group: 'Navigate', run: noop },
  { id: 'signout', label: 'Sign out', keywords: 'logout exit', run: noop },
  { id: 'audit', label: 'Audit log', group: 'Navigate', keywords: 'trail', run: noop },
]

const ids = (query: string) => rankCommands(commands, query).map((command) => command.id)

test('an empty query returns every command in author order', () => {
  expect(ids('   ')).toEqual(['pages', 'new-page', 'settings', 'signout', 'audit'])
})

test('a label prefix outranks a label substring', () => {
  // settings and signout both match by prefix; pages only in the middle of the
  // word. The prefix pair keeping author order is the stable-sort tie-break.
  expect(ids('s')).toEqual(['settings', 'signout', 'pages'])
})

test('a label substring outranks a keyword match', () => {
  // 'log' is inside the label "Audit log" and inside signout's keyword "logout".
  expect(ids('log')).toEqual(['audit', 'signout'])
})

test('keywords and groups are searchable, and are never displayed as the match', () => {
  expect(ids('logout')).toEqual(['signout'])
  expect(ids('create')).toEqual(['new-page'])
})

test('no match is an empty list, not the full list', () => {
  expect(ids('zzz')).toEqual([])
})

test('the empty-query result is a copy, so a caller cannot mutate the source', () => {
  const result = rankCommands(commands, '')

  result.length = 0

  expect(commands).toHaveLength(5)
})
