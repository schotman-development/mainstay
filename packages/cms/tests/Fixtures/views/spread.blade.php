<h1>{{ $entry->title }}</h1>
<p>id {{ $entry->id ?? 'none' }}, {{ $entry->url() ?? 'no address' }}, issued {{ $entry->issuedOn->toDateString() }}</p>
<p>{{ isset($entry->memo) ? 'memo: '.$entry->memo : 'no memo' }}</p>
<p>@auth signed in as {{ auth()->user()->name }} @else a guest @endauth, {{ app('auth.driver')->guest() ? 'to its guard too' : 'signed in to its guard' }}</p>
<ul>@foreach ($entry->genres as $genre)<li>{{ $genre->title }}</li>@endforeach</ul>
<aside>{{ $entry->aside }}</aside>
@foreach (\Mainstay\Facades\Mainstay::find(\Mainstay\Tests\Fixtures\Spread::class, sort: 'slug') as $other)
    @if ($other->id !== ($entry->id ?? null))<section>{{ $other->title }}: {{ $other->content }}{{ isset($other->memo) ? ' memo: '.$other->memo : '' }}</section>@endif
@endforeach
@if ($entry->slug === 'broken') {{ throw new \RuntimeException('The view broke here.') }} @endif
<main class="prose">{{ $entry->content }}</main>
