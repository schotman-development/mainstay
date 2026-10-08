import { fresh } from './dom'

/* A file dropped on the library's grid opens its upload with the file in it:
   `data-media-drop` names the dialog. */
export function mediaDrops(root: ParentNode = document): void {
  for (const zone of fresh<HTMLElement>(root, '[data-media-drop]')) {
    zone.addEventListener('dragover', (event) => {
      if (event.dataTransfer?.types.includes('Files')) event.preventDefault()
    })

    zone.addEventListener('drop', (event) => {
      const files = event.dataTransfer?.files
      const dialog = document.getElementById(zone.dataset.mediaDrop ?? '')
      const input = dialog?.querySelector<HTMLInputElement>('input[type="file"]')

      if (!files?.length || !(dialog instanceof HTMLDialogElement) || !input) return

      event.preventDefault()
      input.files = files
      dialog.showModal()
    })
  }
}
