import type { Decorator, Meta, StoryObj } from '@storybook/react-vite'
import { PathIcon } from './Icon'
import { Sidebar } from './Sidebar'
import type { SidebarSection } from './Sidebar'

/*
 | A collection and the two things WordPress puts on its flyout: the listing
 | and a blank one. Both hang off the collection's own route, so walking into
 | either of them keeps the collection open in the navigation.
 */
function collection(slug: string, plural: string, singular: string) {
  return {
    href: `/admin/collections/${slug}`,
    label: plural,
    submenu: 'flyout' as const,
    items: [
      { href: `/admin/collections/${slug}`, label: `View ${plural.toLowerCase()}` },
      { href: `/admin/collections/${slug}/new`, label: `New ${singular}` },
    ],
  }
}

/* Shared with Shell.stories.tsx; excludeStories keeps it from being indexed. */
export const sections: SidebarSection[] = [
  {
    /* No group name: the root of the panel is not a category of anything. */
    items: [{ href: '/admin', label: 'Dashboard', icon: <PathIcon d="M3.25 1.75h9.5a1.5 1.5 0 0 1 1.5 1.5v9.5a1.5 1.5 0 0 1-1.5 1.5h-9.5a1.5 1.5 0 0 1-1.5-1.5v-9.5a1.5 1.5 0 0 1 1.5-1.5zM5.25 10.75V7.5M8 10.75V5.25M10.75 10.75V8.75" /> }],
  },
  {
    label: 'Content',
    items: [
      {
        href: '/admin/collections',
        label: 'Collections',
        icon: <PathIcon d="M8 1.75 1.75 5 8 8.25 14.25 5 8 1.75ZM1.75 10.75 8 14 14.25 10.75" />,
        /* Pages is one of these, not a fixture beside them: it is a content
           type with a listing and a blank one, which is all a collection is. */
        items: [
          collection('pages', 'Pages', 'page'),
          collection('posts', 'Posts', 'post'),
          collection('products', 'Products', 'product'),
          collection('events', 'Events', 'event'),
        ],
      },
      { href: '/admin/media', label: 'Media', icon: <PathIcon d="M1.75 11 5.5 7.25l2.75 2.75 2-2 4 4" /> },
    ],
  },
  {
    label: 'System',
    items: [
      { href: '/admin/users', label: 'Users', icon: <PathIcon d="M2.75 14.25a5.25 5.25 0 0 1 10.5 0M10.75 5.25a2.75 2.75 0 1 1-5.5 0 2.75 2.75 0 0 1 5.5 0" /> },
      {
        href: '/admin/plugins',
        label: 'Plugins',
        icon: <PathIcon d="M6 1.75v3.5M10 1.75v3.5M3.75 5.25h8.5v3a4.25 4.25 0 0 1-8.5 0z" />,
        items: [
          { href: '/admin/plugins/seo', label: 'SEO' },
          { href: '/admin/plugins/forms', label: 'Forms' },
          { href: '/admin/plugins/redirects', label: 'Redirects' },
        ],
      },
      { href: '/admin/settings', label: 'Settings', icon: <PathIcon d="M1.75 4.75h12.5M1.75 11.25h12.5" /> },
    ],
  },
]

const meta = {
  title: 'Sidebar',
  component: Sidebar,
  args: { sections },
  parameters: { layout: 'fullscreen' },
  excludeStories: ['sections'],
  decorators: [(Story) => <div className="-m-6 flex h-[32rem] font-sans text-ink"><Story /></div>] as Decorator[],
} satisfies Meta<typeof Sidebar>

export default meta

export const Default: StoryObj<typeof meta> = {
  args: { current: '/admin/collections/pages' },
}

/*
 | A detail route marks the section it belongs to, not nothing at all -- and
 | being inside Posts holds both it and Collections open, with the flyout
 | withdrawn in favour of the list now sitting in the navigation.
 */
export const NestedRoute: StoryObj<typeof meta> = {
  args: { current: '/admin/collections/posts/42/edit' },
}

/* Every parent shut: the chevrons are the way in, and a shut toggle takes its
   collections -- and their flyouts -- down with it. */
export const Collapsed: StoryObj<typeof meta> = {
  args: { current: '/admin' },
}

/*
 | Collections open on its own index, which is where a collection's flyout is
 | actually reached: hover Posts for the panel. Open rather than shut because
 | the toggle can still be folded away from here, and a story of the feature
 | that needs a click before the feature appears is a poor story.
 */
export const CollectionFlyout: StoryObj<typeof meta> = {
  args: { current: '/admin/collections' },
}
