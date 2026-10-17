<?php

namespace App\Console\Commands;

use App\Models\Campus;
use App\Models\Personnel;
use App\Models\School;
use App\Rules\ValidPassword;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class CreateDemoAccounts extends Command
{
    protected $signature = 'app:create-demo-accounts {--from-env : Use DEMO_ACCOUNTS_PASSWORD for every account (Render start-up, no prompt)}';

    protected $description = 'Create one ready-to-use demo account per role, each with a new random password';

    /** email => [full name, role, campus short name] */
    private const ACCOUNTS = [
        'demo.admin@drrms.test' => ['Demo Admin', 'DRRMO Admin', 'Zabali'],
        'demo.zabali@drrms.test' => ['Demo Coordinator (Zabali)', 'Coordinator', 'Zabali'],
        'demo.bazal@drrms.test' => ['Demo Coordinator (Bazal)', 'Coordinator', 'Bazal'],
        'demo.casiguran@drrms.test' => ['Demo Coordinator (Casiguran)', 'Coordinator', 'Casiguran'],
        'demo.reporter@drrms.test' => ['Demo Reporter', 'Reporter', 'Zabali'],
    ];

    public function handle(): int
    {
        // A public site must never get a known password, so each one is new
        // and shown only here, or (--from-env) is the one the owner typed into
        // the host's dashboard. Existing accounts are left untouched.
        $shared = null;
        if ($this->option('from-env')) {
            $shared = (string) config('hosting.demo_accounts_password');
            if (Validator::make(['p' => $shared], ['p' => ['required', new ValidPassword]])->fails()) {
                $this->error('DEMO_ACCOUNTS_PASSWORD needs 8+ characters with an uppercase letter, a number and a symbol.');

                return self::FAILURE;
            }
        } elseif (! $this->input->isInteractive()) {
            $this->error('Run this command interactively.');

            return self::FAILURE;
        } elseif (! $this->confirm('Create demo accounts on the '.config('app.env').' database?')) {
            return self::FAILURE;
        }

        $created = DB::transaction(function () use ($shared) {
            CreateAdmin::ensureCampuses();
            $created = [];
            foreach (self::ACCOUNTS as $email => [$name, $role, $campusName]) {
                if (Personnel::where('email', $email)->exists()) {
                    continue;
                }
                $campus = Campus::where('campus_name', 'ASCOT '.$campusName.' Campus')->firstOrFail();
                $password = $shared ?? self::password();
                $person = new Personnel(['campus_id' => $campus->getKey(), 'full_name' => $name, 'role' => $role, 'email' => $email,
                    'school_id' => $role === 'Reporter' ? School::where('campus_id', $campus->getKey())->orderBy('school_id')->value('school_id') : null,
                    'password_hash' => Hash::make($password)]);
                $person->forceFill(['is_approved' => true, 'approved_at' => now(), 'email_verified_at' => now(),
                    'validated_by_coordinator_at' => $role === 'Reporter' ? now() : null])->save();
                $created[] = [$role, $campusName, $email, $password];
            }

            return $created;
        });

        if ($created === []) {
            $this->info('All demo accounts already exist. Nothing changed.');

            return self::SUCCESS;
        }
        if ($shared !== null) {
            // Host logs are not a place for passwords: emails only.
            $this->info('Demo accounts created: '.implode(', ', array_column($created, 2)).'. Password: the DEMO_ACCOUNTS_PASSWORD you set.');

            return self::SUCCESS;
        }
        $this->table(['Role', 'Campus', 'Email', 'Password'], $created);
        $lines = array_map(fn ($row) => implode("\t", $row), $created);
        $file = storage_path('app/private/demo-accounts.txt');
        file_put_contents($file, "Role\tCampus\tEmail\tPassword\n".implode("\n", $lines)."\n", FILE_APPEND);
        $this->info('Saved to '.$file.' (not in git). Delete these accounts before real use.');

        return self::SUCCESS;
    }

    private static function password(): string
    {
        do {
            $password = 'Demo-'.Str::upper(Str::random(4)).'-'.random_int(1000, 9999).'!';
        } while (Validator::make(['p' => $password], ['p' => [new ValidPassword]])->fails());

        return $password;
    }
}
