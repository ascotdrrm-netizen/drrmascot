{{-- How a hazard is rated: the 5×5 risk matrix (App\Support\RiskMatrix). Wording in config/glossary.php. --}}
@php $matrix = \App\Support\RiskMatrix::class; @endphp
<div data-help-risk-matrix>
    <p class="text-sm leading-6 text-gray-700">Each hazard inspection rates two things from 1 to 5. Their product is the risk score; the score gives the level. A building or area shows the level of its latest inspection.</p>
    <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2">
        <div>
            <h4 class="text-sm font-semibold text-gray-900">Likelihood — {{ strtolower(config('glossary.likelihood')) }}</h4>
            <ol class="mt-2 space-y-1 text-sm leading-6 text-gray-700">
                @foreach ($matrix::LIKELIHOOD as $n => $step)
                    <li><span class="font-medium text-gray-900">{{ $n }} {{ $step['label'] }}</span> — {{ $step['meaning'] }}</li>
                @endforeach
            </ol>
        </div>
        <div>
            <h4 class="text-sm font-semibold text-gray-900">Impact — {{ strtolower(config('glossary.impact')) }}</h4>
            <ol class="mt-2 space-y-1 text-sm leading-6 text-gray-700">
                @foreach ($matrix::IMPACT as $n => $step)
                    <li><span class="font-medium text-gray-900">{{ $n }} {{ $step['label'] }}</span> — {{ $step['meaning'] }}</li>
                @endforeach
            </ol>
        </div>
    </div>
    <dl class="mt-4 divide-y divide-gray-100 text-sm">
        @foreach (config('glossary.risk_levels') as $level => $meaning)
            <div class="flex flex-col gap-x-4 py-3 sm:flex-row">
                <dt class="sm:w-40 sm:shrink-0"><x-badge :value="$level" /></dt>
                <dd class="leading-6 text-gray-700">{{ $meaning }}</dd>
            </div>
        @endforeach
    </dl>
    <p class="mt-2 text-xs leading-5 text-gray-600">Example: a crack that is likely to worsen (4) and could make the building unsafe (4) scores 16 — High. Basis: the likelihood × consequence matrix of ISO 31000, as used in DRRM risk assessment.</p>
</div>
