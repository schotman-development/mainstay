<?php

namespace Mainstay\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Mainstay\Auth\Role;
use Mainstay\Auth\User;

/*
 | An account, made on the server. How the first one is made: nothing on the
 | web creates one, so a new server has no page that whoever reaches it
 | first can claim. The password is asked for rather than taken as an option,
 | which would leave it in the shell's history.
 */
class UserCommand extends Command
{
    protected $signature = 'mainstay:user
        {email : The account\'s email, which it signs in with}
        {--name= : The name the admin shows; asked for when left out}
        {--role= : The role it holds, by name: administrator, editor, or one of the site\'s}';

    protected $description = 'Make an account that can sign in to the Mainstay admin';

    public function handle(): int
    {
        $email = mb_strtolower($this->argument('email'));
        $role = $this->option('role');

        if (blank($role)) {
            $this->components->error('Name the role the account holds, for the first one --role=administrator.');

            return self::FAILURE;
        }

        if (($held = Role::query()->where('name', $role)->first()) === null) {
            $this->components->error(sprintf('There is no role called "%s". There are: %s.', $role, Role::query()->orderBy('name')->pluck('name')->implode(', ')));

            return self::FAILURE;
        }

        if (User::query()->where('email', $email)->exists()) {
            $this->components->error("{$email} already has an account.");

            return self::FAILURE;
        }

        $name = $this->option('name') ?: $this->ask('Name');
        $password = $this->secret('Password');

        $validator = Validator::make(
            ['email' => $email, 'name' => $name, 'password' => $password, 'password_confirmation' => $this->secret('Password again')],
            ['email' => ['required', 'email'], 'name' => ['required', 'string', 'max:255'], 'password' => ['required', 'confirmed', Password::defaults()]],
        );

        if ($validator->fails()) {
            $this->components->bulletList($validator->errors()->all());

            return self::FAILURE;
        }

        User::query()->create(['name' => $name, 'email' => $email, 'password' => $password, 'role_id' => $held->id]);

        $this->components->info("{$email} can sign in as {$held->name}.");

        return self::SUCCESS;
    }
}
