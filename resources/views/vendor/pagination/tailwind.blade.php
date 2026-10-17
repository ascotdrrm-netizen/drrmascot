{{-- ASCOT-DRRMS override of Laravel's Tailwind pagination: 44px links on phones, 16px gaps, AAA gray text (see resources/css/app.css). --}}
@if ($paginator->hasPages())
    <nav role="navigation" aria-label="{{ __('Pagination Navigation') }}">

        <div class="flex gap-4 items-center justify-between sm:hidden">

            @if ($paginator->onFirstPage())
                <span class="inline-flex items-center min-h-[2.75rem] px-4 sm:min-h-[2.5rem] text-sm font-medium text-gray-600 bg-white border border-gray-300 cursor-not-allowed leading-5 rounded-md">
                    {!! __('pagination.previous') !!}
                </span>
            @else
                <a href="{{ $paginator->previousPageUrl() }}" rel="prev" class="inline-flex items-center min-h-[2.75rem] px-4 sm:min-h-[2.5rem] text-sm font-medium text-gray-800 bg-white border border-gray-300 leading-5 rounded-md hover:text-gray-700 focus:outline-hidden focus:ring-3 ring-gray-300 focus:border-blue-300 active:bg-gray-100 active:text-gray-800 transition ease-in-out duration-150 hover:bg-gray-100">
                    {!! __('pagination.previous') !!}
                </a>
            @endif

            @if ($paginator->hasMorePages())
                <a href="{{ $paginator->nextPageUrl() }}" rel="next" class="inline-flex items-center min-h-[2.75rem] px-4 sm:min-h-[2.5rem] text-sm font-medium text-gray-800 bg-white border border-gray-300 leading-5 rounded-md hover:text-gray-700 focus:outline-hidden focus:ring-3 ring-gray-300 focus:border-blue-300 active:bg-gray-100 active:text-gray-800 transition ease-in-out duration-150 hover:bg-gray-100">
                    {!! __('pagination.next') !!}
                </a>
            @else
                <span class="inline-flex items-center min-h-[2.75rem] px-4 sm:min-h-[2.5rem] text-sm font-medium text-gray-600 bg-white border border-gray-300 cursor-not-allowed leading-5 rounded-md">
                    {!! __('pagination.next') !!}
                </span>
            @endif

        </div>

        <div class="hidden sm:flex-1 sm:flex sm:gap-4 sm:items-center sm:justify-between">

            <div>
                <p class="text-sm text-gray-700 leading-5">
                    {!! __('Showing') !!}
                    @if ($paginator->firstItem())
                        <span class="font-medium">{{ $paginator->firstItem() }}</span>
                        {!! __('to') !!}
                        <span class="font-medium">{{ $paginator->lastItem() }}</span>
                    @else
                        {{ $paginator->count() }}
                    @endif
                    {!! __('of') !!}
                    <span class="font-medium">{{ $paginator->total() }}</span>
                    {!! __('results') !!}
                </p>
            </div>

            <div>
                <span class="inline-flex rtl:flex-row-reverse shadow-xs rounded-md">

                    {{-- Previous Page Link --}}
                    @if ($paginator->onFirstPage())
                        <span aria-disabled="true" aria-label="{{ __('pagination.previous') }}">
                            <span class="inline-flex items-center min-h-[2.75rem] px-4 sm:min-h-[2.5rem] text-sm font-medium text-gray-600 bg-white border border-gray-300 cursor-not-allowed rounded-l-md leading-5" aria-hidden="true">
                                <x-icon name="chevron_left" class="w-5 h-5" />
                            </span>
                        </span>
                    @else
                        <a href="{{ $paginator->previousPageUrl() }}" rel="prev" class="inline-flex items-center min-h-[2.75rem] px-4 sm:min-h-[2.5rem] text-sm font-medium text-gray-600 bg-white border border-gray-300 rounded-l-md leading-5 hover:text-gray-400 focus:outline-hidden focus:ring-3 ring-gray-300 focus:border-blue-300 active:bg-gray-100 active:text-gray-500 transition ease-in-out duration-150" aria-label="{{ __('pagination.previous') }}">
                            <x-icon name="chevron_left" class="w-5 h-5" />
                        </a>
                    @endif

                    {{-- Pagination Elements --}}
                    @foreach ($elements as $element)
                        {{-- "Three Dots" Separator --}}
                        @if (is_string($element))
                            <span aria-disabled="true">
                                <span class="inline-flex items-center min-h-[2.75rem] px-4 sm:min-h-[2.5rem] -ml-px text-sm font-medium text-gray-700 bg-white border border-gray-300 cursor-default leading-5">{{ $element }}</span>
                            </span>
                        @endif

                        {{-- Array Of Links --}}
                        @if (is_array($element))
                            @foreach ($element as $page => $url)
                                @if ($page == $paginator->currentPage())
                                    <span aria-current="page">
                                        <span class="inline-flex items-center min-h-[2.75rem] px-4 sm:min-h-[2.5rem] -ml-px text-sm font-medium text-gray-700 bg-gray-200 border border-gray-300 cursor-default leading-5">{{ $page }}</span>
                                    </span>
                                @else
                                    <a href="{{ $url }}" class="inline-flex items-center min-h-[2.75rem] px-4 sm:min-h-[2.5rem] -ml-px text-sm font-medium text-gray-700 bg-white border border-gray-300 leading-5 hover:text-gray-700 focus:outline-hidden focus:ring-3 ring-gray-300 focus:border-blue-300 active:bg-gray-100 active:text-gray-700 transition ease-in-out duration-150 hover:bg-gray-100" aria-label="{{ __('Go to page :page', ['page' => $page]) }}">
                                        {{ $page }}
                                    </a>
                                @endif
                            @endforeach
                        @endif
                    @endforeach

                    {{-- Next Page Link --}}
                    @if ($paginator->hasMorePages())
                        <a href="{{ $paginator->nextPageUrl() }}" rel="next" class="inline-flex items-center min-h-[2.75rem] px-4 sm:min-h-[2.5rem] -ml-px text-sm font-medium text-gray-600 bg-white border border-gray-300 rounded-r-md leading-5 hover:text-gray-400 focus:outline-hidden focus:ring-3 ring-gray-300 focus:border-blue-300 active:bg-gray-100 active:text-gray-500 transition ease-in-out duration-150" aria-label="{{ __('pagination.next') }}">
                            <x-icon name="chevron_right" class="w-5 h-5" />
                        </a>
                    @else
                        <span aria-disabled="true" aria-label="{{ __('pagination.next') }}">
                            <span class="inline-flex items-center min-h-[2.75rem] px-4 sm:min-h-[2.5rem] -ml-px text-sm font-medium text-gray-600 bg-white border border-gray-300 cursor-not-allowed rounded-r-md leading-5" aria-hidden="true">
                                <x-icon name="chevron_right" class="w-5 h-5" />
                            </span>
                        </span>
                    @endif
                </span>
            </div>
        </div>
    </nav>
@endif
