import { StrictMode } from 'react'
import { act } from 'react'
import { createRoot } from 'react-dom/client'
import { expect, test } from 'vitest'
import { Editor } from './Editor'

test('mounts a ProseMirror view and hands the DOM back on unmount', async () => {
  const host = document.createElement('div')
  document.body.append(host)
  const root = createRoot(host)

  await act(async () => {
    root.render(
      <StrictMode>
        <Editor />
      </StrictMode>,
    )
  })

  // StrictMode invokes the effect twice. Without the destroy() cleanup this is
  // 2, and every later assertion still passes -- so this is the one that counts.
  expect(host.querySelectorAll('.ProseMirror')).toHaveLength(1)

  const view = host.querySelector('.ProseMirror')
  expect(view?.getAttribute('contenteditable')).toBe('true')
  expect(view?.querySelector('p')).not.toBeNull()

  await act(async () => root.unmount())

  expect(host.querySelector('.ProseMirror')).toBeNull()
})
