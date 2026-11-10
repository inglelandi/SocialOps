<x-app-layout>
    <x-slot name="header">
        <h2 class="text-2xl font-semibold tracking-tight text-gray-900">{{ __('Jobs') }}</h2>
    </x-slot>

    @php($th = 'px-6 py-2 font-medium')
    @php($td = 'px-6 py-2')
    <div class="py-6">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-4 text-sm">
            @if (session('status'))
                <p class="flex items-center gap-1.5 text-green-600"><x-icon name="check-circle" class="h-5 w-5" />{{ __(ucfirst(str_replace('-', ' ', session('status')))) }}.</p>
            @endif

            <x-section icon="sparkles" :title="__('Waiting for Claude')" :description="__('What the team asked Claude for. The scheduled task picks it up once an hour.')" flush class="overflow-x-auto">
                <table class="w-full">
                    <thead class="text-left text-xs uppercase tracking-wide text-gray-600"><tr><th class="{{ $th }}">{{ __('Request') }}</th><th class="{{ $th }}">{{ __('Client') }}</th><th class="{{ $th }}">{{ __('Queued') }}</th><th></th></tr></thead>
                    <tbody class="divide-y divide-gray-200">
                        @forelse ($claude as $request)
                            @php($plan = $request instanceof \App\Models\Report)
                            @php($at = \App\Planning\ClaudeQueue::askedAt($request))
                            <tr class="align-top">
                                <td class="{{ $td }}">
                                    @if ($plan)
                                        <span class="font-medium text-gray-900">{{ __('Plan') }}:</span> {{ \App\Planning\ClaudeQueue::describe($request) }}
                                        @if (filled($request->meta['instruction'] ?? null))<p class="mt-0.5 text-gray-600">{{ __('About:') }} {{ Str::limit($request->meta['instruction'], 160) }}</p>@endif
                                    @else
                                        <span class="font-medium text-gray-900">{{ __('Captions') }}:</span> <a class="font-medium text-gray-900 hover:text-brand-600" href="{{ route('posts.edit', $request) }}">{{ $request->name }}</a>
                                    @endif
                                </td>
                                <td class="{{ $td }} whitespace-nowrap"><a class="font-medium text-gray-900 hover:text-brand-600" href="{{ route('clients.planner', $request->client) }}">{{ $request->client->name }}</a></td>
                                <td class="{{ $td }} whitespace-nowrap tabular-nums">{{ $at->format('d-m-Y H:i') }} <span class="text-gray-600">· {{ $at->diffForHumans() }}</span>
                                    @if (\App\Planning\ClaudeQueue::isLate($request)) <span class="rounded-full bg-red-100 px-2 py-0.5 text-xs text-red-800">{{ __('over 2 hours') }}</span>@endif
                                </td>
                                <td class="{{ $td }} whitespace-nowrap text-right">
                                    @if ($plan)
                                        <form method="POST" action="{{ route('claude.cancel', $request) }}">@csrf @method('DELETE')<button class="font-medium text-gray-900 hover:text-brand-600">{{ __('Cancel') }}</button></form>
                                    @else
                                        <form method="POST" action="{{ route('claude.stop', $request) }}">@csrf<button class="font-medium text-gray-900 hover:text-brand-600">{{ __('Stop asking') }}</button></form>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="{{ $td }} text-gray-600">{{ __('Nothing waiting for Claude.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </x-section>

            <x-section icon="clock" :title="__('Waiting')" :description="__('Publishing and planning run on the queue, which the per-minute scheduled task works through.')" flush>
                <table class="w-full">
                    <thead class="text-left text-xs uppercase tracking-wide text-gray-600"><tr><th class="{{ $th }}">{{ __('Job') }}</th><th class="{{ $th }}">{{ __('Attempts') }}</th><th class="{{ $th }}">{{ __('Due') }}</th></tr></thead>
                    <tbody class="divide-y divide-gray-200">
                        @forelse ($waiting as $job)
                            <tr><td class="{{ $td }} font-mono">{{ $job->name }}@if ($job->running) <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs">{{ __('running') }}</span>@endif</td><td class="{{ $td }}">{{ $job->attempts }}</td><td class="{{ $td }}">{{ $job->at }}</td></tr>
                        @empty
                            <tr><td colspan="3" class="{{ $td }} text-gray-600">{{ __('Nothing waiting.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </x-section>

            <x-section icon="exclamation-triangle" :title="__('Failed')" flush>
                <table class="w-full">
                    <thead class="text-left text-xs uppercase tracking-wide text-gray-600"><tr><th class="{{ $th }}">{{ __('Job') }}</th><th class="{{ $th }}">{{ __('Error') }}</th><th class="{{ $th }}">{{ __('Failed at') }}</th><th></th></tr></thead>
                    <tbody class="divide-y divide-gray-200">
                        @forelse ($failed as $job)
                            <tr class="align-top">
                                <td class="{{ $td }} font-mono whitespace-nowrap">{{ $job->name }}</td>
                                <td class="{{ $td }} text-red-600">{{ Str::limit($job->error, 160) }}</td>
                                <td class="{{ $td }} whitespace-nowrap">{{ $job->at }}</td>
                                <td class="{{ $td }} whitespace-nowrap">
                                    <form method="POST" action="{{ route('jobs.retry', $job->uuid) }}" class="inline">@csrf<button class="font-medium text-gray-900 hover:text-brand-600">{{ __('Retry') }}</button></form>
                                    <form method="POST" action="{{ route('jobs.forget', $job->uuid) }}" class="inline ml-2">@csrf @method('DELETE')<button class="text-gray-600 hover:underline">{{ __('Remove') }}</button></form>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="{{ $td }} text-gray-600">{{ __('No failed jobs.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </x-section>

            @php($priced = \App\Models\ClaudeCall::cost(1, 1) !== null)
            <x-section icon="cpu-chip" :title="__('Claude, last 30 days')" :description="$priced ? null : __('Set ANTHROPIC_PRICE_INPUT and ANTHROPIC_PRICE_OUTPUT (USD per million tokens) in .env to see the cost column.')" flush>
                <table class="w-full">
                    <thead class="text-left text-xs uppercase tracking-wide text-gray-600"><tr><th class="{{ $th }}">{{ __('Client') }}</th><th class="{{ $th }}">{{ __('Purpose') }}</th><th class="{{ $th }} text-right">{{ __('Calls') }}</th><th class="{{ $th }} text-right">{{ __('Tokens in') }}</th><th class="{{ $th }} text-right">{{ __('Tokens out') }}</th>@if ($priced)<th class="{{ $th }} text-right">{{ __('USD') }}</th>@endif</tr></thead>
                    <tbody class="divide-y divide-gray-200">
                        @forelse ($usage as $row)
                            <tr>
                                <td class="{{ $td }}">{{ $row->client?->name ?? '—' }}</td>
                                <td class="{{ $td }}">{{ str_replace('_', ' ', $row->purpose) }}</td>
                                <td class="{{ $td }} text-right">{{ $row->calls }}</td>
                                <td class="{{ $td }} text-right">{{ number_format($row->input_tokens) }}</td>
                                <td class="{{ $td }} text-right">{{ number_format($row->output_tokens) }}</td>
                                @if ($priced)<td class="{{ $td }} text-right">{{ number_format(\App\Models\ClaudeCall::cost($row->input_tokens, $row->output_tokens), 2) }}</td>@endif
                            </tr>
                        @empty
                            <tr><td colspan="6" class="{{ $td }} text-gray-600">{{ __('No Claude calls yet.') }}</td></tr>
                        @endforelse
                        @if ($usage->isNotEmpty())
                            <tr class="font-semibold">
                                <td class="{{ $td }}" colspan="2">{{ __('Total') }}</td>
                                <td class="{{ $td }} text-right">{{ $usage->sum('calls') }}</td>
                                <td class="{{ $td }} text-right">{{ number_format($usage->sum('input_tokens')) }}</td>
                                <td class="{{ $td }} text-right">{{ number_format($usage->sum('output_tokens')) }}</td>
                                @if ($priced)<td class="{{ $td }} text-right">{{ number_format(\App\Models\ClaudeCall::cost((int) $usage->sum('input_tokens'), (int) $usage->sum('output_tokens')), 2) }}</td>@endif
                            </tr>
                        @endif
                    </tbody>
                </table>
            </x-section>
        </div>
    </div>
</x-app-layout>
