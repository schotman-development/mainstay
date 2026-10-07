<?php

namespace Mainstay\Content;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;
use InvalidArgumentException;
use Mainstay\Media\Size;

/*
 | An image in the media library, as a read hands it out: its alt text in the
 | locale it was read in, and the copies of it the field it was read through
 | declares, by name.
 |
 | One that is in the trash, or gone, is still a Media -- `missing`, holding
 | its id and nothing else -- so a list of blocks written back while it is
 | away keeps the reference, and the image's return brings back every place
 | it was used. It prints nothing and links nowhere.
 */
final class Media
{
    public readonly bool $missing;

    /**
     * @param  array{0: int, 1: int}|null  $focal  percentages across and down
     * @param  array<string, Size>  $sizes  by name, the field's
     */
    public function __construct(
        public readonly int $id,
        public readonly ?string $alt = null,
        public readonly ?string $hash = null,
        public readonly ?string $type = null,
        public readonly ?string $name = null,
        public readonly ?array $focal = null,
        private readonly int $originalWidth = 0,
        private readonly int $originalHeight = 0,
        private readonly array $sizes = [],
        /* Who uploaded it, none for code on its own authority: what
           MediaPolicy asks. */
        public readonly ?int $ownerId = null,
    ) {
        $this->missing = $hash === null;
    }

    /* This image with the sizes of the field it is read through. */
    public function sized(array $sizes): self
    {
        return new self($this->id, $this->alt, $this->hash, $this->type, $this->name, $this->focal, $this->originalWidth, $this->originalHeight, $sizes, $this->ownerId);
    }

    /* The copy's address, WebP unless AVIF is asked for. Null for an image
       that is missing. */
    public function url(string $size, string $format = 'webp'): ?string
    {
        if (! in_array($format, ['webp', 'avif'], true)) {
            throw new InvalidArgumentException("Copies are written as webp and avif, not {$format}.");
        }

        $declared = $this->size($size);

        return $this->missing ? null : Storage::disk(config('mainstay.media.disk', 'public'))->url($declared->path($this->hash, $this->focal, $format));
    }

    public function width(string $size): ?int
    {
        return $this->missing ? null : $this->size($size)->output($this->originalWidth, $this->originalHeight, $this->focal)[0];
    }

    public function height(string $size): ?int
    {
        return $this->missing ? null : $this->size($size)->output($this->originalWidth, $this->originalHeight, $this->focal)[1];
    }

    /*
     | The copy as a `<picture>`: the AVIF as a source, the WebP as the
     | `<img>`, with alt and real dimensions on it. Lazy unless the attributes
     | say otherwise, and nothing at all for an image that is missing.
     */
    public function picture(string $size, array $attributes = []): HtmlString
    {
        if ($this->missing) {
            $this->size($size);

            return new HtmlString('');
        }

        $attributes = [
            'src' => $this->url($size),
            'alt' => $this->alt ?? '',
            'width' => $this->width($size),
            'height' => $this->height($size),
            'loading' => 'lazy',
            'decoding' => 'async',
            ...$attributes,
        ];

        return new HtmlString(sprintf(
            '<picture><source type="image/avif" srcset="%s"><img %s></picture>',
            e($this->url($size, 'avif')),
            implode(' ', array_map(fn (string $name, mixed $value) => $name.'="'.e((string) $value).'"', array_keys($attributes), $attributes)),
        ));
    }

    /* A size the field declares. An image read through no field -- from
       find() or upload() -- has none to name. */
    private function size(string $size): Size
    {
        return $this->sizes[$size] ?? throw new InvalidArgumentException($this->sizes === []
            ? "Image {$this->id} was not read through a field, so it has no sizes to name. Read it through the entry that holds it."
            : "Image {$this->id} has no size called {$size}; its field declares ".implode(', ', array_keys($this->sizes)).'.');
    }
}
