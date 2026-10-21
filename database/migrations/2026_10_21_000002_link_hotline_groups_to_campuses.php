<?php

use App\Support\Hotlines;
use Illuminate\Database\Migrations\Migration;

// The hotline migration linked campus groups to campuses that a fresh install
// did not have yet, leaving every group unlinked and the coordinators'
// Hotlines page empty. Links the groups still missing their campus.
return new class extends Migration
{
    public function up(): void
    {
        Hotlines::linkCampuses();
    }

    public function down(): void
    {
        // The links are correct data; nothing to undo.
    }
};
