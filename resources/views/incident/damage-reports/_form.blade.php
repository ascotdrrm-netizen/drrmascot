@php $isEdit = isset($damageReport); @endphp

<x-card class="max-w-3xl">
    <form method="POST" data-ajax data-ajax-refresh="#page-content" action="{{ $formAction }}" class="space-y-4">
        @csrf
        @if ($isEdit)
            @method('PUT')
        @endif

        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
            <div>
                <label for="building_id" class="form-label">Building/Area affected</label>
                <select id="building_id" name="building_id" required
                        class="form-input">
                    <option value="">-- Select building --</option>
                    @foreach ($buildings as $building)
                        <option value="{{ $building->building_id }}" @selected((string) old('building_id', $damageReport->building_id ?? '') === (string) $building->building_id)>
                            {{ $building->building_name }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div>
                <label for="damage_level" class="form-label">Damage level</label>
                <select id="damage_level" name="damage_level" required aria-describedby="damage_level-help"
                        class="form-input">
                    @foreach (['Minor', 'Major', 'Total'] as $level)
                        <option value="{{ $level }}" @selected(old('damage_level', $damageReport->damage_level ?? '') === $level)>{{ $level }}</option>
                    @endforeach
                </select>
                <x-term-help id="damage_level-help" :terms="config('glossary.damage_level')" />
            </div>
        </div>

        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
            <div>
                <label for="casualty_count" class="form-label">Casualty count</label>
                <input type="number" id="casualty_count" name="casualty_count" min="0" step="1" aria-describedby="casualty_count-help"
                       value="{{ old('casualty_count', $damageReport->casualty_count ?? 0) }}" required
                       class="form-input">
                <p id="casualty_count-help" class="form-help">{{ config('glossary.casualty_count') }}</p>
            </div>

            <div>
                <label for="est_cost" class="form-label">Estimated cost (₱)</label>
                <input type="number" id="est_cost" name="est_cost" min="0" step="0.01" aria-describedby="est_cost-help"
                       value="{{ old('est_cost', $damageReport->est_cost ?? '') }}"
                       class="form-input">
                <p id="est_cost-help" class="form-help">{{ config('glossary.est_cost') }}</p>
            </div>
        </div>

        <x-form-actions>
            <button type="submit" class="btn btn-primary">
            {{ $isEdit ? 'Update damage report' : 'Add damage report' }}
        </button>
        </x-form-actions>
    </form>
</x-card>
