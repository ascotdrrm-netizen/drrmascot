<?php

namespace Database\Seeders;

use App\Models\Campus;
use App\Models\Personnel;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            throw new \RuntimeException('The development seeder is disabled in production. Run app:create-admin for a fresh database.');
        }
        $zabali = Campus::create([
            'campus_name' => 'ASCOT Zabali Campus',
            'address' => 'Zabali, Baler, Aurora',
        ]);

        Campus::create([
            'campus_name' => 'ASCOT Bazal Campus',
            'address' => 'Bazal, Maria, Aurora',
        ]);

        Campus::create([
            'campus_name' => 'ASCOT Casiguran Campus',
            'address' => 'Casiguran, Aurora',
        ]);

        $this->call(SchoolSeeder::class);

        // The hotline groups were created before these campuses existed.
        \App\Support\Hotlines::linkCampuses();

        // Default DRRMO admin account — change this password immediately after first login.
        Personnel::create([
            'campus_id' => $zabali->campus_id,
            'full_name' => 'DRRMO Administrator',
            'role' => 'DRRMO Admin',
            'email' => 'admin@ascot.edu.ph',
            'password_hash' => Hash::make('changeme123'),
            'contact_number' => null,
        ]);
    }
}
