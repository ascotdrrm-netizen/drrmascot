<?php

namespace App\Models;

use App\Notifications\ResetPasswordNotification;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;

class Personnel extends Authenticatable implements MustVerifyEmail
{
    use HasFactory, Notifiable, \Illuminate\Auth\MustVerifyEmail;

    /** Files whose owning rows cascade when an account is permanently removed. */
    protected static array $filesToRemove = [];

    protected static function booted(): void
    {
        static::updating(function (Personnel $person) {
            if ($person->isDirty('contact_number')) $person->phone_verified_at = null;
            if ($person->isDirty('email')) $person->email_verified_at = null;
        });
        // Only the account's own photo goes. Their reports, SOS records and the
        // photos/proof attached to them are DRRMO records and stay (the
        // reporter_id becomes null: "Former reporter").
        static::deleting(function (Personnel $person) {
            static::$filesToRemove[spl_object_id($person)] = $person->photo_path ? [$person->photo_path] : [];
        });
        static::deleted(function (Personnel $person) {
            $paths = static::$filesToRemove[spl_object_id($person)] ?? [];
            unset(static::$filesToRemove[spl_object_id($person)]);
            \App\Support\TrashPurge::deleteFilesAfterCommit(\App\Support\Files::diskName(), $paths);
        });
    }

    protected $table = 'personnel';
    protected $primaryKey = 'personnel_id';
    public $timestamps = false;

    protected $fillable = [
        'campus_id',
        'school_id',
        'full_name',
        'role',
        'email',
        'password_hash',
        'contact_number',
        'photo_path',
    ];

    protected $hidden = [
        'password_hash',
    ];

    // Not fillable: is_approved is set only by registration (false) and the
    // Admin's approve action (true) — never from request input.
    protected $casts = [
        'is_approved' => 'boolean',
        'email_verified_at' => 'datetime',
        'phone_verified_at' => 'datetime',
        'validated_by_coordinator_at' => 'datetime',
        'approved_at' => 'datetime',
        'privacy_consented_at' => 'datetime',
    ];

    public function getPhotoUrlAttribute(): ?string
    {
        return $this->photo_path ? \App\Support\Files::url($this->photo_path) : null;
    }

    /**
     * The photo's URL only when the file is on the private records disk;
     * a photo_path whose file has
     * gone shows the initials instead of a broken image.
     */
    public function existingPhotoUrl(): ?string
    {
        return $this->photo_path && \App\Support\Files::exists($this->photo_path) ? $this->photo_url : null;
    }

    // Laravel's auth system expects "password" — map it to our password_hash column.
    // Both overrides are needed: getAuthPassword() for reading the hash to check
    // against on login, getAuthPasswordName() so Laravel's automatic rehash-on-login
    // (EloquentUserProvider::rehashPasswordIfRequired) knows which column to write
    // the newly-rehashed value back to instead of a nonexistent "password" column.
    public function getAuthPassword()
    {
        return $this->password_hash;
    }

    public function getAuthPasswordName()
    {
        return 'password_hash';
    }

    // Personnel inherits CanResetPassword (and getEmailForPasswordReset(),
    // which already correctly returns $this->email) from
    // Illuminate\Foundation\Auth\User — only the notification itself needs
    // overriding, to send ASCOT DRRMS-branded copy instead of Laravel's
    // generic default.
    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ResetPasswordNotification($token));
    }

    public function campus()
    {
        return $this->belongsTo(Campus::class, 'campus_id', 'campus_id');
    }

    /** Attribution only (see App\Models\School) — never used for access control. */
    public function school()
    {
        return $this->belongsTo(\App\Models\School::class, 'school_id', 'school_id');
    }

    /**
     * Stored lower-case and trimmed: PostgreSQL compares text case-sensitively,
     * and the NormalizeEmails middleware lower-cases what forms send.
     */
    public function setEmailAttribute(?string $value): void
    {
        $this->attributes['email'] = $value === null ? null : mb_strtolower(trim($value));
    }

    public function isAdmin(): bool
    {
        return $this->role === 'DRRMO Admin';
    }
}
