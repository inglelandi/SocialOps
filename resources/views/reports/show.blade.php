<x-app-layout :client="$report->client">
    @php($btn = 'inline-flex items-center gap-1.5 rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50')
    <x-slot name="header">
        <h2 class="text-2xl font-semibold tracking-tight text-gray-900">{{ $report->client->name }}: {{ $report->title }}</h2>
    </x-slot>

    <x-slot name="actions">
        <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-700">{{ __(\App\Models\Report::KINDS[$report->kind] ?? $report->kind) }}</span>
        <a class="{{ $btn }}" href="{{ route('reports.download', $report) }}"><x-icon name="arrow-down-tray" class="h-4 w-4" />{{ __('Download .md') }}</a>
        @if ($report->kind === 'plan' && isset($report->meta['posts']))
            <a class="{{ $btn }} border-brand-300 text-brand-700 hover:bg-brand-50" href="{{ route('reports.word', $report) }}"><x-icon name="document-arrow-down" class="h-4 w-4" />{{ __('Download Word') }}</a>
        @endif
        @if ($report->kind === 'monthly')
            <form method="POST" action="{{ route('reports.send', $report) }}" onsubmit="return confirm(@js(__('Mail this report to the client\'s approvers, with the numbers as a CSV?')))">@csrf<button class="{{ $btn }} border-brand-300 text-brand-700 hover:bg-brand-50"><x-icon name="envelope" class="h-4 w-4" />{{ __('Email to client') }}</button></form>
        @endif
        <a class="text-sm text-gray-600 hover:underline" href="{{ route('clients.reports', $report->client) }}">{{ __('All reports') }}</a>
    </x-slot>

    @include('reports._body')
</x-app-layout>
