@extends('layouts.app')

@section('title', 'Campus Status - ASCOT DRRMS')
@section('heading', 'Campus Status')
@section('subheading', 'What each campus is told right now. It shows on the home page and at the top of the phone app.')

@php
    $levels = [
        'normal' => ['Normal', 'check_circle', 'border-green-200 bg-green-50 text-green-800'],
        'advisory' => ['Advisory in effect', 'warning', 'border-amber-200 bg-amber-50 text-amber-800'],
        'suspended' => ['Classes suspended', 'event_busy', 'border-red-200 bg-red-50 text-red-800'],
        'evacuate' => ['Evacuate', 'emergency', 'border-red-300 bg-red-100 text-red-900'],
    ];
@endphp

@section('content')
    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3" x-data="{ levels: @js($levels) }" data-campus-status-board>
        @foreach ($statuses as $row)
            <form class="card space-y-4" x-data="{ status: @js($row['status']), detail: @js($row['detail']), saved: false, async save() { try { await window.drrmsAjax.send({ method: 'put', url: @js(route('api.command.campus-status.update', $row['campus_id'])), data: { status: this.status, detail: this.detail } }); this.saved = true; } catch (error) { window.drrmsAjax.toast(error.response?.data?.message || 'Could not publish the status.', 'danger'); } } }" x-on:submit.prevent="save()">
                <h2 class="card-title">{{ $row['campus'] }}</h2>

                <fieldset>
                    <legend class="form-label">Status</legend>
                    <div class="mt-1 grid grid-cols-1 gap-2">
                        @foreach ($levels as $key => [$label, $icon, $tone])
                            <label class="flex min-h-[2.75rem] cursor-pointer items-center gap-3 rounded-lg border px-3 text-sm font-medium"
                                   :class="status === '{{ $key }}' ? '{{ $tone }}' : 'border-gray-200 text-gray-700'">
                                <input type="radio" name="status" value="{{ $key }}" x-model="status" class="sr-only">
                                <x-icon :name="$icon" class="h-5 w-5" /> {{ $label }}
                            </label>
                        @endforeach
                    </div>
                </fieldset>

                <div>
                    <label class="form-label" for="detail-{{ $loop->index }}">What people should know</label>
                    <textarea id="detail-{{ $loop->index }}" rows="3" maxlength="200" class="form-input" x-model="detail"></textarea>
                </div>

                {{-- How it will look to students and staff. --}}
                <div>
                    <p class="eyebrow mb-2">Preview</p>
                    <div class="flex items-start gap-3 rounded-lg border p-3 text-sm" :class="levels[status][2]">
                        <span class="font-semibold" x-text="levels[status][0]"></span>
                        <span class="opacity-90" x-text="detail"></span>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary w-full">Publish status</button>
                <p class="text-xs text-gray-500" x-show="saved" role="status">Status published for this campus.</p>
            </form>
        @endforeach
    </div>
@endsection
