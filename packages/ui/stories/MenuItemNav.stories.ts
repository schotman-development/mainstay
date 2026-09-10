import type { Meta, StoryObj } from 'storybook-php'
import AsNavigation from './blade/menu-item-nav.blade.php'

const meta: Meta<typeof AsNavigation> = {
  component: AsNavigation,
  id: 'menu-item',
  title: 'Menu item/As navigation',
  parameters: { controls: { disable: true } },
}

export default meta

export const AsNav: StoryObj<typeof AsNavigation> = {}
