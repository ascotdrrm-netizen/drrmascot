<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personnel', fn (Blueprint $table) => $table->timestamp('phone_verified_at')->nullable());
        Schema::create('phone_verifications', function (Blueprint $table) {
            $table->foreignId('personnel_id')->primary()->constrained('personnel', 'personnel_id')->cascadeOnDelete();
            $table->string('phone_number', 20);
            $table->string('code_hash');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('last_sent_at');
        });
        Schema::create('broadcasts', function (Blueprint $table) {
            $table->id('broadcast_id');
            $table->foreignId('campus_id')->constrained('campuses', 'campus_id')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('personnel', 'personnel_id')->nullOnDelete();
            $table->foreignId('published_by')->nullable()->constrained('personnel', 'personnel_id')->nullOnDelete();
            $table->uuid('client_key')->unique();
            $table->string('title', 150);
            $table->text('body');
            $table->string('sms_text', 480)->nullable();
            $table->boolean('send_email')->default(false);
            $table->boolean('send_sms')->default(false);
            $table->string('status', 20)->default('Draft');
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->index(['campus_id', 'published_at']);
        });
        Schema::create('broadcast_deliveries', function (Blueprint $table) {
            $table->id('delivery_id');
            $table->foreignId('broadcast_id')->constrained('broadcasts', 'broadcast_id')->cascadeOnDelete();
            $table->foreignId('personnel_id')->nullable()->constrained('personnel', 'personnel_id')->nullOnDelete();
            $table->string('channel', 10);
            $table->string('destination', 150)->nullable();
            $table->string('status', 20)->default('Pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('error', 255)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->unique(['broadcast_id', 'personnel_id', 'channel']);
            $table->index(['broadcast_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('broadcast_deliveries');
        Schema::dropIfExists('broadcasts');
        Schema::dropIfExists('phone_verifications');
        Schema::table('personnel', fn (Blueprint $table) => $table->dropColumn('phone_verified_at'));
    }
};
