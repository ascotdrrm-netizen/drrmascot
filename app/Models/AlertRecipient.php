<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One person an SMS alert went to, as they were when it was sent. */
class AlertRecipient extends Model
{
    protected $primaryKey = 'alert_recipient_id';
    public $timestamps = false;

    protected $fillable = ['alert_id', 'personnel_id', 'full_name', 'role', 'contact_number', 'status'];

    public function alert()
    {
        return $this->belongsTo(Alert::class, 'alert_id', 'alert_id');
    }
}
