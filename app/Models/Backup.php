<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Backup extends Model
{
    protected $primaryKey = 'backup_id';

    public $timestamps = true;

    const UPDATED_AT = null;

    protected $fillable = [
        'filename',
        'storage_path',
        'created_by',
        'file_size',
    ];

    public function creator()
    {
        return $this->belongsTo(Personnel::class, 'created_by', 'personnel_id');
    }
}
