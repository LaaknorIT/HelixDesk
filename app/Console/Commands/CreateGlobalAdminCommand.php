<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

class CreateGlobalAdminCommand extends Command
{
    protected $signature = 'helixdesk:create-admin
        {--name= : Display name of the administrator}
        {--email= : Email address used to sign in}
        {--password= : Password (prompted for when omitted; avoid passing it on shared shells)}';

    protected $description = 'Create a global administrator who can sign in to the global admin panel';

    public function handle(): int
    {
        $attributes = [
            'name' => $this->option('name') ?: ($this->input->isInteractive() ? text('Name', required: true) : null),
            'email' => $this->option('email') ?: ($this->input->isInteractive() ? text('Email', required: true) : null),
            'password' => $this->option('password') ?: ($this->input->isInteractive() ? password('Password', required: true) : null),
        ];

        $validator = Validator::make($attributes, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', Password::min(12)],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $user = User::query()->create([
            ...$attributes,
            'tenant_id' => null,
            'type' => 'global_admin',
            'is_admin' => true,
        ]);

        $this->info("Global administrator {$user->email} created. Sign in at ".url('/global-admin').'.');

        return self::SUCCESS;
    }
}
