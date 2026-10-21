@extends('layouts.app')

@section('title', 'Hotlines - ASCOT DRRMS')
@section('heading', 'Emergency hotlines')
@section('subheading', 'The numbers on the home page, the phone app and every error page. Keep them current.')

@section('content')
    @if ($groups->isEmpty())
        <x-card>
            <x-empty-state icon="inbox" message="No hotlines have been added yet."
                           hint="Ask the DRRMO Admin to add the emergency hotlines." />
        </x-card>
    @else
        @if (auth()->user()->role !== 'DRRMO Admin' && empty($editable))
            <p class="mb-6 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
                No hotline group is linked to your campus yet, so you can view these numbers but not edit them. Ask the DRRMO Admin to link your campus to its municipality’s hotlines.
            </p>
        @endif
        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2" data-hotline-groups>
            @foreach ($groups as $group)
                <div class="card" data-hotline-group-card="{{ $group->hotline_group_id }}">
                    <div class="card-header">
                        <div>
                            <h2 class="card-title">{{ $group->title }}</h2>
                            <p class="caption leading-5">{{ $group->subtitle ?? ($group->campus ? 'Serves ' . $group->campus->campus_name : 'All campuses') }}</p>
                        </div>
                        @if (in_array($group->hotline_group_id, $editable, true))
                            <a href="{{ route("{$routePrefix}.hotlines.edit", $group) }}" class="btn btn-secondary btn-sm"><x-icon name="edit" class="h-4 w-4" /> Edit</a>
                        @endif
                    </div>
                    <ul class="divide-y divide-gray-100 text-sm">
                        @foreach ($group->hotlines as $hotline)
                            <li class="flex items-start justify-between gap-4 py-2">
                                <span class="font-medium text-gray-900">{{ $hotline->label }}</span>
                                <span class="text-right text-gray-600">
                                    @if ($hotline->radio)
                                        Radio {{ $hotline->radio }}
                                    @elseif (empty($hotline->numbers))
                                        <span class="italic">to be provided</span>
                                    @else
                                        {!! collect($hotline->numbers)->map(fn ($n) => e($n))->join('<br>') !!}
                                    @endif
                                </span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endforeach
        </div>
    @endif
@endsection
