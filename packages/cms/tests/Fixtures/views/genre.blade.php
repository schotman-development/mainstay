<h1>{{ $entry->title }}</h1>
@foreach (\Mainstay\Facades\Mainstay::find(\Mainstay\Tests\Fixtures\Linked\Story::class, where: ['genres' => $entry->id], sort: 'title', depth: 0) as $story)
<p>{{ $story->title }}</p>
@endforeach
