import { useEffect, useRef } from 'react'
import { baseKeymap } from 'prosemirror-commands'
import { history, redo, undo } from 'prosemirror-history'
import { keymap } from 'prosemirror-keymap'
import { EditorState } from 'prosemirror-state'
import { EditorView } from 'prosemirror-view'
import { schema } from './schema'

export type EditorProps = {
  className?: string
}

export function Editor({ className }: EditorProps) {
  const host = useRef<HTMLDivElement>(null)

  useEffect(() => {
    const mount = host.current
    if (!mount) return

    const view = new EditorView(mount, {
      state: EditorState.create({
        schema,
        plugins: [
          history(),
          keymap({ 'Mod-z': undo, 'Mod-y': redo, 'Shift-Mod-z': redo }),
          keymap(baseKeymap),
        ],
      }),
    })

    // ProseMirror owns this DOM subtree; React must hand it back on unmount, or
    // StrictMode's double-invoked effect leaves two views fighting over it.
    return () => view.destroy()
  }, [])

  return <div ref={host} className={className} />
}
