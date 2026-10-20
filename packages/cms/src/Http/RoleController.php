<?php

namespace Mainstay\Http;

use Illuminate\Database\RecordNotFoundException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Mainstay\Auth\Capabilities;
use Mainstay\Auth\Gate;
use Mainstay\Auth\Role;
use Mainstay\Auth\User;
use Mainstay\Content\GlobalSet;
use Mainstay\Content\Taxonomy;
use Mainstay\Mainstay;
use Mainstay\Navigation;

/*
 | The roles' screens over Mainstay::accounts(), for whoever may manage
 | accounts: each role and how many hold it, and a role's capabilities as a
 | grid of what the registered types derive.
 */
class RoleController
{
    public function __construct(private Mainstay $mainstay) {}

    public function index(): View
    {
        $this->sees();

        return view('mainstay::roles.index', [
            'roles' => Role::query()->orderBy('name')->get(),
            'holding' => User::query()->selectRaw('role_id, count(*) as holding')->groupBy('role_id')->pluck('holding', 'role_id'),
        ]);
    }

    public function create(): View
    {
        $this->sees();

        return $this->form(new Role(['capabilities' => []]));
    }

    public function store(Request $request): RedirectResponse
    {
        $this->sees();
        $role = $this->mainstay->accounts()->saveRole(null, (string) $request->input('name'), $this->posted($request));

        return redirect()->route('mainstay.roles.edit', $role->id)->with('status', 'Role made.');
    }

    public function edit(int $id): View
    {
        $this->sees();

        return $this->form(Role::query()->find($id) ?? throw $this->missing());
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $this->sees();
        $this->found(fn () => $this->mainstay->accounts()->saveRole($id, (string) $request->input('name'), $this->posted($request)));

        return redirect()->route('mainstay.roles.edit', $id)->with('status', 'Saved.');
    }

    public function destroy(int $id): RedirectResponse
    {
        $this->sees();
        $this->found(fn () => $this->mainstay->accounts()->deleteRole($id));

        return redirect()->route('mainstay.roles')->with('status', 'Role deleted.');
    }

    /*
     | The grid: for each shape of type, a row covering every type of it and
     | a row per type, a column per verb; then the library's and the
     | accounts'. A box the user could not hand out is drawn disabled, and a
     | name the role holds that no type derives any longer is listed beneath,
     | so it can be unchecked.
     */
    private function form(Role $role): View
    {
        $types = $this->mainstay->registered();
        $entries = array_filter($types, fn (string $type) => ! is_subclass_of($type, GlobalSet::class) && ! is_subclass_of($type, Taxonomy::class));
        $section = fn (string $label, string $every, string $noun, array $verbs, array $types) => [
            'label' => $label,
            'verbs' => array_values($verbs),
            'rows' => [
                ['label' => $every, 'names' => array_map(fn (string $verb) => "{$verb}_{$noun}", array_keys($verbs))],
                ...array_map(fn (string $type) => ['label' => Navigation::label($type), 'names' => array_map(fn (string $verb) => Capabilities::names($type, $verb)[0], array_keys($verbs))], array_values($types)),
            ],
        ];

        $me = Gate::user();
        $editable = Gate::accounts()->allows('saveRole', [$role, $role->capabilities]);

        return view('mainstay::roles.form', [
            'role' => $role,
            'sections' => [
                $section('Content', 'Every entry type', 'entries', ['edit' => 'Edit', 'edit_published' => 'Edit published', 'edit_others' => "Edit others'", 'publish' => 'Publish', 'delete' => 'Delete', 'delete_others' => "Delete others'"], $entries),
                $section('Taxonomies', 'Every taxonomy', 'terms', ['manage' => 'Manage'], array_filter($types, fn (string $type) => is_subclass_of($type, Taxonomy::class))),
                $section('Globals', 'Every global', 'globals', ['edit' => 'Edit', 'publish' => 'Publish'], array_filter($types, fn (string $type) => is_subclass_of($type, GlobalSet::class))),
                ['label' => 'Media', 'verbs' => ['Upload', "Edit others'"], 'rows' => [['label' => 'The library', 'names' => Capabilities::MEDIA]]],
                ['label' => 'Accounts', 'verbs' => ['Manage accounts and roles'], 'rows' => [['label' => 'Every account', 'names' => [Capabilities::USERS]]]],
            ],
            'stale' => array_values(array_diff($role->capabilities, $this->mainstay->capabilities(), ['*'])),
            'covered' => fn (string $name) => Capabilities::uncovered($me, [$name]) === null,
            'changes' => $editable,
            'deletes' => $role->exists && ! User::query()->where('role_id', $role->id)->exists() && Gate::accounts()->allows('deleteRole', [$role]),
        ]);
    }

    /** @return list<string> */
    private function posted(Request $request): array
    {
        $posted = $request->input('capabilities', []);

        return array_values(array_filter(is_array($posted) ? $posted : [], is_string(...)));
    }

    private function sees(): void
    {
        if (Gate::accounts()->denies('viewAny', User::class)) {
            throw $this->missing();
        }
    }

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
}
