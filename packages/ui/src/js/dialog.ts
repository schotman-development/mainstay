import { once } from './dom'

/*
 | The platform's <dialog>, opened as a modal by any control naming it in
 | `data-dialog-open` and closed by anything inside marked `data-dialog-close`.
 | Escape, the focus trap and giving focus back are the browser's.
 */
export function dialogs(): void {
  once('dialogs', () => {
    document.addEventListener('click', (event) => {
      const target = event.target as Element | null
      const opener = target?.closest<HTMLElement>('[data-dialog-open]')

      if (opener) {
        document.getElementById(opener.dataset.dialogOpen ?? '')?.closest('dialog')?.showModal()

        return
      }

      target?.closest('[data-dialog-close]')?.closest('dialog')?.close()
    })
  })
}
