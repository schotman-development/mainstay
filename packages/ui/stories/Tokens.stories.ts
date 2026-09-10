import type { Meta, StoryObj } from 'storybook-php'
import TokenTable from './blade/tokens.blade.php'
import themeCss from '../resources/css/theme.css?raw'

const meta: Meta<typeof TokenTable> = {
  component: TokenTable,
  title: 'Tokens',
  args: { css: themeCss },
  parameters: { controls: { disable: true } },
}

export default meta

export const Tokens: StoryObj<typeof TokenTable> = {}
