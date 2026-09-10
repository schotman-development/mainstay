import type { Meta, StoryObj } from 'storybook-php'
import Shell from './blade/shell-chrome.blade.php'

const meta: Meta<typeof Shell> = {
  component: Shell,
  title: 'Shell',
  parameters: { layout: 'fullscreen', controls: { disable: true } },
}

export default meta

/*
 | The fixed chrome on its own: the bar, the navigation, the breadcrumb, and the
 | hole every route drops into. Worth a story of its own because these are the
 | proportions every screen is designed against -- and because it is where the
 | divider rules have to agree, which only shows up in assembly.
 */
export const Chrome: StoryObj<typeof Shell> = {}

/* A route two levels down. The trail lengthens, the navigation opens itself to
   match, and nothing else about the chrome moves. */
export const NestedRoute: StoryObj<typeof Shell> = {
  args: {
    current: '/admin/collections/posts/42/edit',
    breadcrumb: [
      { label: 'Collections', href: '/admin/collections' },
      { label: 'Posts', href: '/admin/collections/posts' },
      { label: 'Shipping the new editor' },
    ],
  },
}
