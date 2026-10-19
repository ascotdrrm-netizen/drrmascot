@php
    $isEdit = isset($building);
    $fixedHazardTypes = \App\Http\Controllers\Building\BuildingController::HAZARD_TYPES;
    $currentHazardType = $building->hazard_type ?? null;
    $isCustomHazardType = $currentHazardType !== null && ! in_array($currentHazardType, $fixedHazardTypes, true);
    $selectedHazardType = old('hazard_type', $isCustomHazardType ? 'Other' : $currentHazardType);
    $hazardTypeOtherValue = old('hazard_type_other', $isCustomHazardType ? $currentHazardType : '');
@endphp

<x-card class="max-w-3xl">
    <form method="POST" data-ajax data-ajax-refresh="#page-content" action="{{ $formAction }}" class="space-y-4" enctype="multipart/form-data">
        @csrf
        @if ($isEdit)
            @method('PUT')
        @endif

        <x-form-section title="Building/Area details">
        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
            <div>
                <label for="building_name" class="form-label">Building/Area name</label>
                <input type="text" id="building_name" name="building_name" maxlength="100"
                       value="{{ old('building_name', $building->building_name ?? '') }}" required
                       class="form-input">
            </div>

            <div>
                <label for="hazard_type" class="form-label">Hazard type</label>
                <select id="hazard_type" name="hazard_type" required aria-describedby="hazard_type-help"
                        onchange="document.getElementById('hazard_type_other_wrapper').hidden = this.value !== 'Other'; document.getElementById('hazard_type_other').required = this.value === 'Other';"
                        class="form-input">
                    @foreach (array_merge($fixedHazardTypes, ['Other']) as $type)
                        <option value="{{ $type }}" @selected($selectedHazardType === $type)>{{ $type }}</option>
                    @endforeach
                </select>
                <p id="hazard_type-help" class="form-help">{{ config('glossary.building_hazard_type') }}</p>
            </div>
        </div>

        <div>
            <label for="description" class="form-label">Building/Area description (optional)</label>
            <textarea id="description" name="description" rows="3" maxlength="2000" class="form-input" placeholder="Location, use, floors, nearby landmarks, and access points.">{{ old('description', $building->description ?? '') }}</textarea>
        </div>

        <div id="hazard_type_other_wrapper" @if ($selectedHazardType !== 'Other') hidden @endif>
            <label for="hazard_type_other" class="form-label">Specify hazard type</label>
            <input type="text" id="hazard_type_other" name="hazard_type_other" maxlength="100"
                   value="{{ $hazardTypeOtherValue }}" @if ($selectedHazardType === 'Other') required @endif
                   class="form-input">
        </div>

        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
            {{-- The level is not picked here: it comes from the latest hazard inspection (5×5 risk matrix). --}}
            <div @if ($routePrefix !== 'admin') class="md:col-span-2" @endif data-risk-from-inspection>
                <p class="form-label">Risk level</p>
                <div class="rounded-lg bg-gray-50 p-3 text-sm text-gray-700">
                    @if ($isEdit && $building->risk_level)
                        <x-badge :value="$building->risk_level" /> <span class="ml-1">from its latest inspection.</span>
                    @else
                        Not yet assessed.
                    @endif
                    <span class="mt-1 block text-xs text-gray-600">{{ config('glossary.risk_level_from_inspection') }}</span>
                </div>
            </div>

            @if ($routePrefix === 'admin')
                <div>
                    <label for="campus_id" class="form-label">Campus</label>
                    <select id="campus_id" name="campus_id" required
                            class="form-input">
                        @foreach ($campuses as $campus)
                            <option value="{{ $campus->campus_id }}" @selected((string) old('campus_id', $building->campus_id ?? '') === (string) $campus->campus_id)>
                                {{ $campus->campus_name }}
                            </option>
                        @endforeach
                    </select>
                </div>
            @endif
        </div>
        </x-form-section>

        <x-form-section title="Photo and location" description="Optional.">
        <div>
            <label for="photo" class="form-label">Photo (optional)</label>
            @if ($isEdit && $building->photo_path)
                <div class="mb-4">
                    <p class="text-xs leading-6 text-gray-600">Current photo:</p>
                    <img src="{{ $building->photo_url }}" alt="{{ $building->building_name }}" class="h-24 w-24 rounded-lg object-cover">
                </div>
            @endif
            <input type="file" id="photo" name="photo" accept="image/jpeg,image/png,image/webp"
                   class="block w-full text-sm text-gray-700 file:mr-4 file:rounded-full file:border-0 file:bg-accent-soft file:px-4 file:py-2 file:text-sm file:font-medium file:text-accent-ink hover:file:bg-accent-soft-hover">
        </div>

        <div>
            <label for="photo_caption" class="form-label">What does the photo show? (optional)</label>
            <input type="text" id="photo_caption" name="photo_caption" maxlength="200" class="form-input"
                   value="{{ old('photo_caption', $building->photo_caption ?? '') }}" placeholder="e.g. Crack along the second-floor stair landing">
            @error('photo_caption')<p class="form-error">{{ $message }}</p>@enderror
        </div>

        @include('building._location-fields', ['model' => $isEdit ? $building : null])
        </x-form-section>

        <x-form-actions>
            <button type="submit" class="btn btn-primary">
            {{ $isEdit ? 'Update Building/Area' : 'Create Building/Area' }}
        </button>
        </x-form-actions>
    </form>
</x-card>
