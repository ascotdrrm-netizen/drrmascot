@php $isEdit = isset($evaluation); @endphp

<x-card class="max-w-2xl">
    <form method="POST" action="{{ $formAction }}" class="space-y-4">
        @csrf
        @if ($isEdit)
            @method('PUT')
        @endif

        <div>
            <label for="personnel_id" class="mb-1 block text-sm font-medium text-gray-700">Personnel</label>
            <select id="personnel_id" name="personnel_id" required
                    class="block w-full rounded-lg border border-gray-300 px-4 py-2 text-sm focus:border-accent-line focus:outline-hidden focus:ring-2 focus:ring-accent-line">
                <option value="">-- Select personnel --</option>
                @foreach ($personnel as $person)
                    <option value="{{ $person->personnel_id }}" @selected((string) old('personnel_id', $evaluation->personnel_id ?? '') === (string) $person->personnel_id)>
                        {{ $person->full_name }}
                    </option>
                @endforeach
            </select>
        </div>

        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
            <div>
                <label for="pre_score" class="mb-1 block text-sm font-medium text-gray-700">Pre-score (0-100)</label>
                <input type="number" id="pre_score" name="pre_score" min="0" max="100" step="0.01"
                       value="{{ old('pre_score', $evaluation->pre_score ?? '') }}" required
                       class="block w-full rounded-lg border border-gray-300 px-4 py-2 text-sm focus:border-accent-line focus:outline-hidden focus:ring-2 focus:ring-accent-line">
            </div>

            <div>
                <label for="post_score" class="mb-1 block text-sm font-medium text-gray-700">Post-score (0-100)</label>
                <input type="number" id="post_score" name="post_score" min="0" max="100" step="0.01"
                       value="{{ old('post_score', $evaluation->post_score ?? '') }}" placeholder="Leave blank if not yet evaluated"
                       class="block w-full rounded-lg border border-gray-300 px-4 py-2 text-sm focus:border-accent-line focus:outline-hidden focus:ring-2 focus:ring-accent-line">
            </div>
        </div>

        <button type="submit" class="inline-flex w-full items-center justify-center rounded-full bg-accent px-5 py-2 text-sm font-medium text-on-accent transition hover:bg-accent-hover sm:w-auto">
            {{ $isEdit ? 'Update evaluation' : 'Add evaluation' }}
        </button>
    </form>
</x-card>
