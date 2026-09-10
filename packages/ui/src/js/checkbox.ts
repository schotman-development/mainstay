import { fresh } from './dom'

/*
 | Part-checked is a DOM property with no attribute behind it, so it cannot be
 | rendered. The markup carries the intent and this turns it into the property.
 |
 | Without it a part-selected page shows an empty box, which reads as "nothing
 | here is selected" when the truth is the opposite.
 */
export function checkboxes(root: ParentNode = document): void {
  for (const box of fresh<HTMLInputElement>(root, 'input[type=checkbox][data-indeterminate]')) {
    box.indeterminate = true
  }
}
