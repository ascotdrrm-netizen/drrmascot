@php $isEdit = isset($inspection); @endphp

<x-card class="max-w-3xl">
    <form method="POST" data-ajax data-ajax-refresh="#page-content" action="{{ $formAction }}" enctype="multipart/form-data" class="space-y-4">
        @csrf
        @if ($isEdit)
            @method('PUT')
        @endif

        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
            <div>
                <label for="inspection_date" class="form-label">Inspection date</label>
                <input type="date" id="inspection_date" name="inspection_date" max="{{ now()->toDateString() }}"
                       value="{{ old('inspection_date', isset($inspection) ? $inspection->inspection_date->toDateString() : '') }}" required
                       class="form-input">
            </div>

            <div>
                <label for="risk_level" class="form-label">Risk level</label>
                <select id="risk_level" name="risk_level" required aria-describedby="risk_level-help"
                        class="form-input">
                    @foreach (['Low', 'Medium', 'High'] as $level)
                        <option value="{{ $level }}" @selected(old('risk_level', $inspection->risk_level ?? '') === $level)>{{ $level }}</option>
                    @endforeach
                </select>
                {{-- PLACEHOLDER until the risk checklist defines the levels. --}}
                <p id="risk_level-help" class="form-help">{{ config('glossary.risk_level') }}</p>
            </div>
        </div>

        <div>
            <label for="findings" class="form-label">Findings</label>
            <textarea id="findings" name="findings" maxlength="2000" rows="4" aria-describedby="findings-help"
                      class="form-input">{{ old('findings', $inspection->findings ?? '') }}</textarea>
            <p id="findings-help" class="form-help">What you saw and where — for example cracked walls, exposed wiring or a blocked exit.</p>
        </div>

        <div>
            <label for="photo" class="form-label">Photo (optional)</label>
            @if ($isEdit && $inspection->photo_path)
                <div class="mb-4">
                    <p class="text-xs leading-6 text-gray-600">Current photo:</p>
                    <img src="{{ $inspection->photo_url }}" alt="Inspection photo" class="h-24 w-24 rounded-lg object-cover">
                </div>
            @endif
            <input type="file" id="photo" name="photo" accept="image/jpeg,image/png,image/webp"
                   class="block w-full text-sm text-gray-700 file:mr-4 file:rounded-full file:border-0 file:bg-accent-soft file:px-4 file:py-2 file:text-sm file:font-medium file:text-accent-ink hover:file:bg-accent-soft-hover">
        </div>

        <div>
            <label for="photo_caption" class="form-label">What does the photo show? (optional)</label>
            <input type="text" id="photo_caption" name="photo_caption" maxlength="200" class="form-input"
                   value="{{ old('photo_caption', $inspection->photo_caption ?? '') }}" placeholder="e.g. Crack along the second-floor stair landing">
            @error('photo_caption')<p class="form-error">{{ $message }}</p>@enderror
        </div>

        @include('building._location-fields', ['model' => $isEdit ? $inspection : null, 'campusId' => $building->campus_id])

        <x-form-actions>
            <button type="submit" class="btn btn-primary">
            {{ $isEdit ? 'Save changes' : 'Save inspection' }}
        </button>
        </x-form-actions>
    </form>
</x-card>
