<?php

namespace App\Console\Commands;

use App\Models\Campus;
use App\Models\Personnel;
use App\Rules\ValidPassword;
use Database\Seeders\SchoolSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class CreateAdmin extends Command
{
    protected $signature = 'app:create-admin';

    protected $description = 'Create the first approved administrator using a hidden password prompt';

    public function handle(): int
    {
        if (! $this->input->isInteractive()) {
            $this->error('Run this command interactively. Passwords must not be supplied as command arguments.');

            return self::FAILURE;
        }
        if (Personnel::where('role', 'DRRMO Admin')->exists()) {
            $this->error('An administrator already exists. Use Personnel management to add accounts.');

            return self::FAILURE;
        }
        $data = ['full_name' => $this->ask('Administrator full name'),
            'email' => mb_strtolower(trim((string) $this->ask('Administrator email'))),
            'password' => $this->secret('Password (8+ characters, uppercase, number and symbol)'),
            'password_confirmation' => $this->secret('Confirm password')];
        $validator = Validator::make($data, ['full_name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:255', 'unique:personnel,email'],
            'password' => ['required', 'string', 'max:128', 'confirmed', new ValidPassword]]);
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }
        DB::transaction(function () use ($data) {
            self::ensureCampuses();
            // An Admin covers every campus: no campus of their own.
            $person = new Personnel(['full_name' => $data['full_name'],
                'email' => $data['email'], 'role' => 'DRRMO Admin', 'password_hash' => Hash::make($data['password'])]);
            $person->forceFill(['is_approved' => true, 'approved_at' => now(), 'email_verified_at' => now()])->save();
        });
        $this->info('First administrator created. Campuses and schools are ready.');

        return self::SUCCESS;
    }

    /** The three ASCOT campuses and their schools. Safe to run more than once. */
    public static function ensureCampuses(): void
    {
        foreach (['Zabali' => 'Zabali, Baler, Aurora', 'Bazal' => 'Bazal, Maria Aurora, Aurora', 'Casiguran' => 'Casiguran, Aurora'] as $name => $address) {
            Campus::firstOrCreate(['campus_name' => 'ASCOT '.$name.' Campus'], ['address' => $address]);
        }
        (new SchoolSeeder)->run();
    }
}
