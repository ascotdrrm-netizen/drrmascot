{{-- Upload field for one submission's attachments (inside its form). --}}
@php $remaining = max(0, $maxFiles - $attachments->count()); @endphp
<div>
    <label for="attachments" class="form-label">Add photos or PDFs <span class="font-normal text-gray-600">(optional)</span></label>
    @if ($remaining > 0)
        <input type="file" id="attachments" name="attachments[]" multiple accept="image/jpeg,image/png,image/webp,application/pdf"
               aria-describedby="attachments-help"
               class="block w-full text-sm text-gray-700 file:mr-4 file:rounded-full file:border-0 file:bg-accent-soft file:px-4 file:py-2 file:text-sm file:font-medium file:text-accent-ink hover:file:bg-accent-soft-hover">
        <p id="attachments-help" class="form-help">
            Photos (JPG, PNG or WebP) up to {{ \App\Models\Drill\DrillAttachment::MAX_IMAGE_KB / 1024 }} MB each, PDFs up to {{ \App\Models\Drill\DrillAttachment::MAX_PDF_KB / 1024 }} MB.
            You can add {{ $remaining }} more ({{ $maxFiles }} at most). Files are private: only signed-in staff of this campus and the DRRMO can open them.
        </p>
    @else
        <p class="form-help">This already has the maximum of {{ $maxFiles }} files. Remove one below to add another.</p>
    @endif
    @error('attachments')<p class="form-error">{{ $message }}</p>@enderror
    @foreach ($errors->get('attachments.*') as $messages)
        @foreach ($messages as $message)<p class="form-error">{{ $message }}</p>@endforeach
    @endforeach
</div>
