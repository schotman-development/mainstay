<?php

namespace Mainstay\Media;

use InvalidArgumentException;

/*
 | One size an image field declares: a width and a height, cropped to fill
 | that shape around the image's focal point, or a width alone, scaled to.
 | Neither enlarges.
 |
 | Everything about a copy is worked out here from the record alone -- the
 | region cut from the original, the width and height written, the file's
 | name -- so the writer and a template asking for an `<img>`'s dimensions
 | read the same answer, and neither opens a file to get it.
 */
final class Size
{
    public function __construct(public readonly int $width, public readonly ?int $height = null)
    {
        if ($width < 1 || ($height !== null && $height < 1)) {
            throw new InvalidArgumentException("A size is at least one pixel each way; {$width}x{$height} is not.");
        }
    }

    /* `[640, 360]` or `[640]`, as a field declares one. */
    public static function declared(string $name, mixed $size): self
    {
        if (! is_array($size) || ! array_is_list($size) || ! in_array(count($size), [1, 2], true) || array_filter($size, 'is_int') !== $size) {
            throw new InvalidArgumentException("The size {$name} is a width, or a width and a height, in pixels: [640] or [640, 360].");
        }

        return new self(...$size);
    }

    public function crops(): bool
    {
        return $this->height !== null;
    }

    /*
     | The part of an upright original of `$width` by `$height` this size is
     | cut from: the largest region of its shape, as near centred on the
     | focal point as the edges allow. The whole image for a size that only
     | scales.
     |
     | @param  array{0: int, 1: int}  $focal  percentages across and down
     | @return array{0: int, 1: int, 2: int, 3: int}  x, y, width, height
     */
    public function region(int $width, int $height, array $focal): array
    {
        if (! $this->crops()) {
            return [0, 0, $width, $height];
        }

        /* Wider than this size's shape: the full height, and as much width
           as the shape takes. Otherwise the other way about. */
        [$cutWidth, $cutHeight] = $width * $this->height > $height * $this->width
            ? [max(1, min($width, (int) round($height * $this->width / $this->height))), $height]
            : [$width, max(1, min($height, (int) round($width * $this->height / $this->width)))];

        return [
            $this->around($width, $cutWidth, $focal[0]),
            $this->around($height, $cutHeight, $focal[1]),
            $cutWidth,
            $cutHeight,
        ];
    }

    /*
     | The width and height written: the size's own, or the region's where
     | that is smaller, which is how an original smaller than the size is
     | written at its own size and in the shape asked for. A region rounded a
     | pixel past the size's height is scaled to it rather than kept.
     |
     | @return array{0: int, 1: int}
     */
    public function output(int $width, int $height, array $focal): array
    {
        [, , $cutWidth, $cutHeight] = $this->region($width, $height, $focal);

        if ($cutWidth <= $this->width && $cutHeight <= ($this->height ?? $cutHeight)) {
            return [$cutWidth, $cutHeight];
        }

        return $this->crops()
            ? [$this->width, $this->height]
            : [$this->width, max(1, (int) round($cutHeight * $this->width / $cutWidth))];
    }

    /*
     | Where a copy is kept: the original's hash, the size, and the focal
     | point for a size that crops -- so a size that only scales keeps its
     | files when the point moves. Everything a copy is made from is in the
     | name, so a name that exists is already right.
     |
     | ponytail: one directory for every copy. Shard by the hash's first
     | characters when a library outgrows a directory listing.
     */
    public function path(string $hash, array $focal, string $format): string
    {
        return 'media/'.$hash.'-'.$this->width.($this->crops() ? "x{$this->height}-{$focal[0]}-{$focal[1]}" : '').'.'.$format;
    }

    /* The offset that centres a cut of `$cut` on `$percent` of `$length`,
       held inside the edges. */
    private function around(int $length, int $cut, int $percent): int
    {
        return max(0, min($length - $cut, (int) round($length * $percent / 100 - $cut / 2)));
    }
}
