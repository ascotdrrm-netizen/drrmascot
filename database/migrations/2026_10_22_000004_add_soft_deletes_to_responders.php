<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Deleting a responder on Dispatch moves them to the Archive, where they can be
// restored; their past assignments keep pointing at them.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('responders', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('responders', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
