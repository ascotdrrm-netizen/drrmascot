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
        </div>

        @php
            // The 5×5 risk matrix (App\Support\RiskMatrix): the inspector rates
            // two factors; the score and level are computed, here and on the server.
            $matrix = \App\Support\RiskMatrix::class;
            $likelihood = (int) old('likelihood', $inspection->likelihood ?? 0);
            $impact = (int) old('impact', $inspection->impact ?? 0);
            $cell = ['Low' => 'bg-green-100 text-green-900', 'Moderate' => 'bg-amber-100 text-amber-900', 'High' => 'bg-orange-100 text-orange-800', 'Very High' => 'bg-red-100 text-red-900'];
        @endphp
        <fieldset class="rounded-lg border border-gray-200 p-4" data-risk-matrix
                  x-data="{ l: {{ $likelihood }}, i: {{ $impact }}, bands: @js($matrix::BANDS),
                            get score() { return this.l * this.i },
                            get level() { for (const [name, max] of Object.entries(this.bands)) { if (this.score <= max) return name } return '' } }">
            <legend class="px-1 text-sm font-semibold text-gray-900">Risk rating (5×5 matrix)</legend>
            <p class="form-help mt-0">{{ config('glossary.risk_level') }}</p>
            @if ($isEdit && ! $inspection->likelihood && $inspection->risk_level)
                <p class="mt-2 text-sm text-gray-700">This inspection was rated <x-badge :value="$inspection->risk_level" /> before the matrix. Choose a likelihood and an impact to rate it again.</p>
            @endif

            <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2">
                <div>
                    <label for="likelihood" class="form-label">Likelihood</label>
                    <select id="likelihood" name="likelihood" required x-model.number="l" aria-describedby="likelihood-help" class="form-input">
                        <option value="">Choose 1 to 5</option>
                        @foreach ($matrix::LIKELIHOOD as $n => $step)
                            <option value="{{ $n }}" @selected($likelihood === $n)>{{ $n }} · {{ $step['label'] }}</option>
                        @endforeach
                    </select>
                    <p id="likelihood-help" class="form-help">{{ config('glossary.likelihood') }}
                        @foreach ($matrix::LIKELIHOOD as $n => $step)<span x-show="l === {{ $n }}" @if ($likelihood !== $n) style="display:none" @endif> {{ $step['meaning'] }}</span>@endforeach
                    </p>
                </div>
                <div>
                    <label for="impact" class="form-label">Impact</label>
                    <select id="impact" name="impact" required x-model.number="i" aria-describedby="impact-help" class="form-input">
                        <option value="">Choose 1 to 5</option>
                        @foreach ($matrix::IMPACT as $n => $step)
                            <option value="{{ $n }}" @selected($impact === $n)>{{ $n }} · {{ $step['label'] }}</option>
                        @endforeach
                    </select>
                    <p id="impact-help" class="form-help">{{ config('glossary.impact') }}
                        @foreach ($matrix::IMPACT as $n => $step)<span x-show="i === {{ $n }}" @if ($impact !== $n) style="display:none" @endif> {{ $step['meaning'] }}</span>@endforeach
                    </p>
                </div>
            </div>

            <div class="mt-4 flex flex-wrap items-start gap-6">
                <table class="border-separate border-spacing-1 text-center text-xs" aria-label="Risk matrix: likelihood down, impact across">
                    <thead>
                        <tr>
                            <th scope="col" class="px-1 text-left font-medium text-gray-600">L \ I</th>
                            @foreach ($matrix::IMPACT as $n => $step)
                                <th scope="col" class="w-9 font-medium text-gray-600" title="{{ $step['label'] }}">{{ $n }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach (array_reverse($matrix::LIKELIHOOD, true) as $ln => $lstep)
                            <tr>
                                <th scope="row" class="px-1 text-left font-medium text-gray-600" title="{{ $lstep['label'] }}">{{ $ln }}</th>
                                @foreach ($matrix::IMPACT as $in => $istep)
                                    @php $cellScore = $ln * $in; @endphp
                                    <td class="h-8 w-9 rounded {{ $cell[$matrix::level($cellScore)] }}"
                                        :class="l === {{ $ln }} && i === {{ $in }} ? 'ring-2 ring-gray-900 font-bold' : ''"
                                        title="{{ $lstep['label'] }} × {{ $istep['label'] }} = {{ $cellScore }} ({{ $matrix::level($cellScore) }})">{{ $cellScore }}</td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <div class="min-w-0 text-sm" aria-live="polite">
                    <p class="text-gray-600">Result</p>
                    <p class="mt-1 text-2xl font-semibold text-gray-900" x-show="l && i" @if (! ($likelihood && $impact)) style="display:none" @endif>
                        <span x-text="score">{{ $likelihood * $impact ?: '' }}</span>
                        <span class="text-base font-medium" x-text="level">{{ $likelihood && $impact ? $matrix::rate($likelihood, $impact) : '' }}</span>
                    </p>
                    <p class="mt-1 text-gray-600" x-show="! (l && i)" @if ($likelihood && $impact) style="display:none" @endif>Choose both to see the level.</p>
                    <ul class="mt-3 space-y-1 text-xs text-gray-600">
                        @foreach ($matrix::LEVELS as $level)
                            <li><span class="inline-block h-3 w-3 rounded-sm align-middle {{ $cell[$level] }}"></span> {{ $level }} {{ $matrix::range($level) }}</li>
                        @endforeach
                    </ul>
                </div>
            </div>
        </fieldset>

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
