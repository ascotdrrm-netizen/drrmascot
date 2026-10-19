<?php

namespace App\Http\Controllers\Drill;

use App\Http\Controllers\Concerns\HandlesCampusScoping;
use App\Http\Controllers\Concerns\LogsReportDownloads;
use App\Http\Controllers\Controller;
use App\Models\Drill\Drill;
use App\Models\Drill\DrillAttachment;
use App\Support\DrillLog;
use App\Support\FilenameSlug;
use App\Support\PdfImage;
use App\Traits\RendersPdfAssets;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Shared mechanics of a drill's two after-the-drill submissions — the
 * documentation and the After Activity Report. Each subclass names its own
 * model, section, fields, form and PDF; the two never share a record or a
 * file. One per drill: the form creates it the first time and edits it after.
 *
 * Access: anyone who can see the drill (the Admin; any coordinator of its
 * campus — RecordAccess). The person who first submits it is recorded.
 */
abstract class DrillSubmissionController extends Controller
{
    use HandlesCampusScoping, RendersPdfAssets, LogsReportDownloads;

    /** e.g. App\Models\Drill\DrillDocumentation */
    abstract protected function modelClass(): string;

    /** Relation name on Drill. */
    abstract protected function relation(): string;

    /** "Documentation" / "After Activity Report" */
    abstract protected function label(): string;

    /** Route name segment: "documentation" / "after-activity". */
    abstract protected function routeKey(): string;

    abstract protected function formView(): string;

    abstract protected function pdfView(): string;

    /** Activity-log action for a submission or update. */
    abstract protected function logAction(): string;

    /** @return array<string, array> validation rules for the record's own fields */
    abstract protected function rules(): array;

    public function edit(Drill $drill): View
    {
        $this->authorizeRecordAccess($drill);
        $drill->load(['campus', $this->relation()]);

        return view($this->formView(), [
            'drill' => $drill,
            'record' => $drill->{$this->relation()},
            'attachments' => $drill->attachments()->where('section', $this->section())->get(),
            'routePrefix' => $this->routePrefix(),
            'maxFiles' => DrillAttachment::MAX_FILES,
        ]);
    }

    public function update(Request $request, Drill $drill): RedirectResponse
    {
        $this->authorizeRecordAccess($drill);
        $user = Auth::user();

        $existingFiles = $drill->attachments()->where('section', $this->section())->count();
        $data = $request->validate($this->rules() + [
            'attachments' => ['nullable', 'array', 'max:' . max(0, DrillAttachment::MAX_FILES - $existingFiles)],
            'attachments.*' => ['file', 'mimes:' . implode(',', array_merge(DrillAttachment::IMAGE_MIMES, ['pdf'])), 'max:' . DrillAttachment::MAX_PDF_KB, $this->imageSizeRule()],
        ], [
            'attachments.max' => 'A ' . strtolower($this->label()) . ' can have at most ' . DrillAttachment::MAX_FILES . " files; it already has {$existingFiles}.",
            'attachments.*.mimes' => 'Attach photos (JPG, PNG or WebP) or PDF files only.',
            'attachments.*.max' => 'A PDF can be at most ' . (DrillAttachment::MAX_PDF_KB / 1024) . ' MB.',
        ]);

        $files = $request->file('attachments', []);
        $fields = collect($data)->except(['attachments'])->all();

        $save = function () use ($drill, $fields, $files, $user) {
            $record = $drill->{$this->relation()}()->first();
            $created = $record === null;

            if ($created) {
                $class = $this->modelClass();
                /** @var Model $record */
                $record = new $class($fields);
                $record->forceFill(['drill_id' => $drill->drill_id, 'submitted_by' => $user->personnel_id, 'submitted_by_name' => $user->full_name]);
                $record->save();
            } else {
                $record->update($fields);
            }

            foreach ($files as $file) {
                $this->storeAttachment($drill, $file, $user);
            }

            return $created;
        };

        // Two sends at once (a double tap) both find no record; the unique
        // drill_id lets one insert, and the other then saves as an update.
        try {
            $created = DB::transaction($save);
        } catch (UniqueConstraintViolationException) {
            DB::transaction($save);
            $created = false;
        }

        DrillLog::record($user, $drill, $this->logAction(), strtolower($this->label()) . ($created ? ' submitted' : ' updated') . ($files ? ' (' . count($files) . ' file' . (count($files) === 1 ? '' : 's') . ' attached)' : ''));

        // Point to the drill's other submission while it is still missing.
        $other = $this->relation() === 'documentation' ? ['afterActivityReport', 'Next: write the After Activity Report.'] : ['documentation', 'Next: add the documentation.'];
        $next = $drill->{$other[0]}()->exists() ? 'You can print both from the drill page.' : $other[1];

        return redirect()
            ->route("{$this->routePrefix()}.drills.show", $drill)
            ->with('status', $this->label() . ($created ? ' submitted. ' : ' updated. ') . $next);
    }

    public function pdf(Drill $drill): Response
    {
        $this->authorizeRecordAccess($drill);
        $drill->load(['campus', $this->relation()]);
        $record = $drill->{$this->relation()};
        abort_if($record === null, 404, 'This drill has no ' . strtolower($this->label()) . ' yet.');

        $files = $drill->attachments()->where('section', $this->section())->get();

        $pdf = $this->loadPdfViewWithPageCount($this->pdfView(), [
            'drill' => $drill,
            'record' => $record,
            'images' => $files->filter->isImage()
                ->map(fn (DrillAttachment $a) => ['src' => PdfImage::fromStoredFile($a->path), 'caption' => $a->original_name])
                ->filter(fn ($i) => $i['src'] !== null)
                ->values(),
            'documents' => $files->reject->isImage()->values(),
        ]);

        $this->logReportDownload(sprintf('%s printed the Drill %s for %s (%s, %s)', Auth::user()->full_name, $this->label(), $drill->drill_type, $drill->campus->campus_name ?? 'Unknown campus', $drill->scheduled_date->format('M j, Y')));

        return $pdf->download(sprintf(
            'ASCOT-Drill-%s_%s_%s_%s.pdf',
            FilenameSlug::titleCase($this->label()),
            FilenameSlug::titleCase($drill->drill_type),
            $record->date_conducted->format('Y-m-d'),
            FilenameSlug::titleCase($drill->campus->campus_name ?? 'Campus')
        ));
    }

    protected function section(): string
    {
        return ($this->modelClass())::SECTION;
    }

    /** Photos have a smaller cap than PDFs. */
    protected function imageSizeRule(): \Closure
    {
        return function (string $attribute, $file, \Closure $fail) {
            if ($file instanceof UploadedFile
                && in_array(strtolower($file->getClientOriginalExtension()), DrillAttachment::IMAGE_MIMES, true)
                && $file->getSize() > DrillAttachment::MAX_IMAGE_KB * 1024) {
                $fail('A photo can be at most ' . (DrillAttachment::MAX_IMAGE_KB / 1024) . ' MB.');
            }
        };
    }

    /** Stored on the private records disk (App\Support\Files) under a random name; served by DrillAttachmentController. */
    protected function storeAttachment(Drill $drill, UploadedFile $file, $user): DrillAttachment
    {
        $extension = strtolower($file->getClientOriginalExtension());
        $path = $file->storeAs("drill-attachments/{$drill->drill_id}/{$this->section()}", Str::random(24) . '.' . $extension, \App\Support\Files::diskName());

        $attachment = new DrillAttachment([
            'section' => $this->section(),
            'path' => $path,
            'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
            'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
            'size_bytes' => $file->getSize(),
        ]);
        $attachment->forceFill(['drill_id' => $drill->drill_id, 'uploaded_by' => $user->personnel_id, 'uploaded_by_name' => $user->full_name])->save();

        return $attachment;
    }
}
