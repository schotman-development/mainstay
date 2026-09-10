@props(['entry' => 'draft'])

@php($detail = match ($entry) {
    'published' => \Mainstay\Ui\Demo\Entries::published(),
    'blank' => \Mainstay\Ui\Demo\Entries::blank(),
    default => \Mainstay\Ui\Demo\Entries::draft(),
})

{{-- The screen brings the shell with it -- it has to, because the Save button's
     label and variant come from state only it holds, and a decorator cannot
     reach into that to fill the bar's actions slot. So the frame only has to
     give the whole thing a viewport to sit in. --}}
<div class="-m-6 h-dvh">
    <x-stories::entry-details :entry="$detail" />
</div>
