<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A deleted SOS goes to the Archive (Trash) and can be restored, like every
// other DRRMO record. A false alarm is not deleted: it is Rejected.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sos_events', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('sos_events', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
