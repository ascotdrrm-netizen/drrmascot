<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phone numbers are now digits only (App\Rules\ValidPhone). Numbers saved
 * before that with spaces, dashes, dots or brackets ("0921 555 0139") would
 * fail validation on every later edit of the person, so they are cleaned
 * once. Anything that is not a mobile number after cleaning is left as is.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('personnel')->whereNotNull('contact_number')->orderBy('personnel_id')
            ->select(['personnel_id', 'contact_number'])
            ->each(function ($row) {
                $clean = preg_replace('/[\s().-]/', '', (string) $row->contact_number);
                if ($clean !== $row->contact_number && preg_match('/^(09\d{9}|\+?639\d{9})$/', $clean)) {
                    DB::table('personnel')->where('personnel_id', $row->personnel_id)->update(['contact_number' => $clean]);
                }
            });
    }

    public function down(): void
    {
        // Not undone: the spaced form carried no extra information.
    }
};
