@extends('layouts.app')

@section('title', 'Edit hotlines: ' . $group->title . ' - ASCOT DRRMS')
@section('heading', 'Edit hotlines: ' . $group->title)
@section('subheading', 'One office per row. Put several numbers on separate lines. Leave the numbers blank for “to be provided”.')

@section('breadcrumb')
    <x-back-link :href="route($routePrefix . '.hotlines.index')">Back to hotlines</x-back-link>
@endsection

@php
    $rows = old('entries', $group->hotlines->map(fn ($h) => [
        'label' => $h->label,
        'numbers' => implode("\n", $h->numbers ?? []),
        'radio' => $h->radio,
    ])->all());
@endphp

@section('content')
    <form method="POST" data-ajax data-ajax-refresh="#page-content" action="{{ route("{$routePrefix}.hotlines.update", $group) }}" class="card max-w-3xl space-y-6"
          x-data="{ rows: @js(array_values($rows)) }" data-hotline-form>
        @csrf
        @method('PUT')

        <div>
            <label for="note" class="form-label">Note under the heading (optional)</label>
            <input type="text" id="note" name="note" maxlength="300" class="form-input" value="{{ old('note', $group->note) }}">
        </div>

        <div class="space-y-4">
            <template x-for="(row, i) in rows" :key="i">
                <div class="grid grid-cols-1 gap-3 rounded-lg border border-gray-100 bg-gray-50 p-4 sm:grid-cols-[1fr_1fr_8rem_auto]">
                    <div>
                        <label class="form-label" :for="'label-' + i">Office or agency</label>
                        <input class="form-input" :id="'label-' + i" :name="'entries[' + i + '][label]'" x-model="row.label" maxlength="100" placeholder="e.g. MDRRMO Baler">
                    </div>
                    <div>
                        <label class="form-label" :for="'numbers-' + i">Phone numbers</label>
                        <textarea class="form-input !py-2" rows="2" :id="'numbers-' + i" :name="'entries[' + i + '][numbers]'" x-model="row.numbers" placeholder="e.g. 0917-000-0000"></textarea>
                    </div>
                    <div>
                        <label class="form-label" :for="'radio-' + i">Radio (optional)</label>
                        <input class="form-input" :id="'radio-' + i" :name="'entries[' + i + '][radio]'" x-model="row.radio" maxlength="40" placeholder="e.g. 147.000 MHz">
                    </div>
                    <div class="flex items-end">
                        <button type="button" class="icon-btn" x-on:click="rows.splice(i, 1)" aria-label="Remove this row"><x-icon name="delete" /></button>
                    </div>
                </div>
            </template>
            <button type="button" class="btn btn-secondary" x-on:click="rows.push({ label: '', numbers: '', radio: '' })"><x-icon name="add" class="h-4 w-4" /> Add an office</button>
        </div>

        @if ($errors->any())
            <p class="form-error">{{ $errors->first() }}</p>
        @endif

        <x-form-actions>
            <a href="{{ route($routePrefix . '.hotlines.index') }}" class="btn btn-secondary">Cancel</a>
            <button type="submit" class="btn btn-primary">Save hotlines</button>
        </x-form-actions>
    </form>
@endsection
