<?php

use App\Models\Personnel;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * The demo accounts (demo.*@drrms.test) are gone for good: start-up no longer
 * makes them (app:create-demo-accounts was removed), and this deletes the ones
 * already there, once. Each goes through Personnel::delete(), as in User
 * Management: their records stay under their names, their photo goes.
 *
 * Demo Admin stays if it is the only DRRMO Admin, so no one is locked out;
 * delete it from User Management once there is another Admin.
 */
return new class extends Migration
{
    public const EMAILS = [
        'demo.admin@drrms.test',
        'demo.zabali@drrms.test',
        'demo.bazal@drrms.test',
        'demo.casiguran@drrms.test',
        'demo.reporter@drrms.test',
    ];

    public function up(): void
    {
        foreach (Personnel::whereIn('email', self::EMAILS)->get() as $person) {
            if ($person->role === 'DRRMO Admin' && Personnel::where('role', 'DRRMO Admin')->count() <= 1) {
                continue;
            }
            $person->delete();
        }

        // What the start-up remembered about them (see the removed command).
        DB::table('system_secrets')->where('key', 'like', 'demo_account_created:%')->delete();
    }

    public function down(): void
    {
        // Deleted accounts are not brought back.
    }
};
