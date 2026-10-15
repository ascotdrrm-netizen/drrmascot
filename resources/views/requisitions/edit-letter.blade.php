@extends('layouts.app')

@section('title', 'Supporting Letter - ASCOT DRRMS')
@section('heading', $requisition->hasLetter() ? 'Replace Letter' : 'Add Letter')

@section('breadcrumb')
    <x-back-link href='{{ route("{$routePrefix}.requisitions.show", $requisition) }}'>Back to requisition</x-back-link>
@endsection

@section('content')
    <x-card class="max-w-xl">
        <form method="POST" action="{{ route("{$routePrefix}.requisitions.letter.update", $requisition) }}" class="space-y-4" enctype="multipart/form-data">
            @csrf

            <div>
                <label for="letter" class="mb-1 block text-sm font-medium text-gray-700">Supporting letter</label>
                <input type="file" id="letter" name="letter" accept=".pdf,.doc,.docx" required
                       class="block w-full text-sm text-gray-700 file:mr-4 file:rounded-full file:border-0 file:bg-green-50 file:px-4 file:py-2 file:text-sm file:font-medium file:text-green-700 hover:file:bg-green-100">
                <p class="mt-1 text-xs text-gray-500">PDF or Word document, max 10MB.</p>
            </div>

            <button type="submit" class="inline-flex items-center justify-center rounded-full bg-green-600 px-5 py-2 text-sm font-medium text-white transition hover:bg-green-700">
                {{ $requisition->hasLetter() ? 'Replace Letter' : 'Add Letter' }}
            </button>
        </form>
    </x-card>
@endsection
