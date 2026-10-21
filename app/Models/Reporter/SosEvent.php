<?php

namespace App\Models\Reporter;

use App\Models\Campus;
use App\Models\Personnel;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SosEvent extends Model
{
    use SoftDeletes;

    /** Every status an SOS can have; Resolved and Rejected (a false alarm) are closed. */
    public const STATUSES = ['New', 'Acknowledged', 'Responding', 'Resolved', 'Rejected'];

    protected $primaryKey = 'sos_id';
    protected $fillable = ['reporter_id', 'campus_id', 'client_key', 'type', 'place',
        'latitude', 'longitude', 'accuracy_m', 'status', 'acknowledged_by', 'acknowledged_at', 'resolved_at'];
    protected $casts = ['acknowledged_at' => 'datetime', 'resolved_at' => 'datetime'];

    /** The SOS Console's live poll reads the same location text as the page. */
    protected $appends = ['location_label', 'map_url'];

    protected static function booted(): void
    {
        static::updated(function (SosEvent $sos) {
            // A deleted reporter (reporter_id null) has no one to tell.
            if ($sos->wasChanged('status') && $sos->reporter_id !== null) \App\Models\StaffNotification::create([
                'personnel_id' => $sos->reporter_id, 'campus_id' => $sos->campus_id,
                'kind' => 'sos_update', 'subject_id' => $sos->getKey(),
                'message' => "SOS #{$sos->getKey()} is now {$sos->status}.",
            ]);
        });
    }

    public function hasGps(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }

    /**
     * Where the sender is: the place they typed, else the phone's GPS fix
     * ("15.73612, 121.54901 (±12 m)"). The phone app sends only GPS, so
     * "Location not shared" means there was no fix at all.
     */
    protected function locationLabel(): Attribute
    {
        return Attribute::get(function () {
            $accuracy = $this->accuracy_m !== null ? ' (±' . $this->accuracy_m . ' m)' : '';
            if (filled($this->place)) {
                return $this->place . ($this->hasGps() ? $accuracy : '');
            }

            return $this->hasGps()
                ? sprintf('%.5f, %.5f', $this->latitude, $this->longitude) . $accuracy
                : 'Location not shared';
        });
    }

    /** A Google Maps link to the GPS fix, the same one the SOS text message carries. */
    protected function mapUrl(): Attribute
    {
        return Attribute::get(fn () => $this->hasGps()
            ? sprintf('https://maps.google.com/?q=%.6f,%.6f', $this->latitude, $this->longitude)
            : null);
    }

    public function reporter()
    {
        return $this->belongsTo(Personnel::class, 'reporter_id', 'personnel_id');
    }

    public function campus()
    {
        return $this->belongsTo(Campus::class, 'campus_id', 'campus_id');
    }
}
