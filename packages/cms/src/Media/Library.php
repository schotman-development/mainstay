<?php

namespace Mainstay\Media;

use Carbon\CarbonImmutable;
use finfo;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\RecordNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Mainstay\Content\Media;
use Mainstay\Fields\Blocks;
use Mainstay\Fields\Image;
use Mainstay\Mainstay;
use Mainstay\Policies\MediaPolicy;
use RuntimeException;
use Spatie\Image\Drivers\Gd\GdDriver;
use Spatie\Image\Exceptions\CouldNotLoadImage;
use SplFileInfo;
use Throwable;

/*
 | The media library: images uploaded from code, held once each by the hash of
 | their bytes, with every size an image field declares written beside them as
 | plain files in AVIF and WebP at the moment they arrive.
 |
 | The original is kept on a private disk and never served -- a camera's
 | carries the EXIF the copies are re-encoded without, GPS included. The copies
 | are named from everything they are made of, so a name that exists is a file
 | that is right, and nothing ever needs invalidating.
 |
 | spatie/image on GD, chosen here rather than left to the package, which
 | prefers Imagick: GD is on nearly every PHP host, and one engine everywhere
 | is one set of tests proving what is written.
 */
class Library
{
    private const TABLE = 'mainstay_media';

    private const FORMATS = ['avif', 'webp'];

    /* What the library holds, by what the bytes say they are rather than the
       name, and the extension its original is kept under. */
    private const TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];

    /*
     | ponytail: GD decodes into PHP's memory at four bytes a pixel, and the
     | rotation EXIF asks for, like a crop, holds a second image beside the
     | first; ten bytes a pixel covers both, and the file's own bytes, held
     | while it decodes, go on top. An image larger than that leaves room for
     | is refused before it is decoded. A
     | site whose photos need more wants Imagick, which decodes outside PHP's
     | memory limit.
     */
    private const BYTES_PER_PIXEL = 10;

    public function __construct(private Mainstay $mainstay) {}

    /*
     | A file into the library, and every declared size of it onto the media
     | disk. Alt text names every content locale, an empty string marking the
     | image decorative, so a language left out is refused rather than shipped
     | undescribed. Bytes the library already holds are refused naming the
     | image that holds them, trashed or not: an upload neither edits an image
     | nor restores one.
     |
     | The files are written before the row, so a failed upload leaves no
     | record naming a file that is not there. What it did write is whole --
     | see put() -- and a second try uses it.
     |
     | @param  array<string, string>  $alt  by locale
     | @param  array{0: int, 1: int}  $focal  percentages across and down
     */
    public function upload(string|SplFileInfo $file, array $alt = [], array $focal = [50, 50], bool $overrideAccess = false): Media
    {
        $this->authorize('create', Media::class, $overrideAccess);

        $path = $file instanceof SplFileInfo ? $file->getRealPath() : $file;

        if (! is_string($path) || ! is_file($path) || ! is_readable($path)) {
            throw new InvalidArgumentException(sprintf('%s is not a file that can be read.', $file instanceof SplFileInfo ? $file->getPathname() : $file));
        }

        $this->check(['alt' => $alt, 'focal' => $focal], upload: true);
        $type = $this->inspect($path);
        $this->engine();

        $hash = hash_file('sha256', $path);

        if (($held = $this->held($hash)) !== null) {
            throw $this->taken($held);
        }

        $image = $this->decode($path);
        $focal = array_map('intval', $focal);

        $this->put($this->originals(), "media/{$hash}.".self::TYPES[$type], $path);
        $this->generate($image, $hash, $focal);

        $now = $this->now();

        /* In a transaction of its own, which inside a seeder's is a savepoint:
           the second of two uploads racing with the same bytes is refused by
           the index, and on Postgres a failed insert outside a savepoint
           abandons the whole of the caller's transaction. The image that won
           is read with a lock, since a plain read inside a caller's MySQL
           transaction answers from a snapshot older than it. */
        try {
            $id = DB::transaction(fn () => DB::table(self::TABLE)->insertGetId([
                'hash' => $hash,
                'type' => $type,
                'name' => mb_substr($file instanceof SplFileInfo && method_exists($file, 'getClientOriginalName') ? $file->getClientOriginalName() : basename($path), 0, 255),
                'bytes' => filesize($path),
                'width' => $image->getWidth(),
                'height' => $image->getHeight(),
                'focal_x' => $focal[0],
                'focal_y' => $focal[1],
                'alt' => json_encode($alt, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'created_at' => $now,
                'updated_at' => $now,
            ]));
        } catch (UniqueConstraintViolationException $exception) {
            throw $this->taken($this->held($hash, lock: true) ?? throw $exception);
        }

        return $this->find($id, overrideAccess: true);
    }

    /* An image out of the trash, with its alt text in the locale asked for
       or the request's. It names no size: those are a field's. */
    public function find(int $id, ?string $locale = null, bool $overrideAccess = false): ?Media
    {
        $this->authorize('viewAny', Media::class, $overrideAccess);

        return $this->load([$id], $locale ?? App::getLocale())[$id] ?? null;
    }

    /*
     | Alt text for the locales given, the rest kept, and a focal point moved:
     | the crops it moves are written at once, before the row says where the
     | point is, so the row never names copies that are not there yet. The
     | old ones stay, and old URLs go on serving what they served.
     |
     | @param  array<string, string>|null  $alt  by locale
     | @param  array{0: int, 1: int}|null  $focal
     */
    public function update(int $id, ?array $alt = null, ?array $focal = null, bool $overrideAccess = false): Media
    {
        $row = $this->row($id);

        $this->authorize('update', $this->media($row, App::getLocale()), $overrideAccess);
        $this->check(array_filter(['alt' => $alt, 'focal' => $focal], fn (?array $value) => $value !== null), upload: false);

        $changes = ['updated_at' => $this->now()];

        if ($alt !== null) {
            $changes['alt'] = json_encode([...json_decode($row->alt, true), ...$alt], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        }

        if ($focal !== null) {
            $focal = array_map('intval', $focal);
            $this->engine();
            $this->generate($this->source($row), $row->hash, $focal);
            $changes = [...$changes, 'focal_x' => $focal[0], 'focal_y' => $focal[1]];
        }

        DB::table(self::TABLE)->where('id', $id)->update($changes);

        return $this->find($id, overrideAccess: true);
    }

    /* Into the trash. The files stay: an entry still holding the image reads
       it as missing, and has it back if it comes back. */
    public function delete(int $id, bool $overrideAccess = false): void
    {
        $this->authorize('delete', $this->media($this->row($id), App::getLocale()), $overrideAccess);

        DB::table(self::TABLE)->where('id', $id)->whereNull('deleted_at')->update(['deleted_at' => $now = $this->now(), 'updated_at' => $now]);
    }

    /* The copies the declarations name that are not written yet, for an
       image out of the trash. How a size added to a field reaches images
       uploaded before it; mainstay:media:reprocess queues one per image. */
    public function reprocess(int $id): void
    {
        $row = DB::table(self::TABLE)->where('id', $id)->whereNull('deleted_at')->first();

        if ($row !== null && $this->missing($row->hash, [(int) $row->focal_x, (int) $row->focal_y])) {
            $this->engine();
            $this->generate($this->source($row), $row->hash, [(int) $row->focal_x, (int) $row->focal_y]);
        }
    }

    /*
     | The images out of the trash among `$ids`, by id, with alt text in
     | `$locale`: what a read of entries hands its image fields to cast,
     | loaded in one query however many rows and blocks name them. Inlined
     | rather than bound, since SQL Server takes 2100 parameters at most.
     |
     | @param  list<int>  $ids
     | @return array<int, Media>
     */
    public function load(array $ids, string $locale): array
    {
        if ($ids === []) {
            return [];
        }

        return DB::table(self::TABLE)->whereIntegerInRaw('id', array_unique($ids))->whereNull('deleted_at')->get()
            ->mapWithKeys(fn (object $row) => [(int) $row->id => $this->media($row, $locale)])
            ->all();
    }

    /*
     | Which of `$ids` are images out of the trash, which is what a save asks
     | of the images it is given.
     |
     | @param  list<int>  $ids
     | @return list<int>
     */
    public function present(array $ids): array
    {
        return $ids === [] ? [] : DB::table(self::TABLE)->whereIntegerInRaw('id', array_unique($ids))->whereNull('deleted_at')->pluck('id')->map(fn (mixed $id) => (int) $id)->all();
    }

    /*
     | Every size any registered type or block declares, once each: an upload
     | does not know which field it is for. Two fields declaring one alike
     | share its files.
     |
     | @return list<Size>
     */
    private function sizes(): array
    {
        $classes = array_values($this->mainstay->registered());
        $read = $sizes = [];

        while (($class = array_pop($classes)) !== null) {
            if (isset($read[$class])) {
                continue;
            }

            $read[$class] = true;

            foreach ($this->mainstay->fields($class) as $field) {
                if ($field instanceof Image) {
                    foreach ($field->sizes as $size) {
                        $sizes["{$size->width}x{$size->height}"] = $size;
                    }
                } elseif ($field instanceof Blocks) {
                    array_push($classes, ...$field->of);
                }
            }
        }

        return array_values($sizes);
    }

    /* Alt text and the focal point, checked as a save checks a field. */
    private function check(array $values, bool $upload): void
    {
        $locales = array_keys($this->mainstay->locales());
        $rules = [
            'alt' => [$upload ? 'present' : 'sometimes', 'array:'.implode(',', $locales)],
            'focal' => ['sometimes', 'list', 'size:2'],
            'focal.*' => ['integer', 'between:0,100'],
        ];

        foreach ($locales as $locale) {
            $rules["alt.{$locale}"] = [$upload ? 'present' : 'sometimes', 'string', 'max:1000'];
        }

        Validator::make($values, $rules, [
            'alt.*.present' => 'The :attribute field describes the image in that language; an empty string marks it decorative.',
            'focal.size' => 'The focal point is two percentages, across and down: [50, 50].',
        ])->validate();
    }

    /*
     | The file's type, by its bytes, and a size GD can decode in the memory
     | PHP has left -- read from the header, before anything is decoded, so a
     | small file that unpacks to gigabytes is refused rather than run out of
     | memory on.
     */
    private function inspect(string $path): string
    {
        $type = (new finfo(FILEINFO_MIME_TYPE))->file($path);
        $size = isset(self::TYPES[$type]) ? getimagesize($path) : false;

        if ($size === false) {
            throw ValidationException::withMessages(['file' => 'The file is not a JPEG, PNG, GIF or WebP image.']);
        }

        $limit = ini_parse_quantity(ini_get('memory_limit'));

        if ($limit >= 0 && $limit - memory_get_usage() < $size[0] * $size[1] * self::BYTES_PER_PIXEL + filesize($path)) {
            throw ValidationException::withMessages(['file' => sprintf(
                'The image is %s megapixels, more than PHP\'s memory_limit of %s leaves room to decode.',
                round($size[0] * $size[1] / 1_000_000, 1),
                ini_get('memory_limit'),
            )]);
        }

        return $type;
    }

    /* A GD that cannot write both formats is refused before anything is
       written, rather than leaving a <picture> pointing at files that never
       were. */
    private function engine(): void
    {
        if (! extension_loaded('gd')) {
            throw new RuntimeException('The media library writes its copies with PHP\'s GD extension, which is not loaded.');
        }

        foreach (['AVIF', 'WebP'] as $format) {
            if (empty(gd_info()["{$format} Support"])) {
                throw new RuntimeException("This PHP's GD cannot write {$format}, and every copy is written as AVIF and WebP. Build GD with {$format} support.");
            }
        }
    }

    /* Decoded and turned upright: loadFile() reads the EXIF orientation and
       rotates as it loads. */
    private function decode(string $path): GdDriver
    {
        try {
            return (new GdDriver)->loadFile($path);
        } catch (CouldNotLoadImage) {
            throw ValidationException::withMessages(['file' => 'The image could not be decoded.']);
        }
    }

    /* The original, from its disk, which need not be local. */
    private function source(object $row): GdDriver
    {
        $path = "media/{$row->hash}.".self::TYPES[$row->type];
        $local = tempnam(sys_get_temp_dir(), 'mainstay');

        try {
            $stream = $this->originals()->readStream($path) ?? throw new RuntimeException("The original of image {$row->id}, {$path}, is not on its disk.");
            file_put_contents($local, $stream);
            fclose($stream);

            return $this->decode($local);
        } finally {
            unlink($local);
        }
    }

    /* Whether any declared copy of this image at this focal point is not
       written yet. */
    private function missing(string $hash, array $focal): bool
    {
        foreach ($this->sizes() as $size) {
            foreach (self::FORMATS as $format) {
                if (! $this->disk()->exists($size->path($hash, $focal, $format))) {
                    return true;
                }
            }
        }

        return false;
    }

    /*
     | Every declared copy not written yet. A size that crops is cut from the
     | region around the focal point and scaled; spatie's own focal crops are
     | not used, since focalCrop() takes a centre in pixels and does not scale
     | and focalCropAndResize() enlarges. Size works out the region and what
     | is written, so the file and what a template is told agree.
     */
    private function generate(GdDriver $image, string $hash, array $focal): void
    {
        $disk = $this->disk();
        [$width, $height] = [$image->getWidth(), $image->getHeight()];

        foreach ($this->sizes() as $size) {
            $formats = array_filter(self::FORMATS, fn (string $format) => ! $disk->exists($size->path($hash, $focal, $format)));

            if ($formats === []) {
                continue;
            }

            [$x, $y, $cutWidth, $cutHeight] = $size->region($width, $height, $focal);
            [$outWidth, $outHeight] = $size->output($width, $height, $focal);

            /* A clone shares the decoded image, and a crop or a scale puts a
               new one on the clone, so the original stays as it was. */
            $copy = clone $image;

            if ([$cutWidth, $cutHeight] !== [$width, $height]) {
                $copy->manualCrop($cutWidth, $cutHeight, $x, $y);
            }

            if ([$outWidth, $outHeight] !== [$cutWidth, $cutHeight]) {
                $copy->resize($outWidth, $outHeight);
            }

            foreach ($formats as $format) {
                $local = tempnam(sys_get_temp_dir(), 'mainstay');

                try {
                    $copy->format($format)->save($local);
                    clearstatcache(true, $local);

                    /* GD's writers report failure by return value, which
                       spatie does not read. */
                    if (filesize($local) === 0) {
                        throw new RuntimeException("GD wrote nothing for the {$format} copy of {$hash}.");
                    }

                    $this->put($disk, $size->path($hash, $focal, $format), $local);
                } finally {
                    unlink($local);
                }
            }
        }
    }

    /*
     | A file onto a disk under its final name only once it is whole: written
     | under a temporary name beside it and moved into place, every step
     | checked whatever the disk's `throw` says. So a name that exists is a
     | file that was finished, and skipping it is right.
     */
    private function put(Filesystem $disk, string $path, string $local): void
    {
        if ($disk->exists($path)) {
            return;
        }

        $temporary = dirname($path).'/.'.Str::random(20).'.tmp';
        $stream = fopen($local, 'rb');

        try {
            $written = $disk->writeStream($temporary, $stream) && $disk->move($temporary, $path);
        } catch (Throwable $exception) {
            $disk->delete($temporary);

            throw $exception;
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        if (! $written) {
            $disk->delete($temporary);

            throw new RuntimeException("{$path} could not be written to its disk.");
        }
    }

    private function held(string $hash, bool $lock = false): ?object
    {
        return DB::table(self::TABLE)->where('hash', $hash)->when($lock, fn ($query) => $query->lockForUpdate())->first(['id', 'deleted_at']);
    }

    private function taken(object $held): ValidationException
    {
        return ValidationException::withMessages(['file' => "This image is already in the library, as image {$held->id}".($held->deleted_at === null ? '.' : ', in its trash.')]);
    }

    private function row(int $id): object
    {
        return DB::table(self::TABLE)->where('id', $id)->whereNull('deleted_at')->first()
            ?? throw new RecordNotFoundException("Image {$id} is not in the library.");
    }

    private function media(object $row, string $locale): Media
    {
        return new Media(
            (int) $row->id,
            alt: json_decode($row->alt, true)[$locale] ?? null,
            hash: $row->hash,
            type: $row->type,
            name: $row->name,
            focal: [(int) $row->focal_x, (int) $row->focal_y],
            originalWidth: (int) $row->width,
            originalHeight: (int) $row->height,
        );
    }

    /*
     | Gate, about Mainstay's own user, with MediaPolicy answering unless the
     | host chose another -- found as ContentStore finds an entry's, with no
     | guessing by name.
     */
    private function authorize(string $ability, mixed $subject, bool $overrideAccess): void
    {
        if ($overrideAccess) {
            return;
        }

        /* Nobody until phase 9, as in ContentStore. */
        $gate = Gate::forUser(null)->guessPolicyNamesUsing(fn () => []);
        $gate = $gate->getPolicyFor(Media::class) === null ? $gate->policy(Media::class, MediaPolicy::class) : $gate;

        $gate->inspect($ability, $subject)->authorize();
    }

    private function disk(): Filesystem
    {
        return Storage::disk(config('mainstay.media.disk', 'public'));
    }

    private function originals(): Filesystem
    {
        return Storage::disk(config('mainstay.media.originals', 'local'));
    }

    private function now(): string
    {
        return CarbonImmutable::now('UTC')->format('Y-m-d H:i:s');
    }
}
