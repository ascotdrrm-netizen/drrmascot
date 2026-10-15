<?php

namespace App\Models\Drill;

use Illuminate\Database\Eloquent\Model;

/**
 * A photo or PDF attached to ONE of a drill's two submissions — `section` is
 * "documentation" or "after_activity" and never changes. Files live on the
 * private records disk (App\Support\Files: this server or Supabase Storage)
 * and are served only through DrillAttachmentController, which checks
 * sign-in and campus.
 */
class DrillAttachment extends Model
{
    public const SECTIONS = [DrillDocumentation::SECTION, DrillAfterActivityReport::SECTION];

    /** Per submission. */
    public const MAX_FILES = 10;

    public const MAX_IMAGE_KB = 5120;

    public const MAX_PDF_KB = 10240;

    public const IMAGE_MIMES = ['jpg', 'jpeg', 'png', 'webp'];

    protected $primaryKey = 'attachment_id';

    const UPDATED_AT = null;

    protected $fillable = ['section', 'path', 'original_name', 'mime_type', 'size_bytes'];

    protected $casts = ['size_bytes' => 'integer'];

    public function drill()
    {
        return $this->belongsTo(Drill::class, 'drill_id', 'drill_id');
    }

    public function isImage(): bool
    {
        return str_starts_with($this->mime_type, 'image/');
    }

    public function sizeLabel(): string
    {
        return $this->size_bytes >= 1048576
            ? number_format($this->size_bytes / 1048576, 1) . ' MB'
            : max(1, (int) round($this->size_bytes / 1024)) . ' KB';
    }
}
