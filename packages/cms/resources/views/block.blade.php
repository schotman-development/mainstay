{{--
 | A site block's sidebar, drawn into the Content tab once the block is
 | selected: its id, its type, and every field it has -- those typed in
 | place on the page too -- posting as its data. `$messages` are a refused
 | save's, by where they are in the block.
--}}
<h2 class="border-b border-border px-4 py-3 text-sm font-medium">{{ \Mainstay\Http\Form::label($block::class) }}</h2>
<input type="hidden" name="id" value="{{ $block->id }}">
<input type="hidden" name="type" value="{{ $block::handle() }}">
<x-mainstay::block-fields :block="$block" prefix="data" :messages="$messages" />
