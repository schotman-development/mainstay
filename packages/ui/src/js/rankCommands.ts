export type Command = {
  id: string
  label: string
  /*
   | Shown right-aligned in the result row and searched alongside the label, so
   | a command can be found by the area it belongs to ("Pages", "Settings").
   */
  group?: string
  /*
   | Words a user might reach for that are not in the label: "logout" for Sign
   | out, "new" for Create. Searched, never displayed.
   */
  keywords?: string
  run: () => void
}

/*
 | Case-insensitive substring match, ranked by where the match lands. An empty
 | query returns everything in author order, which is what makes the palette
 | double as a nav menu before anything is typed.
 |
 | Ties keep author order because Array.sort is stable -- that is load-bearing,
 | not incidental: it is how the caller controls which of two equally-good
 | matches wins.
 */
export function rankCommands(commands: Command[], query: string): Command[] {
  const needle = query.trim().toLowerCase()

  // A copy: this path would otherwise hand the caller its own array back, and a
  // caller that sorts or splices the result would be editing its command list.
  if (!needle) return [...commands]

  return commands
    .map((command) => ({ command, score: score(command, needle) }))
    .filter((entry) => entry.score > 0)
    .sort((a, b) => b.score - a.score)
    .map((entry) => entry.command)
}

function score(command: Command, needle: string): number {
  const label = command.label.toLowerCase()

  if (label.startsWith(needle)) return 3
  if (label.includes(needle)) return 2

  return `${command.group ?? ''} ${command.keywords ?? ''}`.toLowerCase().includes(needle) ? 1 : 0
}
