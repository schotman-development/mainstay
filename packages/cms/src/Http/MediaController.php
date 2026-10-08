<?php

namespace Mainstay\Http;

use Illuminate\Database\RecordNotFoundException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Mainstay\Mainstay;

/*
 | The media library's screens over the library: the grid and its trash, an
 | upload, and an image's page for its alt text and focal point. The library
 | asks Gate on every write, as it does for a seeder, so a refusal is its own.
 |
 | The grid is also the image field's picker: `?pick` draws it without the
 | shell, for the dialog an image field opens, and an upload from there
 | answers with the grid again rather than a page.
 */
class MediaController
{
    private const PER_PAGE = 24;

    public function __construct(private Mainstay $mainstay) {}

    public function index(Request $request): View
    {
        return $this->grid($request, trashed: false);
    }

    public function trashed(Request $request): View
    {
        return $this->grid($request, trashed: true);
    }

    /*
     | A file and its alt text in every locale. An alt left empty posts the
     | null the host's middleware makes of it, which is the empty string the
     | library takes as decorative.
     */
    public function store(Request $request): View|RedirectResponse
    {
        $file = $request->file('file');

        if (! $file instanceof UploadedFile || ! $file->isValid()) {
            $this->refuse($request, ['file' => $file instanceof UploadedFile ? $file->getErrorMessage() : 'Choose an image to upload.']);
        }

        try {
            $media = $this->mainstay->media()->upload($file, $this->alt($request));
        } catch (ValidationException $exception) {
            $this->refuse($request, $exception->errors());
        }

        return $request->has('pick')
            ? $this->grid($request, trashed: false, picked: $media->id)
            : redirect()->route('mainstay.media.edit', $media->id)->with('status', 'Uploaded.');
    }

    public function edit(int $id): View
    {
        $locales = array_keys($this->mainstay->locales());
        $media = $this->mainstay->media()->find($id) ?? throw $this->missing();

        return view('mainstay::media.edit', [
            'media' => $media,
            'alt' => array_combine($locales, array_map(fn (string $locale) => $this->mainstay->media()->find($id, $locale)?->alt, $locales)),
        ]);
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $focal = $request->input('focal');

        $this->found(fn () => $this->mainstay->media()->update($id, alt: $this->alt($request), focal: is_array($focal) ? array_values($focal) : null));

        return redirect()->route('mainstay.media.edit', $id)->with('status', 'Saved.');
    }

    public function trash(int $id): RedirectResponse
    {
        $this->found(fn () => $this->mainstay->media()->delete($id));

        return redirect()->route('mainstay.media')->with('status', 'Moved to the trash.');
    }

    public function restore(int $id): RedirectResponse
    {
        $this->found(fn () => $this->mainstay->media()->restore($id));

        return redirect()->route('mainstay.media.trash')->with('status', 'Restored.');
    }

    public function destroy(int $id): RedirectResponse
    {
        $this->found(fn () => $this->mainstay->media()->destroy($id));

        return redirect()->route('mainstay.media.trash')->with('status', 'Deleted for good.');
    }

    private function grid(Request $request, bool $trashed, ?int $picked = null): View
    {
        $page = $request->query('page');
        $images = $this->mainstay->media()->paginate(self::PER_PAGE, page: is_string($page) && ctype_digit($page) ? (int) $page : null, trashed: $trashed);

        return view($request->has('pick') ? 'mainstay::media.picker' : 'mainstay::media.index', [
            'images' => $images,
            'trashed' => $trashed,
            'picked' => $picked,
            'locales' => $this->mainstay->locales(),
        ]);
    }

    /* An image the library does not hold, in the trash or out of it, is the
       admin's not-found rather than the site's. */
    private function found(callable $write): void
    {
        try {
            $write();
        } catch (RecordNotFoundException) {
            throw $this->missing();
        }
    }

    private function missing(): HttpResponseException
    {
        return new HttpResponseException(response()->view('mainstay::missing', status: 404));
    }

    /* The alt text posted, by locale: the null an empty input posts as the
       empty string, and a locale not posted left out, for the library to
       refuse on an upload. */
    private function alt(Request $request): array
    {
        $posted = $request->input('alt');

        return array_map(fn (mixed $alt) => $alt ?? '', array_intersect_key(is_array($posted) ? $posted : [], $this->mainstay->locales()));
    }

    /* Back to the form with what was wrong, or, from the picker, the picker
       again with it. */
    private function refuse(Request $request, array $errors): never
    {
        if ($request->has('pick')) {
            throw new HttpResponseException(response()->view('mainstay::media.picker', [
                'images' => $this->mainstay->media()->paginate(self::PER_PAGE),
                'trashed' => false,
                'picked' => null,
                'locales' => $this->mainstay->locales(),
                'refused' => collect($errors)->flatten()->all(),
            ], 422));
        }

        throw ValidationException::withMessages($errors);
    }
}
