<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="text-2xl font-semibold tracking-tight text-gray-900">{{ __('Clients') }}</h2>
            @can('add-clients')
                <a href="{{ route('clients.create') }}" class="inline-flex items-center rounded-lg bg-brand-500 px-3.5 py-2 text-sm font-medium text-white hover:bg-brand-600">{{ __('New client') }}</a>
            @endcan
        </div>
    </x-slot>

    @php($colors = ['active' => 'bg-green-100 text-green-800', 'waiting_dates' => 'bg-gray-100 text-gray-700', 'waiting' => 'bg-yellow-100 text-yellow-800', 'seasonal' => 'bg-blue-100 text-blue-800', 'stopped' => 'bg-red-100 text-red-800'])
    @php($priorities = ['high' => 'text-red-600 font-medium', 'medium' => 'text-orange-800', 'low' => 'text-gray-600'])
    @php($pill = 'whitespace-nowrap rounded-full px-2 py-0.5')
    <div class="py-6">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            @if (session('status'))
                <p class="mb-4 text-sm text-green-600">{{ __(ucfirst(str_replace('-', ' ', session('status')))) }}.</p>
            @endif
            @if ($clients->isEmpty())
                <x-empty-state class="bg-white" :action="__('Add the first client')" :href="auth()->user()->can('add-clients') ? route('clients.create') : null">{{ __('No clients yet. A client profile holds the accounts, the voice, the pillars and the approvers; everything else hangs off it.') }}</x-empty-state>
            @else
            <div class="overflow-x-auto rounded-xl border border-gray-300 bg-white">
                <table class="min-w-full text-sm">
                    <thead class="text-left text-xs uppercase tracking-wide text-gray-600">
                        <tr>
                            <th class="whitespace-nowrap px-4 py-3 font-medium">{{ __('Client') }}</th>
                            <th class="whitespace-nowrap px-4 py-3 font-medium">{{ __('Status') }}</th>
                            <th class="whitespace-nowrap px-4 py-3 font-medium">{{ __('Next plan due') }}</th>
                            <th class="whitespace-nowrap px-4 py-3 font-medium">{{ __('Rhythm') }}</th>
                            <th class="whitespace-nowrap px-4 py-3 font-medium">{{ __('Scheduled until') }}</th>
                            <th class="whitespace-nowrap px-4 py-3 font-medium">{{ __('Pending') }}</th>
                            <th class="whitespace-nowrap px-4 py-3 font-medium">{{ __('Links') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        @foreach ($clients as $client)
                            <tr class="align-top">
                                <td class="px-4 py-3">
                                    <a href="{{ route('calendar', ['client' => $client->slug]) }}" class="font-medium text-gray-900 hover:text-brand-600">{{ $client->name }}</a>
                                    <span class="ml-1 font-mono text-xs text-gray-500">{{ $client->code }}</span>
                                    <p class="text-xs text-gray-600">{{ __(str_replace('_', ' ', $client->type)) }} · {{ collect($client->profile['accounts'])->filter()->keys()->map(fn ($p) => str_replace('_', ' ', $p))->join(', ') ?: '—' }}</p>
                                    <div class="mt-2 flex flex-wrap gap-1.5 text-xs">
                                        <a href="{{ route('calendar', ['client' => $client->slug]) }}" class="{{ $pill }} bg-gray-100 text-gray-600">{{ trans_choice('{0} nothing scheduled this week|{1} :count post this week|[2,*] :count posts this week', $client->week_count) }}</a>
                                        <a href="{{ route('clients.reviews', $client) }}" class="{{ $pill }} {{ $client->in_review_count ? 'bg-yellow-100 text-yellow-800' : 'bg-gray-100 text-gray-600' }}">{{ trans_choice('{0} nothing waiting for the client|{1} :count waiting for the client|[2,*] :count waiting for the client', $client->in_review_count) }}</a>
                                        @if (config('features.publishing'))
                                        <a href="{{ route('clients.comments', $client) }}" class="{{ $pill }} {{ $client->open_comments_count ? 'bg-red-100 text-red-800' : 'bg-gray-100 text-gray-600' }}">{{ trans_choice('{0} no comments to answer|{1} :count comment to answer|[2,*] :count comments to answer', $client->open_comments_count) }}</a>
                                        @if ($client->failed_count)
                                            <a href="{{ route('calendar', ['client' => $client->slug]) }}" class="{{ $pill }} bg-red-100 text-red-800">{{ trans_choice('{1} :count post failed or needs a human|[2,*] :count posts failed or need a human', $client->failed_count) }}</a>
                                        @endif
                                        @if ($client->expiring_count)
                                            <a href="{{ route('clients.accounts', $client) }}" class="{{ $pill }} bg-red-100 text-red-800">{{ trans_choice('{1} :count token expiring|[2,*] :count tokens expiring', $client->expiring_count) }}</a>
                                        @endif
                                        @endif
                                    </div>
                                </td>
                                <td class="px-4 py-3">
                                    <span class="inline-block whitespace-nowrap rounded-full px-2 py-0.5 text-xs {{ $colors[$client->status] ?? 'bg-gray-100 text-gray-700' }}">{{ __(\App\Models\Client::STATUSES[$client->status] ?? $client->status) }}</span>
                                    <p class="mt-1 text-xs text-gray-600">@if ($client->priority)<span class="{{ $priorities[$client->priority] ?? '' }}">{{ __(\App\Models\Client::PRIORITIES[$client->priority]) }}</span>@endif @if ($client->priority && $client->owner) · @endif{{ $client->owner }}</p>
                                </td>
                                <td class="whitespace-nowrap px-4 py-3">
                                    @if ($client->plan_due)<span class="{{ $client->plan_due->lt(today()) ? 'text-red-600 font-medium' : '' }}">{{ $client->plan_due->format('d-m-Y') }}</span>@else <span class="text-gray-500">—</span> @endif
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-xs text-gray-700">
                                    @if ($client->posts_per_week !== null || $client->stories_per_week !== null)<p>{{ __(':posts posts, :stories stories a week', ['posts' => $client->posts_per_week ?? 0, 'stories' => $client->stories_per_week ?? 0]) }}</p>@endif
                                    @if ($client->tiktoks_per_month !== null)<p>{{ __(':n TikToks a month', ['n' => $client->tiktoks_per_month]) }}</p>@endif
                                </td>
                                <td class="whitespace-nowrap px-4 py-3">@if ($client->scheduled_until){{ \Carbon\Carbon::parse($client->scheduled_until)->format('d-m-Y') }}@else <span class="text-gray-500">—</span> @endif</td>
                                <td class="max-w-sm whitespace-pre-line px-4 py-3 text-xs text-gray-700">{{ $client->pending }}</td>
                                <td class="px-4 py-3 text-xs">
                                    @foreach ($client->links ?? [] as $link)
                                        <a href="{{ $link['url'] }}" target="_blank" rel="noopener" class="block font-medium text-gray-900 hover:text-brand-600">{{ $link['label'] ?: parse_url($link['url'], PHP_URL_HOST) }}</a>
                                    @endforeach
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @endif
        </div>
    </div>
</x-app-layout>
