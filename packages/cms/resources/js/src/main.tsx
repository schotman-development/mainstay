import { createRoot } from 'react-dom/client'
import { Editor } from '@mainstay/editor'
import { mount } from '@mainstay/ui/behaviour'
import { api } from './api'
import './index.css'

/*
 | What is left of the bundle now that the admin renders in Blade: the editor
 | island, and the design system's own behaviour -- which ships with the
 | components rather than with the app, so Storybook can mount the same code
 | against the same markup.
 |
 | React is still here, but only underneath ProseMirror -- the editor is a React
 | component and rewriting it is not this change. Nothing else in the panel
 | mounts through it any more.
 */
mount()

const editor = document.getElementById('mainstay-editor')

if (editor) createRoot(editor).render(<Editor />)

/* The admin is just another client of the public content API, so the panel
   reads its own version back out of it rather than off the server that
   rendered the page. */
const status = document.getElementById('mainstay-status')

if (status) {
  api<{ version: string }>()
    .then((response) => (status.textContent = `v${response.version}`))
    .catch((cause: unknown) => (status.textContent = cause instanceof Error ? cause.message : String(cause)))
}
