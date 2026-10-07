<?php

namespace Mainstay\Tests;

use GdImage;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Mainstay\Content\Media;
use Mainstay\Facades\Mainstay;
use Mainstay\Mainstay as Registry;
use Mainstay\Media\Reprocess;
use Mainstay\Media\Size;
use Mainstay\Tests\Fixtures\Broken\Framed;
use Mainstay\Tests\Fixtures\Broken\Unsized;
use Mainstay\Tests\Fixtures\Pictured;
use Mainstay\Tests\Fixtures\Poster;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

/*
 | The phase 6 check: images uploaded through the library, every declared size
 | written as plain files in both formats under the original's hash, and read
 | back through entries -- a field of their own and two blocks down -- in one
 | query per read, with what a trashed image leaves behind kept rather than
 | lost.
 |
 | The images are drawn with GD here rather than kept as fixtures, so what
 | each pixel should be is written beside the test that reads it back.
 */
class MediaTest extends DatabaseTestCase
{
    private const ALT = ['en' => 'Red beside blue', 'nl' => 'Rood naast blauw'];

    /** @var list<string> */
    private array $files = [];

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('mainstay.locales', ['en' => '/', 'nl' => '/nl']);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Storage::fake('local');

        $this->declare(Pictured::class);
        $this->artisan('mainstay:sync')->assertSuccessful();
    }

    protected function tearDown(): void
    {
        array_map(unlink(...), array_filter($this->files, is_file(...)));

        parent::tearDown();
    }

    /* A PNG of `$width` by `$height`, red up to `$red` pixels across and
       blue after, with a pixel of `$mark`'s grey in the corner so no two
       drawings share a hash. */
    private function png(int $width, int $height, int $red, int $mark = 0): string
    {
        return $this->draw($width, $height, $red, $mark, fn (GdImage $image, string $path) => imagepng($image, $path));
    }

    private function draw(int $width, int $height, int $red, int $mark, callable $write): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, imagecolorallocate($image, 0, 0, 255));

        if ($red > 0) {
            imagefilledrectangle($image, 0, 0, $red - 1, $height - 1, imagecolorallocate($image, 255, 0, 0));
        }

        imagesetpixel($image, $width - 1, $height - 1, imagecolorallocate($image, $mark, $mark, $mark));

        $this->files[] = $path = tempnam(sys_get_temp_dir(), 'mainstay-media-');
        $write($image, $path);

        return $path;
    }

    /* `$width` by `$height` as the camera stored it, red on its left half,
       and an EXIF orientation of 6: turn it a quarter clockwise to see it. */
    private function rotatedJpeg(int $width, int $height): string
    {
        $path = $this->draw($width, $height, intdiv($width, 2), 0, fn (GdImage $image, string $path) => imagejpeg($image, $path, 100));

        /* One IFD, one entry: Orientation, a SHORT, 6. Big-endian. */
        $tiff = "MM\x00\x2A".pack('N', 8).pack('n', 1).pack('nnN', 0x0112, 3, 1).pack('n', 6)."\x00\x00".pack('N', 0);
        $exif = "Exif\x00\x00".$tiff;
        $jpeg = file_get_contents($path);
        file_put_contents($path, substr($jpeg, 0, 2)."\xFF\xE1".pack('n', strlen($exif) + 2).$exif.substr($jpeg, 2));

        return $path;
    }

    private function upload(string $file, array $alt = self::ALT, array $focal = [50, 50]): Media
    {
        return Mainstay::media()->upload($file, alt: $alt, focal: $focal, overrideAccess: true);
    }

    /* Which colour a written copy is at a pixel, by its strongest channel:
       lossy formats come back near the colour drawn, not on it. */
    private function colour(string $path, int $x, int $y): string
    {
        $image = imagecreatefromstring(Storage::disk('public')->get($path));
        $rgb = imagecolorsforindex($image, imagecolorat($image, $x, $y));

        return $rgb['red'] > $rgb['blue'] ? 'red' : 'blue';
    }

    /* The width and height a copy was written at. */
    private function dimensions(string $path): array
    {
        return array_slice(getimagesizefromstring(Storage::disk('public')->get($path)), 0, 2);
    }

    private function create(array $data): Pictured
    {
        return Mainstay::create(Pictured::class, ['title' => 'Pictured', ...$data], locale: 'en', overrideAccess: true);
    }

    /* A strip of one figure holding `$image`. */
    private function figures(mixed ...$images): array
    {
        return [['type' => 'strip', 'data' => ['figures' => array_map(fn (mixed $image) => ['type' => 'figure', 'data' => ['image' => $image]], $images)]]];
    }

    /* The field errors a write was refused with. */
    private function refusal(callable $write): array
    {
        try {
            $write();
        } catch (ValidationException $exception) {
            return $exception->errors();
        }

        $this->fail('The write was not refused.');
    }

    #[Test]
    public function an_upload_keeps_the_original_privately_and_writes_every_declared_size_in_both_formats(): void
    {
        $media = $this->upload($file = $this->png(120, 80, 48));
        $hash = hash_file('sha256', $file);

        $this->assertSame($hash, $media->hash);
        $this->assertFalse($media->missing);
        $this->assertSame('Red beside blue', $media->alt);
        $this->assertSame('image/png', $media->type);
        $this->assertSame([50, 50], $media->focal);
        $this->assertTrue(Storage::disk('local')->exists("media/{$hash}.png"));
        $this->assertSame(file_get_contents($file), Storage::disk('local')->get("media/{$hash}.png"), 'The original is kept as it came.');

        /* The cover's crop and its scale, and the figure's crop two blocks
           down: every size a registered type declares, once each. */
        $expected = [];

        foreach (['-40x20-50-50', '-60', '-16x16-50-50'] as $size) {
            foreach (['avif', 'webp'] as $format) {
                $expected[] = "media/{$hash}{$size}.{$format}";
            }
        }

        $written = Storage::disk('public')->files('media');
        sort($expected);
        sort($written);
        $this->assertSame($expected, $written, 'Only the copies are public, never the original.');

        $this->assertSame([40, 20], $this->dimensions("media/{$hash}-40x20-50-50.webp"));
        $this->assertSame([60, 40], $this->dimensions("media/{$hash}-60.webp"));
        $this->assertSame([16, 16], $this->dimensions("media/{$hash}-16x16-50-50.avif"));

        /* Bytes the library holds already are refused naming the image
           holding them, and nothing is written for them. */
        $this->assertSame(['file' => ["This image is already in the library, as image {$media->id}."]], $this->refusal(fn () => $this->upload($file)));
        $this->assertSame(1, DB::table('mainstay_media')->count());
        $this->assertCount(6, Storage::disk('public')->files('media'));
    }

    #[Test]
    public function a_rotated_jpeg_comes_out_upright(): void
    {
        $media = $this->upload($this->rotatedJpeg(40, 20));
        $row = DB::table('mainstay_media')->find($media->id);

        $this->assertSame([20, 40], [(int) $row->width, (int) $row->height], 'Its size is the upright one.');
        $this->assertSame([20, 40], $this->dimensions("media/{$media->hash}-60.webp"), 'Smaller than the size, so written at its own.');
        $this->assertSame('red', $this->colour("media/{$media->hash}-60.webp", 10, 8), 'The left of what the camera stored is the top of what it saw.');
        $this->assertSame('blue', $this->colour("media/{$media->hash}-60.webp", 10, 32));
    }

    #[Test]
    public function a_crop_keeps_the_focal_point_in_frame_and_moving_it_writes_new_files_beside_the_old(): void
    {
        /* Red for the first 60 of 200 pixels across. A square cut is the full
           height: 100 across, as near the point as the edges allow. */
        $media = $this->upload($this->png(200, 100, 60), focal: [10, 50]);
        $hash = $media->hash;

        /* The point 20 pixels in, the cut starting at the left edge: 3.2 of
           the 16 written. */
        $this->assertSame('red', $this->colour("media/{$hash}-16x16-10-50.webp", 3, 8));
        $this->assertSame('red', $this->colour("media/{$hash}-16x16-10-50.avif", 3, 8));

        $moved = Mainstay::media()->update($media->id, focal: [90, 50], overrideAccess: true);

        /* The point 180 pixels in, the cut held at the right edge, from 100:
           12.8 of the 16 written, and all of it blue. */
        $this->assertSame([90, 50], $moved->focal);
        $this->assertSame('blue', $this->colour("media/{$hash}-16x16-90-50.webp", 12, 8));
        $this->assertSame('blue', $this->colour("media/{$hash}-16x16-90-50.webp", 3, 8));
        $this->assertTrue(Storage::disk('public')->exists("media/{$hash}-16x16-10-50.webp"), 'Old URLs go on serving what they served.');
        $this->assertCount(2, array_filter(Storage::disk('public')->files('media'), fn (string $path) => str_contains($path, "{$hash}-60.")), 'A size that only scales keeps its files when the point moves.');
    }

    #[Test]
    public function nothing_is_enlarged_and_every_file_is_the_size_a_template_is_told(): void
    {
        $small = $this->upload($this->png(30, 10, 10));
        $large = $this->upload($this->png(300, 90, 100), focal: [20, 70]);

        foreach ([$small, $large] as $media) {
            $entry = $this->create(['cover' => $media, 'blocks' => $this->figures($media)]);
            $read = Mainstay::findById(Pictured::class, $entry->id, locale: 'en');
            $copies = ['card' => $read->cover, 'wide' => $read->cover, 'thumb' => $read->blocks[0]->figures[0]->image];

            foreach ($copies as $size => $image) {
                foreach (['webp', 'avif'] as $format) {
                    $path = parse_url($image->url($size, $format), PHP_URL_PATH);

                    $this->assertSame([$image->width($size), $image->height($size)], $this->dimensions(substr($path, strpos($path, 'media/'))), "{$size} as {$format}");
                }
            }
        }

        $read = Mainstay::find(Pictured::class, locale: 'en')->first();
        $this->assertSame([20, 10], [$read->cover->width('card'), $read->cover->height('card')], 'In the shape asked for, at the original\'s own size.');
        $this->assertSame([1, 100], (new Size(1, 100))->output(7, 101, [50, 50]), 'A region rounded past the height is scaled to it.');
        $this->assertSame([30, 10], [$read->cover->width('wide'), $read->cover->height('wide')]);
    }

    #[Test]
    public function what_is_not_an_image_or_will_not_fit_in_memory_is_refused_with_nothing_written(): void
    {
        $this->files[] = $text = sys_get_temp_dir().'/mainstay-media-'.uniqid().'.png';
        file_put_contents($text, 'Not a picture at all.');

        $this->assertSame(['file' => ['The file is not a JPEG, PNG, GIF or WebP image.']], $this->refusal(fn () => $this->upload($text)));
        $this->assertArrayHasKey('file', $this->refusal(fn () => $this->upload($this->draw(20, 20, 10, 0, fn (GdImage $image, string $path) => imagebmp($image, $path)))), 'An image of a kind the library does not hold.');
        file_put_contents($svg = $this->files[] = sys_get_temp_dir().'/mainstay-media-'.uniqid().'.svg', '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20"/>');
        $this->assertArrayHasKey('file', $this->refusal(fn () => $this->upload($svg)));

        /* A header claiming 50,000 pixels square, with nothing behind it: read
           before anything is decoded. */
        $huge = tempnam(sys_get_temp_dir(), 'mainstay-media-');
        $this->files[] = $huge;
        file_put_contents($huge, "\x89PNG\r\n\x1a\n".pack('N', 13).'IHDR'.pack('NN', 50_000, 50_000)."\x08\x02\x00\x00\x00".pack('N', 0));
        $limit = ini_get('memory_limit');

        try {
            ini_set('memory_limit', '512M');
            $this->assertSame(['file' => ["The image is 2500 megapixels, more than PHP's memory_limit of 512M leaves room to decode."]], $this->refusal(fn () => $this->upload($huge)));
        } finally {
            ini_set('memory_limit', $limit);
        }

        $image = $this->png(20, 20, 10);
        $this->assertArrayHasKey('alt.nl', $this->refusal(fn () => $this->upload($image, alt: ['en' => 'Only English'])), 'Every content locale is described, or marked decorative.');
        $this->assertArrayHasKey('alt', $this->refusal(fn () => $this->upload($image, alt: [...self::ALT, 'de' => 'Rot neben Blau'])));
        $this->assertArrayHasKey('focal.0', $this->refusal(fn () => $this->upload($image, focal: [150, 50])));

        $this->assertSame(0, DB::table('mainstay_media')->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertSame([], Storage::disk('public')->allFiles());

        $decorative = $this->upload($image, alt: ['en' => '', 'nl' => '']);
        $this->assertSame('', $decorative->alt);
    }

    #[Test]
    public function a_write_that_fails_leaves_no_row_and_no_part_of_a_file(): void
    {
        $failing = function (string $disk): void {
            $fake = Storage::disk($disk);
            Storage::set($disk, new class($fake->getDriver(), $fake->getAdapter(), $fake->getConfig()) extends FilesystemAdapter
            {
                public function move($from, $to)
                {
                    return false;
                }
            });
        };
        $file = $this->png(40, 40, 20);
        $hash = hash_file('sha256', $file);

        $failing('local');
        $this->assertThrows(fn () => $this->upload($file), RuntimeException::class, "media/{$hash}.png could not be written to its disk.");
        $this->assertSame([], Storage::disk('local')->allFiles(), 'The temporary file goes with the failure.');
        $this->assertSame([], Storage::disk('public')->allFiles());

        /* The original whole, the copies not: a second try uses the one and
           writes the others. */
        Storage::fake('local');
        $failing('public');
        $this->assertThrows(fn () => $this->upload($file), RuntimeException::class);
        $this->assertSame(["media/{$hash}.png"], Storage::disk('local')->allFiles());
        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->assertSame(0, DB::table('mainstay_media')->count());

        Storage::fake('public');
        $this->upload($file);
        $this->assertCount(6, Storage::disk('public')->files('media'));
        $this->assertSame(1, DB::table('mainstay_media')->count());
    }

    #[Test]
    public function images_read_back_through_entries_with_alt_text_in_the_locale_read(): void
    {
        $cover = $this->upload($this->png(80, 40, 40), alt: ['en' => 'A "quoted" <cover>', 'nl' => 'Een omslag']);
        $figure = $this->upload($this->png(32, 32, 16, mark: 1), alt: ['en' => 'A figure', 'nl' => 'Een figuur']);

        $created = $this->create(['title' => 'Shots', 'cover' => $cover, 'blocks' => $this->figures($figure->id)]);
        $id = $created->id;
        $this->assertSame('A "quoted" <cover>', $created->cover->alt, 'A write hands back what it wrote, images read as a read reads them.');
        Mainstay::update(Pictured::class, $id, ['title' => 'Plaatjes'], locale: 'nl', overrideAccess: true);

        $en = Mainstay::findById(Pictured::class, $id, locale: 'en');
        $nl = Mainstay::findById(Pictured::class, $id, locale: 'nl');

        $this->assertInstanceOf(Media::class, $en->cover);
        $this->assertSame($cover->id, $en->cover->id);
        $this->assertSame('A "quoted" <cover>', $en->cover->alt);
        $this->assertSame('Een omslag', $nl->cover->alt);
        $this->assertSame('A figure', $en->blocks[0]->figures[0]->image->alt);
        $this->assertSame('Een figuur', $nl->blocks[0]->figures[0]->image->alt);

        $webp = Storage::disk('public')->url("media/{$cover->hash}-40x20-50-50.webp");
        $avif = Storage::disk('public')->url("media/{$cover->hash}-40x20-50-50.avif");
        $this->assertSame($webp, $en->cover->url('card'));
        $this->assertSame($avif, $en->cover->url('card', 'avif'));
        $this->assertSame(
            '<picture><source type="image/avif" srcset="'.e($avif).'"><img src="'.e($webp).'" alt="A &quot;quoted&quot; &lt;cover&gt;" width="40" height="20" loading="lazy" decoding="async"></picture>',
            (string) $en->cover->picture('card'),
        );
        $this->assertStringContainsString('loading="eager" decoding="async" class="hero"', (string) $en->cover->picture('card', ['loading' => 'eager', 'class' => 'hero']));

        $this->assertThrows(fn () => $en->cover->url('poster'), InvalidArgumentException::class, "Image {$cover->id} has no size called poster; its field declares card, wide.");
        $this->assertThrows(fn () => Mainstay::media()->find($cover->id)->url('card'), InvalidArgumentException::class, 'was not read through a field');
        $this->assertSame('Een omslag', Mainstay::media()->find($cover->id, locale: 'nl')->alt);
    }

    #[Test]
    public function a_listing_loads_every_image_its_entries_hold_in_one_query(): void
    {
        foreach (range(1, 3) as $mark) {
            $this->create(['cover' => $this->upload($this->png(20, 20, 10, mark: $mark)), 'blocks' => $this->figures(
                $this->upload($this->png(20, 20, 5, mark: $mark)),
                $this->upload($this->png(20, 20, 15, mark: $mark)),
            )]);
        }

        $count = function (callable $read): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $entries = $read();
            DB::disableQueryLog();

            foreach ($entries as $entry) {
                $this->assertFalse($entry->cover->missing);
                $this->assertFalse($entry->blocks[0]->figures[1]->image->missing);
            }

            return count(array_filter(DB::getQueryLog(), fn (array $query) => str_contains($query['query'], 'mainstay_media')));
        };

        $this->assertSame(1, $count(fn () => Mainstay::find(Pictured::class, locale: 'en')));
        $this->assertSame(1, $count(fn () => Mainstay::paginate(Pictured::class, perPage: 2, locale: 'en')));
    }

    #[Test]
    public function a_write_naming_an_image_the_library_does_not_hold_is_refused_on_the_field(): void
    {
        $trashed = $this->upload($this->png(20, 20, 10));
        Mainstay::media()->delete($trashed->id, overrideAccess: true);
        $message = fn (string $field, int $id) => ["The {$field} field names image {$id}, which is not in the media library or is in its trash."];

        $this->assertSame(['cover' => $message('cover', 999)], $this->refusal(fn () => $this->create(['cover' => 999])));
        $this->assertSame(['cover' => $message('cover', $trashed->id)], $this->refusal(fn () => $this->create(['cover' => $trashed])));
        $this->assertSame(['cover' => ['The cover field must be an integer.']], $this->refusal(fn () => $this->create(['cover' => 'abc'])));
        $this->assertSame(['cover' => ['The cover field format is invalid.']], $this->refusal(fn () => $this->create(['cover' => true])), 'Not image 1.');
        $this->assertSame(['cover' => ['The cover field is required.']], $this->refusal(fn () => $this->create(['title' => 'No cover'])));

        $live = $this->upload($this->png(20, 20, 5));
        $at = 'blocks.0.data.figures.1.data.image';
        $errors = $this->refusal(fn () => $this->create(['cover' => $live, 'blocks' => $this->figures($live, 998)]));
        $this->assertSame([$at], array_keys($errors));
        $this->assertStringContainsString('names image 998', $errors[$at][0]);

        $this->assertSame(0, DB::table('pictured')->count());
    }

    #[Test]
    public function the_library_is_written_only_with_the_override_until_there_is_a_user(): void
    {
        $file = $this->png(20, 20, 10);

        $this->assertThrows(fn () => Mainstay::media()->upload($file, alt: self::ALT), AuthorizationException::class, 'Writing to the media library needs a Mainstay user');
        $this->assertSame(0, DB::table('mainstay_media')->count());
        $this->assertSame([], Storage::disk('public')->allFiles());

        $media = $this->upload($file);

        $this->assertThrows(fn () => Mainstay::media()->update($media->id, alt: ['en' => 'Changed']), AuthorizationException::class);
        $this->assertThrows(fn () => Mainstay::media()->delete($media->id), AuthorizationException::class);
        $this->assertSame('Red beside blue', Mainstay::media()->find($media->id)->alt, 'Reading is open.');
        $this->assertSame('Changed', Mainstay::media()->update($media->id, alt: ['en' => 'Changed'], overrideAccess: true)->alt);
        $this->assertSame('Rood naast blauw', Mainstay::media()->find($media->id, locale: 'nl')->alt, 'The locales not given keep theirs.');
    }

    #[Test]
    public function a_trashed_image_reads_as_missing_and_keeps_its_place(): void
    {
        $cover = $this->upload($file = $this->png(20, 20, 10));
        $figure = $this->upload($this->png(20, 20, 5));
        $id = $this->create(['title' => 'Kept', 'cover' => $cover, 'blocks' => $this->figures($figure)])->id;

        Mainstay::media()->delete($cover->id, overrideAccess: true);
        Mainstay::media()->delete($figure->id, overrideAccess: true);

        $read = Mainstay::findById(Pictured::class, $id, locale: 'en');
        $this->assertTrue($read->cover->missing);
        $this->assertSame($cover->id, $read->cover->id);
        $this->assertNull($read->cover->url('card'));
        $this->assertNull($read->cover->width('card'));
        $this->assertSame('', (string) $read->cover->picture('card'));
        $this->assertTrue($read->blocks[0]->figures[0]->image->missing);
        $this->assertNull(Mainstay::media()->find($cover->id));

        /* A save of another field, and of the blocks as they were read: the
           field keeps what it held, the required cover included. */
        Mainstay::update(Pictured::class, $id, ['title' => 'Still kept'], locale: 'en', overrideAccess: true);
        Mainstay::update(Pictured::class, $id, ['cover' => $read->cover, 'blocks' => $read->blocks], locale: 'en', overrideAccess: true);

        $row = DB::table('pictured')->find($id);
        $this->assertSame($cover->id, (int) $row->cover);
        $this->assertSame($figure->id, json_decode($row->blocks, true)[0]['data']['figures'][0]['data']['image']);
        $this->artisan('mainstay:schema:check')->assertSuccessful();

        /* Moved to another field it is a new choice, and refused. */
        $this->assertArrayHasKey('cover', $this->refusal(fn () => Mainstay::update(Pictured::class, $id, ['cover' => $figure->id], locale: 'en', overrideAccess: true)));

        $this->assertSame(['file' => ["This image is already in the library, as image {$cover->id}, in its trash."]], $this->refusal(fn () => $this->upload($file)));
    }

    #[Test]
    public function the_drift_check_names_a_block_image_that_is_not_an_id(): void
    {
        $id = $this->create(['cover' => $this->upload($this->png(20, 20, 10))])->id;
        DB::table('pictured')->where('id', $id)->update(['blocks' => json_encode($this->figures('a picture'))]);

        $this->artisan('mainstay:schema:check')
            ->expectsOutputToContain("row {$id} holds what its field cannot read")
            ->assertFailed();
    }

    #[Test]
    public function reprocess_queues_one_job_per_image_and_writes_a_size_declared_since(): void
    {
        $live = $this->upload($this->png(60, 90, 30));
        $trashed = $this->upload($this->png(60, 90, 20));
        Mainstay::media()->delete($trashed->id, overrideAccess: true);

        $this->declare(Pictured::class, Poster::class);
        $this->assertFalse(Storage::disk('public')->exists("media/{$live->hash}-24x36-50-50.webp"));

        Queue::fake();
        $this->artisan('mainstay:media:reprocess')->expectsOutputToContain('Queued 1 image.')->assertSuccessful();
        Queue::assertPushed(Reprocess::class, 1);

        Queue::pushed(Reprocess::class)->each(fn (Reprocess $job) => $job->handle($this->app->make(Registry::class)));
        (new Reprocess($trashed->id))->handle($this->app->make(Registry::class));

        $this->assertSame([24, 36], $this->dimensions("media/{$live->hash}-24x36-50-50.webp"));
        $this->assertTrue(Storage::disk('public')->exists("media/{$live->hash}-24x36-50-50.avif"));
        $this->assertFalse(Storage::disk('public')->exists("media/{$trashed->hash}-24x36-50-50.webp"));
    }

    #[Test]
    public function two_uploads_of_the_same_bytes_at_once_leave_one_image(): void
    {
        config()->set('database.connections.beside', config('database.connections.testing'));

        /* The moment the upload has looked for its bytes and found none,
           another connection puts them in the library. */
        $race = function (string $file) use (&$beside): void {
            $hash = hash_file('sha256', $file);
            $beside = null;

            DB::listen(function ($query) use ($hash, &$beside) {
                if ($beside === null && str_contains($query->sql, 'mainstay_media') && in_array($hash, $query->bindings, true)) {
                    $beside = false;
                    $beside = DB::connection('beside')->table('mainstay_media')->insertGetId([
                        'hash' => $hash, 'type' => 'image/png', 'name' => 'beside.png', 'bytes' => 1, 'width' => 1, 'height' => 1,
                        'focal_x' => 50, 'focal_y' => 50, 'alt' => '{}', 'created_at' => '2026-10-06 00:00:00', 'updated_at' => '2026-10-06 00:00:00',
                    ]);
                }
            });
        };

        $race($file = $this->png(20, 20, 10));
        $errors = $this->refusal(fn () => $this->upload($file));
        $this->assertSame(['file' => ["This image is already in the library, as image {$beside}."]], $errors);
        $this->assertSame(1, DB::table('mainstay_media')->count());

        /* Inside a seeder's own transaction, which the refusal leaves usable
           -- on Postgres a failed insert outside a savepoint abandons it. A
           caller's transaction on SQLite holds the file, so the other
           connection cannot write until it ends. */
        $race($file = $this->png(20, 20, 10, mark: 1));
        DB::beginTransaction();

        try {
            if (DB::getDriverName() === 'sqlite') {
                $this->assertThrows(fn () => $this->upload($file), QueryException::class, 'database is locked');
            } else {
                $errors = $this->refusal(fn () => $this->upload($file));
                $this->assertSame(['file' => ["This image is already in the library, as image {$beside}."]], $errors);
                $this->assertSame(1, DB::table('sites')->count(), 'The caller\'s transaction goes on.');
            }
        } finally {
            DB::rollBack();
        }
    }

    #[Test]
    public function an_image_field_is_nullable_and_names_its_sizes(): void
    {
        $registry = new Registry;

        $this->assertThrows(fn () => $registry->fields(Framed::class), InvalidArgumentException::class, 'Framed::$cover is not nullable, and an image field holds null for no image chosen.');
        $this->assertThrows(fn () => $registry->fields(Unsized::class), InvalidArgumentException::class, 'An image field names the sizes it is printed at');
        $this->assertSame(['type' => ['integer', 'null']], $registry->fields(Pictured::class)['cover']->schema());
    }
}
