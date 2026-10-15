<?php

namespace App\Http\Controllers\Drill;

use App\Http\Controllers\Concerns\HandlesCampusScoping;
use App\Http\Controllers\Concerns\LogsReportDownloads;
use App\Http\Controllers\Controller;
use App\Models\Drill\Drill;
use App\Models\Drill\DrillAttachment;
use App\Support\DrillLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The only way to reach a drill attachment: files are on the PRIVATE disk,
 * so every request passes sign-in (route middleware) and the drill's campus
 * check (RecordAccess) here. Shown inline (thumbnails, previews); downloaded
 * with ?download=1, which is also when it is logged — thumbnails would
 * otherwise flood the activity log.
 */
class DrillAttachmentController extends Controller
{
    use HandlesCampusScoping, LogsReportDownloads;

    public function show(Request $request, Drill $drill, DrillAttachment $attachment): StreamedResponse
    {
        $this->authorizeAttachment($drill, $attachment);
        $disk = \App\Support\Files::disk();
        abort_unless($disk->exists($attachment->path), 404);

        $download = $request->boolean('download');
        if ($download) {
            $this->logReportDownload(sprintf('%s downloaded the drill attachment "%s" (%s, %s)', Auth::user()->full_name, $attachment->original_name, $drill->drill_type, $drill->scheduled_date->format('M j, Y')));
        }

        return $download
            ? $disk->download($attachment->path, $attachment->original_name)
            : $disk->response($attachment->path, $attachment->original_name, ['Content-Type' => $attachment->mime_type, 'Cache-Control' => 'private, max-age=3600']);
    }

    /** Permanent: the file and its row go. Back to the form it belongs to. */
    public function destroy(Drill $drill, DrillAttachment $attachment): RedirectResponse
    {
        $this->authorizeAttachment($drill, $attachment);

        \App\Support\Files::disk()->delete($attachment->path);
        $attachment->delete();
        DrillLog::record(Auth::user(), $drill, 'updated', '— attachment "' . mb_strimwidth($attachment->original_name, 0, 60, '…') . '" removed');

        $form = $attachment->section === 'documentation' ? 'documentation' : 'after-activity';

        return redirect()
            ->route("{$this->routePrefix()}.drills.{$form}.edit", $drill)
            ->with('status', 'Attachment permanently removed.');
    }

    protected function authorizeAttachment(Drill $drill, DrillAttachment $attachment): void
    {
        $this->authorizeRecordAccess($drill);
        abort_unless((int) $attachment->drill_id === (int) $drill->drill_id, 404);
    }
}
