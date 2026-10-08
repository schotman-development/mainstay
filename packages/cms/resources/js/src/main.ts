import { editors } from '@mainstay/editor'
import { mount } from '@mainstay/ui/behaviour'
import './index.css'

/*
 | What the admin's Blade cannot do for itself: the design system's own
 | behaviour -- which ships with the components rather than with the app, so
 | Storybook can mount the same code against the same markup -- and the rich
 | text editor on each rich text field.
 */
mount()
editors()
