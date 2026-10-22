<?php

namespace Mainstay\Http;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\RecordNotFoundException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;
use Mainstay\Auth\Gate;
use Mainstay\Auth\User;
use Mainstay\Content\ContentType;
use Mainstay\Content\Document;
use Mainstay\Content\Draft;
use Mainstay\Content\Entry;
use Mainstay\Content\GlobalSet;
use Mainstay\Content\Taxonomy;
use Mainstay\Fields\Blocks;
use Mainstay\Fields\Content;
use Mainstay\Fields\Field;
use Mainstay\Fields\Image;
use Mainstay\Fields\Relation;
use Mainstay\Fields\Terms;
use Mainstay\Mainstay;
use Mainstay\Navigation;
use Mainstay\Ui\EntryList;
use ReflectionProperty;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use TypeError;

/*
 | The admin's screens over the query layer: a type's list and trash, an
 | entry's form in a locale, and the buttons over drafts, publishing, the
 | trash and the front page. Nothing here decides what may be written or what
 | is valid -- the layer does, as it does for a seeder -- so a refusal is the
 | layer's own, and a screen only leaves out what the user may not do.
 */
class EntryController
{
    private const PER_PAGE = 20;

    public function __construct(private Mainstay $mainstay) {}

    /* A type's list, or a global's form: a global is the one there is. */
    public function index(Request $request, string $type): View
    {
        $class = $this->type($type);

        return is_subclass_of($class, GlobalSet::class) ? $this->global($request, $class) : $this->list($request, $class, trashed: false);
    }

    public function trashed(Request $request, string $type): View
    {
        return $this->list($request, $this->type($type, entries: true), trashed: true);
    }

    public function create(Request $request, string $type): View
    {
        $class = $this->type($type, entries: true);

        return $this->form($request, $class, action: route('mainstay.entries.store', $type));
    }

    public function edit(Request $request, string $type, int $id): View
    {
        $class = $this->type($type, entries: true);
        [$shown, $draft, $elsewhere] = $this->read($request, $class, fn (string $locale) => [
            $this->mainstay->findById($class, $id, locale: $locale, depth: 1),
            is_subclass_of($class, Taxonomy::class) ? null : $this->mainstay->drafts()->of($class, $id, locale: $locale, depth: 1),
        ]);

        return $this->form($request, $class, $shown, $draft, $elsewhere, route('mainstay.entries.update', [$type, $id]), $id);
    }

    /* An entry not published yet, which only its draft holds. */
    public function draft(Request $request, string $type, int $draft): View|RedirectResponse
    {
        $class = $this->type($type, entries: true);
        [$shown, $held, $elsewhere] = $this->read($request, $class, function (string $locale) use ($draft) {
            $read = $this->mainstay->drafts()->find($draft, locale: $locale, depth: 1);

            return [null, $read];
        });
        $any = $held ?? $elsewhere;

        if ($any instanceof Draft && $any->entryId !== null) {
            return redirect()->route('mainstay.entries.edit', [$type, $any->entryId, 'locale' => $this->locale($request)]);
        }

        if (! ($any instanceof Draft ? $any->entry : $any) instanceof $class) {
            throw $this->missing();
        }

        return $this->form($request, $class, $shown, $held, $elsewhere, route('mainstay.drafts.update', [$type, $draft]));
    }

    /* The first save of a new entry or term, and every save of a global. */
    public function store(Request $request, string $type): RedirectResponse
    {
        return $this->save($request, $this->type($type));
    }

    public function update(Request $request, string $type, int $id): RedirectResponse
    {
        return $this->save($request, $this->type($type, entries: true), id: $id);
    }

    public function saveDraft(Request $request, string $type, int $draft): RedirectResponse
    {
        return $this->save($request, $this->type($type, entries: true), draft: $draft);
    }

    /*
     | The page the Content tab draws: the public page of the entry as the
     | form now holds it, unsaved -- Mainstay::preview() of what the form
     | changed, over the entry's draft -- drawn with nobody signed in and its
     | content printed for the editor. Only a type with a page has one. What
     | cannot be drawn answers why, as messages for the tab to show: a change
     | refused as its save would be, a field the page needs left empty, a
     | view that throws.
     */
    public function canvas(Request $request, string $type): Response
    {
        return $this->drawCanvas($request, $type);
    }

    public function entryCanvas(Request $request, string $type, int $id): Response
    {
        return $this->drawCanvas($request, $type, id: $id);
    }

    public function draftCanvas(Request $request, string $type, int $draft): Response
    {
        return $this->drawCanvas($request, $type, draft: $draft);
    }

    private function drawCanvas(Request $request, string $type, ?int $id = null, ?int $draft = null): Response
    {
        $class = $this->type($type, entries: true);

        if ($this->mainstay->route($class) === null) {
            throw $this->missing();
        }

        $locale = $this->locale($request);
        $fields = Form::fields($class, Gate::about($class)->allows('viewInternal', [$class, $class]));

        try {
            $changes = Form::changes($request, $class, $fields);

            /* A tag typed and not picked is left out: only a save makes
               one. */
            foreach ($fields as $name => $field) {
                if ($field instanceof Terms && is_array($changes[$name] ?? null)) {
                    $changes[$name] = array_values(array_filter($changes[$name], fn (mixed $chip) => ! is_array($chip)));
                }
            }

            $entry = $this->mainstay->preview($class, $id, $changes, draft: $draft, locale: $locale);
        } catch (ValidationException $exception) {
            return response()->json(['messages' => array_values(array_unique(Arr::flatten($exception->errors())))], 422);
        } catch (AuthorizationException|RecordNotFoundException|InvalidArgumentException $exception) {
            return response()->json(['messages' => [$exception->getMessage()]], $exception instanceof AuthorizationException ? 403 : 404);
        }

        /* As phase 8 has it, a draft leaves empty what its property cannot
           hold -- a required date -- and the entry it reads as lacks it. */
        $needed = array_filter($fields, fn (Field $field) => ! $field->internal && ! (new ReflectionProperty($entry, $field->name))->isInitialized($entry));

        if ($needed !== []) {
            return response()->json(['messages' => ['The page is drawn once it has '.implode(', ', array_map(fn (Field $field) => $field->label(), $needed)).'.']], 422);
        }

        $entry->content = ($entry->content ?? Document::empty())->editing();

        return $this->asVisitor($request, $locale, function () use ($entry) {
            try {
                return response(view($this->mainstay->template($entry), ['entry' => $entry])->render())->header('Cache-Control', 'no-store');
            } catch (Throwable $exception) {
                report($exception);

                return response()->json(['messages' => [$exception->getMessage() ?: 'The page stopped drawing with '.class_basename($exception).'.']], 500);
            }
        });
    }

    /*
     | `$draw` with nobody signed in, as the public page is drawn: Mainstay's
     | Gate reads as a visitor's, and the default guard is one with no user,
     | so a host view's `@auth`, `auth()` and `@can` see a guest rather than
     | the account the admin's session made the default. The container's
     | resolved guard is forgotten on the way in and out, so the rest of the
     | request keeps its own. In the form's locale.
     */
    private function asVisitor(Request $request, string $locale, callable $draw): mixed
    {
        $user = $request->attributes->get(Gate::USER);
        $guard = Auth::getDefaultDriver();
        $language = App::getLocale();

        $request->attributes->remove(Gate::USER);
        Auth::shouldUse(Gate::VISITOR);
        app()->forgetInstance('auth.driver');
        App::setLocale($locale);

        try {
            return $draw();
        } finally {
            $request->attributes->set(Gate::USER, $user);
            Auth::shouldUse($guard);
            app()->forgetInstance('auth.driver');
            App::setLocale($language);
        }
    }

    /*
     | A site block of the content, for the Content tab: posted as the
     | document holds it, `block`, once it is selected, or as its settings
     | post it after a field changed -- `id`, `type` and `data`, each field
     | through its fromForm() -- with `ancestors`, the attrs of the blocks it
     | sits inside, outermost first, for one inside another. Checked as the
     | canvas checks the content, by Mainstay::preview() as a draft save
     | would, nothing written. Answered with the block as the document is to
     | hold it, its HTML drawn for the editor as the canvas draws it, and,
     | when it is selected, its settings: every field, drawn by the component
     | Details draws its kind with, showing the `errors` posted with it -- a
     | refused save's, by where they are in it. A check that refuses it
     | answers why, with the block as converted and its settings showing each
     | message at its field, what was refused left empty, so it can be put
     | right there; anything else refused answers why alone.
     */
    public function block(Request $request, string $type): JsonResponse
    {
        return $this->drawBlock($request, $type);
    }

    public function entryBlock(Request $request, string $type, int $id): JsonResponse
    {
        return $this->drawBlock($request, $type, id: $id);
    }

    public function draftBlock(Request $request, string $type, int $draft): JsonResponse
    {
        return $this->drawBlock($request, $type, draft: $draft);
    }

    private function drawBlock(Request $request, string $type, ?int $id = null, ?int $draft = null): JsonResponse
    {
        $class = $this->type($type, entries: true);
        $content = $this->mainstay->fields($class)['content'];

        if ($this->mainstay->route($class) === null) {
            throw $this->missing();
        }

        $locale = $this->locale($request);
        $drawn = is_string($request->input('block'));
        $ancestors = is_string($request->input('ancestors')) ? json_decode($request->input('ancestors'), true) : [];
        $ancestors = is_array($ancestors) && array_is_list($ancestors) ? $ancestors : [];
        $list = $content->listing();

        foreach ($ancestors as $ancestor) {
            $list = ($held = $list?->block($ancestor)) === null ? null : $this->mainstay->innerBlocks($held);
        }

        $item = $drawn ? json_decode($request->input('block'), true) : ['id' => $request->input('id'), 'type' => $request->input('type'), 'data' => (array) $request->input('data', [])];

        if (($block = $list?->block($item)) === null) {
            return response()->json(['messages' => ['The content holds no block of the type '.json_encode(is_array($item) ? $item['type'] ?? null : null).' here.']], 422);
        }

        $item = $drawn ? $item : $list->itemFromForm($item);
        $node = ['type' => 'block', 'attrs' => $item];

        foreach (array_reverse($ancestors) as $ancestor) {
            $node = ['type' => 'block', 'attrs' => $ancestor, 'content' => [$node]];
        }

        try {
            $entry = $this->mainstay->preview($class, $id, ['content' => ['type' => 'doc', 'content' => [$node]]], draft: $draft, locale: $locale);
        } catch (ValidationException $exception) {
            $prefix = 'content.content.0.'.str_repeat('content.0.', count($ancestors)).'attrs.';
            $errors = Form::blockErrors($block, $exception->errors(), $prefix);

            $shown = $item;
            Arr::forget($shown, array_keys($errors));

            try {
                $read = $drawn ? $list->readItem($shown, 'content') : null;
            } catch (InvalidArgumentException|TypeError) {
                $read = null;
            }

            return response()->json([
                'messages' => array_values(array_unique([...Arr::flatten($errors), ...Arr::flatten(array_filter($exception->errors(), fn (string $key) => ! str_starts_with($key, $prefix), ARRAY_FILTER_USE_KEY))])),
                'block' => $item,
                'form' => $read === null ? null : view('mainstay::block', ['block' => $read, 'messages' => $errors])->render(),
            ], 422);
        } catch (AuthorizationException|RecordNotFoundException|InvalidArgumentException $exception) {
            return response()->json(['messages' => [$exception->getMessage()]], $exception instanceof AuthorizationException ? 403 : 404);
        }

        $read = $entry->content->blocks()[0];
        $attrs = $entry->content->toArray()['content'][0];

        foreach ($ancestors as $ancestor) {
            [$read, $attrs] = [$read->inner()[0], $attrs['content'][0]];
        }

        try {
            $html = $this->asVisitor($request, $locale, fn () => $this->mainstay->marked(fn () => $read->toHtml()));
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(['messages' => [$exception->getMessage() ?: 'The block stopped drawing with '.class_basename($exception).'.']], 500);
        }

        $errors = json_decode((string) $request->input('errors'), true);
        $errors = array_map(fn (array $messages) => array_values(array_filter($messages, is_string(...))), array_filter(is_array($errors) ? $errors : [], is_array(...)));

        return response()->json([
            'block' => $attrs['attrs'],
            'html' => $html,
            'form' => $drawn ? view('mainstay::block', ['block' => $read, 'messages' => $errors])->render() : null,
        ]);
    }

    /* Throws a draft away, unless someone else saved it after this form drew
       it: what would go is then not what the editor saw. */
    public function discard(Request $request, string $type, int $draft): RedirectResponse
    {
        $class = $this->type($type);
        $current = $this->current($request, $class, draft: $draft);

        if ($current === null) {
            throw $this->missing();
        }

        if ($this->moved($request, $current)) {
            return back()->withErrors(['draft' => 'Someone else saved this draft after you opened it. Look it over, then discard it.']);
        }

        $this->mainstay->drafts()->discard($draft);

        $back = match (true) {
            is_subclass_of($class, GlobalSet::class) => route('mainstay.entries', $type),
            $current->entryId !== null => route('mainstay.entries.edit', [$type, $current->entryId]),
            default => route('mainstay.entries', $type),
        };

        return redirect()->to($this->localized($request, $back))->with('status', 'Draft discarded.');
    }

    public function trash(Request $request, string $type, int $id): RedirectResponse
    {
        $class = $this->type($type, entries: true);
        $this->mainstay->delete($class, $id);

        return redirect()->to($this->localized($request, route('mainstay.entries', $type)))->with('status', 'Moved to the trash.');
    }

    /* The list's selection, every row of it an entry -- a draft not published
       has nothing in the trash to go to. */
    public function trashMany(Request $request, string $type): RedirectResponse
    {
        $class = $this->type($type, entries: true);
        $ids = array_unique(array_map('intval', array_filter((array) $request->input('rows', []), fn (mixed $row) => is_string($row) && ctype_digit($row))));
        $moved = 0;
        $kept = [];

        foreach ($ids as $id) {
            try {
                $this->mainstay->delete($class, $id);
                $moved++;
            } catch (AuthorizationException|ValidationException $exception) {
                $kept[] = $exception instanceof ValidationException ? collect($exception->errors())->flatten()->first() : $exception->getMessage();
            }
        }

        return back()->with('status', $moved === 1 ? 'Moved 1 entry to the trash.' : "Moved {$moved} entries to the trash.")->withErrors(array_unique($kept));
    }

    public function restore(Request $request, string $type, int $id): RedirectResponse
    {
        $paths = $this->mainstay->restore($this->type($type, entries: true), $id);

        return back()->with('status', $paths === [] ? 'Restored.' : 'Restored at '.implode(', ', array_unique($paths)).'.');
    }

    public function destroy(Request $request, string $type, int $id): RedirectResponse
    {
        $this->mainstay->destroy($this->type($type, entries: true), $id);

        return back()->with('status', 'Deleted for good.');
    }

    /* Gives an entry, or one not published yet, to another account at once,
       as the front page is set: its own form, beside the entry's. */
    public function assign(Request $request, string $type, int $id): RedirectResponse
    {
        $this->mainstay->assign($this->type($type, entries: true), $id, owner: (int) $request->input('owner'));

        return back()->with('status', 'The author is changed.');
    }

    public function assignDraft(Request $request, string $type, int $draft): RedirectResponse
    {
        $this->type($type, entries: true);
        $this->mainstay->drafts()->assign($draft, owner: (int) $request->input('owner'));

        return back()->with('status', 'The author is changed.');
    }

    public function front(Request $request, string $type, int $id): RedirectResponse
    {
        $this->mainstay->setFrontPage($this->type($type, entries: true), $id);

        return back()->with('status', 'This is the front page now.');
    }

    /*
     | An entry's versions, or a global's, newest first: the live one, and
     | every one a write replaced and filed, each with when and who
     | published it -- an account's name, "a script" for none, "a deleted
     | account" for an id no account has -- and Restore, which makes one the
     | draft. Terms have none.
     */
    public function history(Request $request, string $type, ?int $id = null): View
    {
        $class = $this->type($type);
        $global = is_subclass_of($class, GlobalSet::class);

        if ($global === ($id !== null) || is_subclass_of($class, Taxonomy::class)) {
            throw $this->missing();
        }

        $live = null;

        foreach ($this->order($this->locale($request)) as $locale) {
            $live ??= $global ? $this->mainstay->global($class, locale: $locale, depth: 0) : $this->mainstay->findById($class, $id, locale: $locale, depth: 0);
        }

        try {
            $revisions = $this->mainstay->revisions()->of($class, $id);
        } catch (RecordNotFoundException) {
            throw $this->missing();
        }

        $names = User::query()->whereIn('id', array_filter([$live?->publishedBy, ...$revisions->pluck('publishedBy')]))->pluck('name', 'id');

        if (! $global && $live === null) {
            throw $this->missing();
        }

        return view('mainstay::history', [
            'class' => $class,
            'id' => $id,
            /* When the live version went live: when the one before it was
               replaced, or the entry was first written. Not its last write,
               which a save changing nothing moves too. */
            'since' => $revisions->first()?->createdAt ?? $live?->createdAt,
            'handle' => $class::handle(),
            'label' => Navigation::label($class),
            'title' => $global ? Navigation::label($class) : ($live->title ?: 'Untitled'),
            'form' => $this->localized($request, $global ? route('mainstay.entries', $type) : route('mainstay.entries.edit', [$type, $id])),
            'live' => $live,
            'revisions' => $revisions,
            'who' => fn (?int $user) => $user === null ? 'a script' : ($names[$user] ?? 'a deleted account'),
            'restores' => Gate::about($class)->allows('update', $global || $live === null ? [$class, $class] : [$live]),
        ]);
    }

    /*
     | A revision made the draft, and the form opened on it, saying what it
     | could not bring back: a field the type no longer declares, a locale
     | no longer configured, a value its field now refuses. Only a revision
     | of the entry -- or the global -- the address names; any other is not
     | found here.
     */
    public function restoreRevision(Request $request, string $type, int $id, int $revision): RedirectResponse
    {
        return $this->revert($request, $type, $id, $revision);
    }

    public function restoreGlobalRevision(Request $request, string $type, int $revision): RedirectResponse
    {
        return $this->revert($request, $type, null, $revision);
    }

    private function revert(Request $request, string $type, ?int $id, int $revision): RedirectResponse
    {
        $class = $this->type($type);
        $global = is_subclass_of($class, GlobalSet::class);

        if ($global === ($id !== null) || is_subclass_of($class, Taxonomy::class)) {
            throw $this->missing();
        }

        try {
            if (! $this->mainstay->revisions()->of($class, $id)->contains('id', $revision)) {
                throw $this->missing();
            }

            $draft = $this->mainstay->revisions()->restore($revision);
        } catch (RecordNotFoundException) {
            throw $this->missing();
        }

        if ($draft === null) {
            return redirect()->to($this->localized($request, $global ? route('mainstay.globals.history', $type) : route('mainstay.entries.history', [$type, $id])))
                ->with('status', 'Nothing to restore: that version is what is live.');
        }

        $unrestored = [];

        foreach ($draft->unrestored['fields'] ?? [] as $name => $why) {
            $unrestored[] = "{$name}: {$why}";
        }

        foreach ($draft->unrestored['locales'] ?? [] as $locale => $fields) {
            foreach ($fields as $name => $why) {
                $unrestored[] = strtoupper($locale)." {$name}: {$why}";
            }
        }

        return redirect()->to($this->localized($request, $global ? route('mainstay.entries', $type) : route('mainstay.entries.edit', [$type, $id])))
            ->with('status', 'That version is the draft now. Look it over, then publish it.')
            ->withErrors($unrestored === [] ? [] : ['revision' => ['Not brought back -- '.implode(' ', $unrestored)]]);
    }

    /*
     | What a relation's search offers: the entries of the types it points at
     | whose titles hold the query, in the form's locale, as the rows the
     | field adds. Drawn as HTML for the field to put under its search.
     |
     | ponytail: the target types are read into memory and matched by title,
     | as the list is; past a few thousand entries this wants a `like` the
     | layer does not have yet.
     */
    public function relations(Request $request, string $type, string $field): View
    {
        $class = $this->type($type);
        /* A block's, in its sidebar, by the block's handle: `hero.story`. */
        [$block, $name] = str_contains($field, '.') ? explode('.', $field, 2) : [null, $field];
        $fields = $block === null
            ? Form::fields($class, Gate::about($class)->allows('viewInternal', [$class, $class]))
            : (isset(Form::blocks($class)[$block]) ? $this->mainstay->fields(Form::blocks($class)[$block]) : []);
        $relation = $fields[$name] ?? null;

        if (! $relation instanceof Relation || $relation instanceof Terms) {
            throw $this->missing();
        }

        $query = is_string($request->query('q')) ? trim($request->query('q')) : '';
        $found = [];

        /* A type the reader may not read is not searched, as it is not
           shown. */
        foreach ($query === '' ? [] : $relation->to as $target) {
            foreach (Form::readable(fn () => $this->mainstay->find($target, locale: $this->locale($request), depth: 0)) ?? [] as $entry) {
                if (count($found) < 20 && mb_stripos($entry->title, $query) !== false) {
                    $found[] = [
                        'value' => $relation->several() ? $target::handle().':'.$entry->id : (string) $entry->id,
                        'title' => $entry->title,
                        'type' => $relation->several() ? Navigation::label($target, plural: false) : null,
                    ];
                }
            }
        }

        return view('mainstay::relations', ['found' => $found, 'query' => $query]);
    }

    /*
     | A save from a form: a term written live, anything else saved as its
     | draft and, for Publish, put live in the same request. A publish the
     | layer refuses -- a required field empty, a path taken -- leaves the
     | draft saved and says why; one asked of a draft someone else saved since
     | this form drew it is refused, the save going through as always, so
     | nobody puts live what they have not seen.
     */
    private function save(Request $request, string $class, ?int $id = null, ?int $draft = null): RedirectResponse
    {
        $locale = $this->locale($request);
        $handle = $class::handle();
        $global = is_subclass_of($class, GlobalSet::class);

        /* The browser's copy of a form left unsaved, posted back: the form
           drawn from it as a refused save draws what was posted, saving
           nothing, with the fingerprints and the draft's time it was made
           with, so saving it changes only what it changed. */
        if ($request->input('intent') === 'restore') {
            return redirect()->to($this->localized($request, match (true) {
                $global => route('mainstay.entries', $handle),
                $id !== null => route('mainstay.entries.edit', [$handle, $id]),
                $draft !== null => route('mainstay.drafts.edit', [$handle, $draft]),
                default => route('mainstay.entries.create', $handle),
            }))->withInput($request->except('_token', 'intent'))->with('restored', true);
        }

        $data = Form::changes($request, $class, Form::fields($class, Gate::about($class)->allows('viewInternal', [$class, $class])));

        if (is_subclass_of($class, Taxonomy::class)) {
            $term = DB::transaction(fn () => $id === null
                ? $this->mainstay->create($class, Form::terms($class, $data), locale: $locale)
                : $this->mainstay->update($class, $id, Form::terms($class, $data), locale: $locale));
            $request->session()->flash(Form::SAVED, true);

            return redirect()->to($this->localized($request, route('mainstay.entries.edit', [$handle, $term->id])))->with('status', 'Saved.');
        }

        $before = $this->current($request, $class, $id, $draft);
        /* Tags typed are created with the save, and gone with it if it is
           refused. */
        $saved = DB::transaction(fn () => $this->mainstay->drafts()->save($class, Form::terms($class, $data), entry: $id, draft: $draft, locale: $locale));
        $request->session()->flash(Form::SAVED, true);

        $here = $this->localized($request, match (true) {
            $global => route('mainstay.entries', $handle),
            $id !== null => route('mainstay.entries.edit', [$handle, $id]),
            $saved !== null => route('mainstay.drafts.edit', [$handle, $saved->id]),
            $draft !== null => route('mainstay.entries', $handle),
            default => route('mainstay.entries.create', $handle),
        });

        if ($request->input('intent') !== 'publish') {
            return redirect()->to($here)->with('status', $saved === null ? 'Nothing to save: this is what is live.' : 'Draft saved.');
        }

        if ($saved === null) {
            return redirect()->to($here)->with('status', 'Nothing to publish: this is what is live.');
        }

        if ($before !== null && $this->moved($request, $before)) {
            return redirect()->to($here)->withErrors(['draft' => 'Someone else saved this draft after you opened it. Your changes are saved; look it over, then publish.']);
        }

        try {
            $published = $this->mainstay->drafts()->publish($saved->id);
        } catch (ValidationException $exception) {
            return redirect()->to($here)->withErrors($exception->errors());
        }

        return redirect()->to($this->localized($request, $global ? route('mainstay.entries', $handle) : route('mainstay.entries.edit', [$handle, $published->id])))->with('status', 'Published.');
    }

    /*
     | The list of a type, or its trash: every entry read in every locale --
     | shown in the chosen one, or, marked as missing there, in the first it
     | has -- and every draft, shaped by EntryList as the query string asks.
     |
     | ponytail: the whole type is read into memory to shape it, which holds
     | to a few thousand entries; past that it is paginate() and a `like`
     | operator the layer does not have yet.
     */
    private function list(Request $request, string $class, bool $trashed): View
    {
        $locale = $this->locale($request);
        $handle = $class::handle();
        $front = $this->mainstay->frontPage();
        $gate = Gate::about($class);
        $drafts = ! $trashed && ! is_subclass_of($class, Taxonomy::class);
        $rows = [];

        foreach ($this->order($locale) as $code) {
            foreach ($this->mainstay->find($class, locale: $code, depth: 0, trashed: $trashed) as $entry) {
                $rows[(string) $entry->id] ??= [
                    'title' => $entry->title ?? '',
                    'owner' => $entry->ownerId,
                    'category' => '',
                    'status' => $trashed ? 'Trashed' : 'Published',
                    'modified' => $entry->updatedAt?->toIso8601String(),
                    'path' => (string) $entry->id,
                    'edit' => route('mainstay.entries.edit', [$handle, $entry->id]),
                    'view' => $trashed ? null : $entry->url(),
                    'id' => $entry->id,
                    'draft' => null,
                    'missing' => $code === $locale ? null : $code,
                    'front' => $front === [$class, $entry->id],
                    'trashes' => ! $trashed && $gate->allows('delete', [$entry]),
                    'restores' => $trashed && $gate->allows('restore', [$entry]),
                    'destroys' => $trashed && $gate->allows('forceDelete', [$entry]),
                ];
            }

            foreach ($drafts ? $this->mainstay->drafts()->all($class, locale: $code, depth: 0) : [] as $draft) {
                if ($draft->entryId !== null) {
                    if (isset($rows[(string) $draft->entryId]) && $rows[(string) $draft->entryId]['draft'] === null) {
                        $rows[(string) $draft->entryId] = [...$rows[(string) $draft->entryId], 'title' => $draft->entry->title ?? '', 'status' => 'Changed', 'draft' => $draft->id, 'modified' => $draft->updatedAt->toIso8601String()];
                    }

                    continue;
                }

                $rows["draft:{$draft->id}"] ??= [
                    'title' => $draft->entry->title ?? '',
                    'owner' => $draft->entry->ownerId,
                    'category' => '',
                    'status' => 'Draft',
                    'modified' => $draft->updatedAt->toIso8601String(),
                    'path' => "draft:{$draft->id}",
                    'edit' => route('mainstay.drafts.edit', [$handle, $draft->id]),
                    'view' => null,
                    'id' => null,
                    'draft' => $draft->id,
                    'missing' => $code === $locale ? null : $code,
                    'front' => false,
                    'trashes' => false,
                    'restores' => false,
                    'destroys' => false,
                ];
            }
        }

        $owners = User::query()->whereIn('id', array_filter(array_column($rows, 'owner')))->pluck('name', 'id');

        foreach ($rows as $key => $row) {
            $rows[$key]['author'] = $owners[$row['owner']] ?? '—';
            $rows[$key]['edit'] = $this->localized($request, $row['edit']);
        }

        /* A key given twice in the query string is an array, and asks for
           nothing a list can do. */
        $asked = fn (string $key, string $otherwise) => is_string($request->query($key)) ? $request->query($key) : $otherwise;
        $view = [
            'query' => $asked('query', ''),
            'status' => $asked('status', 'All'),
            'field' => in_array($asked('field', ''), ['title', 'status', 'author', 'modified'], true) ? $asked('field', '') : 'modified',
            'direction' => $asked('direction', '') === 'asc' ? 'asc' : 'desc',
            'page' => (int) $asked('page', '1'),
            'perPage' => self::PER_PAGE,
        ];

        return view('mainstay::entries', [
            'class' => $class,
            'label' => Navigation::label($class),
            'trashed' => $trashed,
            'drafts' => $drafts,
            'locale' => $locale,
            'shaped' => EntryList::shape(array_values($rows), $view),
            'empty' => $rows === [],
            'view' => $view,
        ]);
    }

    private function global(Request $request, string $class): View
    {
        [$shown, $draft, $elsewhere] = $this->read($request, $class, fn (string $locale) => [
            $this->mainstay->global($class, locale: $locale, depth: 1),
            $this->mainstay->drafts()->of($class, locale: $locale, depth: 1),
        ]);

        return $this->form($request, $class, $shown, $draft, $elsewhere, route('mainstay.entries.store', $class::handle()));
    }

    /*
     | What a form shows in the request's locale: the live entry there and its
     | draft, from `$read`, and where neither is, the first other locale
     | either is in, for the shared fields -- an entry with no row in the
     | locale opens with those, saying it has no version there yet.
     |
     | @return array{0: ?ContentType, 1: ?Draft, 2: ContentType|Draft|null}
     */
    private function read(Request $request, string $class, callable $read): array
    {
        $locale = $this->locale($request);
        [$shown, $draft] = $read($locale);

        if ($shown !== null || $draft !== null) {
            return [$shown, $draft, null];
        }

        foreach ($this->order($locale) as $other) {
            [$live, $drafted] = $other === $locale ? [null, null] : $read($other);

            if ($drafted !== null || $live !== null) {
                return [null, null, $drafted ?? $live];
            }
        }

        return [null, null, null];
    }

    /*
     | The form of an entry, a term, a global or a new one. A field is drawn
     | by its type's component, with its value as it reads in the locale --
     | the draft's where there is one, a shared field from another locale
     | where this one has no row, a declared default where nothing holds one
     | -- and, for anything already saved, the fingerprint a save leaves an
     | unchanged field out by.
     */
    private function form(Request $request, string $class, ?ContentType $shown = null, ?Draft $draft = null, ContentType|Draft|null $elsewhere = null, string $action = '', ?int $id = null): View
    {
        $locale = $this->locale($request);
        $gate = Gate::about($class);
        $global = is_subclass_of($class, GlobalSet::class);
        $term = is_subclass_of($class, Taxonomy::class);
        $fields = Form::fields($class, $gate->allows('viewInternal', [$class, $class]));
        $other = $elsewhere instanceof Draft ? $elsewhere->entry : $elsewhere;
        $entry = $draft?->entry ?? $shown;
        $saved = $entry !== null || $other !== null;

        if (! $global && $id !== null && ! $saved) {
            throw $this->missing();
        }

        /* A host's field type is drawn by writing the file its component()
           names, so one without it is named before anything renders. */
        foreach ($fields as $field) {
            if (! view()->exists($view = Str::replaceFirst('::', '::components.', $field->component()))) {
                throw new InvalidArgumentException("{$class}::\${$field->name} is drawn by the component {$field->component()}, and there is no view {$view}. Write components/".str_replace('.', '/', Str::after($field->component(), '::')).'.blade.php among the views registered as '.Str::before($field->component(), '::').'.');
            }
        }

        $values = $seen = [];

        foreach ($fields as $name => $field) {
            $source = $entry ?? ($field->localized ? null : $other);
            $values[$name] = $source === null ? ($field->hasDefault ? $field->default : null) : ($source->{$name} ?? null);

            if ($saved && ($print = Form::fingerprint($field, $values[$name])) !== null) {
                $seen[$name] = $print;
            }
        }

        $templates = $global ? [] : $this->mainstay->templates($class);
        $template = ($entry ?? $other) instanceof Entry ? $this->mainstay->template($entry ?? $other) : $templates[0] ?? null;

        if ($saved && $template !== null) {
            $seen['template'] = sha1($template);
        }

        $routed = ! $global && $this->mainstay->route($class) !== null;
        $live = $id !== null && $shown instanceof Entry;
        $title = $global ? Navigation::label($class) : ((($entry ?? $other)?->title ?? '') ?: 'Untitled');
        $current = $draft ?? ($elsewhere instanceof Draft ? $elsewhere : null);

        return view('mainstay::form', [
            'class' => $class,
            'handle' => $class::handle(),
            'label' => Navigation::label($class),
            'title' => $title,
            'global' => $global,
            'term' => $term,
            'id' => $id,
            'locale' => $locale,
            'missing' => $saved && $entry === null,
            'fields' => $fields,
            'values' => $values,
            'seen' => $seen,
            'templates' => $templates,
            'template' => $template,
            'draft' => $current,
            'live' => $live,
            'published' => $shown !== null,
            'slug' => $routed && $id === null ? $this->slug($class) : null,
            'addresses' => $live ? $this->addresses($class, $id) : [],
            'front' => $live && $this->mainstay->frontPage() === [$class, $id],
            'routed' => $routed,
            'owner' => match (true) {
                ! $saved => Gate::user()?->name,
                ($entry ?? $other)->ownerId === null => null,
                default => User::query()->whereKey(($entry ?? $other)->ownerId)->value('name'),
            },
            'owner_id' => ($entry ?? $other)?->ownerId,
            ...$this->authors($class, $entry ?? $other, $id, $current),
            'action' => $this->localized($request, $action),
            /* Where the Content tab draws the page from, for a type with
               one, and the blocks it adds, by handle. */
            'canvas' => $routed ? $this->localized($request, match (true) {
                $id !== null => route('mainstay.canvas.entry', [$class::handle(), $id]),
                $current !== null => route('mainstay.canvas.draft', [$class::handle(), $current->id]),
                default => route('mainstay.canvas.create', $class::handle()),
            }) : null,
            'block' => $routed ? $this->localized($request, match (true) {
                $id !== null => route('mainstay.block.entry', [$class::handle(), $id]),
                $current !== null => route('mainstay.block.draft', [$class::handle(), $current->id]),
                default => route('mainstay.block.create', $class::handle()),
            }) : null,
            /* Every block the content holds, by handle, with the blocks it
               holds inside it; and the ones at its top level. */
            'blocks' => collect(Form::blocks($class))->map(fn (string $block) => [
                'label' => Form::label($block),
                'inner' => array_map(fn (string $inner) => $inner::handle(), $this->mainstay->innerBlocks($block)->of ?? []),
            ])->all(),
            'top' => array_map(fn (string $block) => $block::handle(), ($fields['content'] ?? null) instanceof Content ? $fields['content']->blocks() : []),
            /* The library's picker and image dialog, for a type whose blocks
               hold an image: a block's settings are drawn after the page, too
               late to push them. */
            'pictures' => collect(Form::blocks($class))->contains(fn (string $block) => collect($this->mainstay->fields($block))->contains(fn (Field $field) => $field instanceof Image)),
            'invalid' => ($fields['content'] ?? null) instanceof Content ? $this->invalid($fields['content'], $values['content'] ?? null) : [],
            /* The browser's copy of the form left unsaved is kept under this:
               the account, the screen and its locale. */
            'copy' => json_encode([Gate::user()?->getKey(), $request->getPathInfo(), $locale], JSON_UNESCAPED_SLASHES),
            'publishes' => ! $term && $gate->allows('publish', $entry instanceof Entry ? [$entry] : [$class, $class]),
            'deletes' => $live && $gate->allows('delete', [$shown]),
        ]);
    }

    /*
     | After a save refused for a site block's field, each such block's
     | errors by its id, read from the content as it was posted -- or, for a
     | refused publish, as its draft saved it -- the blocks inside another
     | too: its name and its messages by where they are in it,
     | `data.headline`. A block posted without an id is the raw content's to
     | show alone.
     |
     | @return array<string, array{label: string, messages: array<string, list<string>>}>
     */
    private function invalid(Content $content, mixed $saved): array
    {
        $posted = old('content') !== null ? json_decode((string) old('content'), true) : ($saved instanceof Document ? $saved->toArray() : null);
        $errors = session('errors')?->getBag('default')->getMessages() ?? [];
        $invalid = [];

        $walk = function (mixed $nodes, ?Blocks $list, string $at) use (&$walk, &$invalid, $errors) {
            foreach (is_array($nodes) ? $nodes : [] as $index => $node) {
                $item = is_array($node) ? $node['attrs'] ?? null : null;

                if (($block = $list?->block($item)) === null) {
                    continue;
                }

                $messages = Form::blockErrors($block, $errors, "{$at}.{$index}.attrs.");

                if ($messages !== [] && is_string($id = $item['id'] ?? null) && $id !== '') {
                    $invalid[$id] = ['label' => Form::label($block), 'messages' => $messages];
                }

                $walk($node['content'] ?? null, $this->mainstay->innerBlocks($block), "{$at}.{$index}.content");
            }
        };

        $walk($posted['content'] ?? null, $content->listing(), 'content.content');

        return $invalid;
    }

    /*
     | The Author menu, for whoever may give this entry away: every account
     | Gate would let make one of the type, as the sidebar asks, and where
     | it posts -- the entry, or the draft of one not published yet. A term's
     | owner is nothing anyone asks about, so it has no menu.
     |
     | @return array{authors: ?array<int, string>, assigns: ?string}
     */
    private function authors(string $class, ?ContentType $entry, ?int $id, ?Draft $draft): array
    {
        if (! $entry instanceof Entry || is_subclass_of($class, Taxonomy::class) || Gate::about($class)->denies('assign', [$entry])) {
            return ['authors' => null, 'assigns' => null];
        }

        return [
            'authors' => User::query()->with('role')->orderBy('name')->get()
                ->filter(fn (User $user) => Gate::about($class)->forUser($user)->allows('create', [$class, $class]))
                ->pluck('name', 'id')->all(),
            'assigns' => $id !== null ? route('mainstay.entries.assign', [$class::handle(), $id]) : route('mainstay.drafts.assign', [$class::handle(), $draft->id]),
        ];
    }

    /* The field the slug follows the title into: the Text field the type's
       route ends in, in the first locale's pattern. */
    private function slug(string $class): ?string
    {
        return Form::slug($class);
    }

    /* Where a live entry is read, by locale. */
    private function addresses(string $class, int $id): array
    {
        $addresses = [];

        foreach (array_keys($this->mainstay->locales()) as $locale) {
            if (($url = $this->mainstay->findById($class, $id, locale: $locale, depth: 0)?->url()) !== null) {
                $addresses[$locale] = $url;
            }
        }

        return $addresses;
    }

    /*
     | The draft the form was drawn from, read in any locale it has: a new
     | entry's by its own id, an entry's or a global's by what it changes.
     */
    private function current(Request $request, string $class, ?int $id = null, ?int $draft = null): ?Draft
    {
        if (is_subclass_of($class, Taxonomy::class) || ($id === null && $draft === null && ! is_subclass_of($class, GlobalSet::class))) {
            return null;
        }

        foreach ($this->order($this->locale($request)) as $locale) {
            $read = $draft !== null
                ? $this->mainstay->drafts()->find($draft, locale: $locale, depth: 0)
                : $this->mainstay->drafts()->of($class, $id, locale: $locale, depth: 0);

            if ($read !== null) {
                return $read;
            }
        }

        return null;
    }

    /* Whether the draft was saved by anyone after this form drew it. */
    private function moved(Request $request, Draft $draft): bool
    {
        return (string) $request->input('_draft_at') !== $draft->updatedAt->toIso8601String();
    }

    /*
     | A registered type by the handle in the path, or not found. `$entries`
     | for a screen only entries and terms have. Only for whoever may write
     | to it at all, as the sidebar offers it.
     */
    private function type(string $handle, bool $entries = false): string
    {
        $class = $this->mainstay->registered()[$handle] ?? throw $this->missing();

        if ($entries && ! is_subclass_of($class, Entry::class)) {
            throw $this->missing();
        }

        if (! Navigation::writes($class)) {
            throw new AuthorizationException('You may not change '.Navigation::label($class).'.');
        }

        return $class;
    }

    /* Not found, drawn in the shell as any admin path nothing answers is. */
    private function missing(): HttpResponseException
    {
        return new HttpResponseException(response()->view('mainstay::missing', status: 404));
    }

    private function locale(Request $request): string
    {
        return Form::locale($request);
    }

    /* The locales to look in, the one asked for first. */
    private function order(string $locale): array
    {
        return [$locale, ...array_diff(array_keys($this->mainstay->locales()), [$locale])];
    }

    /* A link that keeps the screen's locale, unless it is the default. */
    private function localized(Request $request, string $url): string
    {
        $locale = $this->locale($request);

        return $locale === array_key_first($this->mainstay->locales()) ? $url : $url.(str_contains($url, '?') ? '&' : '?').'locale='.$locale;
    }
}
