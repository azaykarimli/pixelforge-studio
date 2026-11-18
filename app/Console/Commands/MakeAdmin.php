<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class MakeAdmin extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:make-admin {email : The email address of an existing account}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Let an account see the admin spend report';

    /**
     * There is deliberately no way to become an admin through the app
     * itself: an operator runs this on the server.
     */
    public function handle(): int
    {
        $user = User::query()->firstWhere('email', Str::lower($this->argument('email')));

        if ($user === null) {
            $this->components->error('No account has that email address. Register it in the app first, then run this again.');

            return self::FAILURE;
        }

        $user->forceFill(['is_admin' => true])->save();

        $this->components->info("{$user->email} can now see the spend report.");

        return self::SUCCESS;
    }
}
