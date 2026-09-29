<h1>{{ $entry->title }}</h1>
<a href="{{ $entry->url() }}">link</a>
<p>{{ isset($entry->editorNote) ? 'note: '.$entry->editorNote : 'no note' }}</p>
<p>locale {{ app()->getLocale() }}</p>
