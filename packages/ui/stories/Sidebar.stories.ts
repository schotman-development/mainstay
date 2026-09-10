import type { Meta, StoryObj } from 'storybook-php'
import Sidebar from './blade/sidebar-sections.blade.php'

const meta: Meta<typeof Sidebar> = {
  component: Sidebar,
  title: 'Sidebar',
  parameters: { layout: 'fullscreen' },
}

export default meta

type Story = StoryObj<typeof Sidebar>

export const Default: Story = { args: { current: '/admin/collections/pages' } }

/*
 | A detail route marks the section it belongs to, not nothing at all -- and
 | being inside Posts holds both it and Collections open, with the flyout
 | withdrawn in favour of the list now sitting in the navigation.
 */
export const NestedRoute: Story = { args: { current: '/admin/collections/posts/42/edit' } }

/* Every parent shut: the chevrons are the way in, and a shut toggle takes its
   collections -- and their flyouts -- down with it. */
export const Collapsed: Story = { args: { current: '/admin' } }

/*
 | Collections open on its own index, which is where a collection's flyout is
 | actually reached: hover Posts for the panel. Open rather than shut because
 | the toggle can still be folded away from here, and a story of the feature
 | that needs a click before the feature appears is a poor story.
 */
export const CollectionFlyout: Story = { args: { current: '/admin/collections' } }
