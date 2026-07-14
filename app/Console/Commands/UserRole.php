<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class UserRole extends Command
{
    protected $signature = 'user:role {email} {role}';

    protected $description = 'Set a user role: patient | practitioner | admin';

    public function handle(): int
    {
        $email = (string) $this->argument('email');
        $role = (string) $this->argument('role');

        if (! in_array($role, ['patient', 'practitioner', 'admin'], true)) {
            $this->error("invalid role \"{$role}\" — expected patient | practitioner | admin");

            return self::FAILURE;
        }

        $user = User::where('email', $email)->first();
        if ($user === null) {
            $this->error("no user with email {$email}");

            return self::FAILURE;
        }

        $user->role = $role;
        $user->save();
        $this->info("{$user->email} → {$role}");

        return self::SUCCESS;
    }
}
