import { checkboxes } from './checkbox'
import { commandCenters } from './command-center'
import { dirtyForms } from './dirty-form'
import { dropdowns } from './dropdown'
import { entryLists } from './entry-list'
import { entryStatus } from './entry-status'
import { sidebar } from './sidebar'
import { tagInputs } from './tag-input'

export { rankCommands } from './rankCommands'
export type { Command } from './rankCommands'

/*
 | Everything the markup cannot say for itself, wired in one call.
 |
 | Safe to call again: the delegated listeners attach once for the life of the
 | page and the per-element wiring skips anything it has already seen. That is
 | what lets Storybook call it after every story render -- the components are
 | live in the workshop, which is the only place their behaviour gets looked at
 | before it reaches the admin.
 */
export function mount(root: ParentNode = document): void {
  sidebar(root)
  checkboxes(root)
  dropdowns()
  tagInputs()
  commandCenters(root)
  entryLists(root)
  entryStatus(root)
  dirtyForms(root)
}
