<figure>@foreach ($block->slides as $slide)<p>@field($slide, 'title')</p>@endforeach<figcaption>@field($block, 'caption')</figcaption>@auth<small>signed in</small>@endauth</figure>
