<x-app-layout>
    <x-slot name="header">
        <h2 class="text-2xl font-semibold tracking-tight text-gray-900">{{ __('Needs attention') }}</h2>
    </x-slot>

    @php($quiet = $week->isEmpty() && $drafts->isEmpty() && $stuck->isEmpty() && $waiting->isEmpty() && $deadlines->isEmpty() && $unanswered->isEmpty() && $tokens->isEmpty() && $drift->isEmpty() && ! $failedJobs && ! $lateClaude)
    @php($line = fn ($post) => '<a class="font-medium text-gray-900 hover:text-brand-600" href="'.route('posts.edit', $post).'">'.e($post->name).'</a> <span class="text-gray-600">'.e($post->client->code.' · '.collect($post->platforms)->map(fn ($p) => str_replace('_', ' ', ucfirst($p)))->join(', ')).'</span>')
    @php($item = 'flex flex-wrap items-baseline gap-x-3 gap-y-1 px-6 py-2.5')
    @php($when = 'w-40 shrink-0 tabular-nums text-gray-600')
    @php($flag = 'rounded-full bg-red-100 px-2 py-0.5 text-xs text-red-800')
    <div class="py-6">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-4 text-sm">
            @if (session('status'))
                <p class="text-green-600">{{ __(ucfirst(str_replace('-', ' ', session('status')))) }}.</p>
            @endif
            @if ($clients->isEmpty())
                <x-empty-state class="bg-white" :action="__('Add the first client')" :href="auth()->user()->can('add-clients') ? route('clients.create') : null">{{ __('No clients yet. A client profile holds the accounts, the voice, the pillars and the approvers; everything else hangs off it.') }}</x-empty-state>
            @elseif ($quiet)
                <x-empty-state class="bg-white" :action="__('Open the calendar')" :href="route('calendar')">{{ __('Nothing needs you right now.') }}</x-empty-state>
            @endif

            @if ($lateClaude)
                <p class="rounded-xl bg-red-100 px-6 py-4 font-medium text-red-800" role="alert">{{ __('Claude has not picked up :n requests for over 2 hours. Check that the scheduled task is on.', ['n' => $lateClaude]) }} <a class="underline" href="{{ route('jobs.index') }}">{{ __('See what is waiting') }}</a></p>
            @endif

            <div class="grid gap-4 lg:grid-cols-2 lg:items-start">
                <div class="space-y-4">
                    @if ($today->isNotEmpty() || $tomorrow->isNotEmpty())
                        <x-section :title="__('Going out')" flush>
                            <ul class="divide-y divide-gray-200">
                                @foreach ([__('Today') => $today, __('Tomorrow') => $tomorrow] as $label => $posts)
                                    @if ($posts->isNotEmpty())
                                        <li class="bg-gray-50 px-6 py-1.5 text-xs font-medium uppercase tracking-wide text-gray-600">{{ $label }}</li>
                                        @foreach ($posts as $post)
                                            <li class="{{ $item }}"><span class="{{ $when }}">{{ $post->scheduled_at->format('H:i') }}</span> <span>{!! $line($post) !!}</span>
                                                @if ($post->scheduled_at->lt(now()->subMinutes(10))) <span class="{{ $flag }}">{{ config('features.publishing') ? __('late: is the queue running?') : __('past its time: posted? Mark it published') }}</span> @endif
                                            </li>
                                        @endforeach
                                    @endif
                                @endforeach
                                <li class="px-6 py-3"><a href="{{ route('today') }}" class="font-medium text-gray-900 underline decoration-gray-400 underline-offset-4 hover:text-brand-600">{{ __('Post them by hand: open Post today') }}</a></li>
                            </ul>
                        </x-section>
                    @endif

                    @if ($drafts->isNotEmpty())
                        <x-section :title="__('Due within 7 days, not sent for approval yet')" flush>
                            <ul class="divide-y divide-gray-200">
                                @foreach ($drafts as $post)
                                    <li class="{{ $item }}"><span class="{{ $when }}">{{ $post->scheduled_at->translatedFormat('D d-m-Y H:i') }}</span> <span class="flex-1">{!! $line($post) !!} <x-state-badge :state="$post->state" /></span></li>
                                @endforeach
                            </ul>
                        </x-section>
                    @endif

                    @if ($stuck->isNotEmpty())
                        <x-section :title="__('Failed or waiting for a human')" flush>
                            <ul class="divide-y divide-gray-200">
                                @foreach ($stuck as $post)
                                    <li class="{{ $item }}"><a class="font-medium text-gray-900 hover:text-brand-600" href="{{ route('posts.edit', $post) }}">{{ $post->name }}</a> <x-state-badge :state="$post->state" /> <span class="text-red-600">{{ Str::limit($post->publish_error, 120) }}</span></li>
                                @endforeach
                            </ul>
                        </x-section>
                    @endif

                    @if ($drift->isNotEmpty())
                        <x-section :title="__('Pillar mix off target')" :description="__('Share of posts per pillar, :days days back and ahead, against the profile.', ['days' => \App\Planning\PillarMix::DAYS])" flush>
                            <ul class="divide-y divide-gray-200">
                                @foreach ($drift as $row)
                                    <li class="{{ $item }}"><a class="font-medium text-gray-900 hover:text-brand-600" href="{{ route('clients.edit', $row['client']) }}">{{ $row['client']->name }}</a>
                                        <span class="text-gray-600">@foreach ($row['mix']['drifted'] as $pillar){{ __(':pillar at :actual%, target :target%', ['pillar' => str_replace('_', ' ', $pillar), 'actual' => $row['mix']['rows'][$pillar]['actual'], 'target' => $row['mix']['rows'][$pillar]['target']]) }}@if (! $loop->last) · @endif @endforeach</span>
                                    </li>
                                @endforeach
                            </ul>
                        </x-section>
                    @endif
                </div>

                <div class="space-y-4">
                    @if ($deadlines->isNotEmpty())
                        <x-section :title="__('Deadlines in the next 7 days')" flush>
                            <ul class="divide-y divide-gray-200">
                                @foreach ($deadlines as $deadline)
                                    <li class="{{ $item }}"><span class="{{ $deadline->due_on->lt(today()) ? $flag : $when }}">{{ $deadline->due_on->translatedFormat('D d-m-Y') }}</span>
                                        <span class="flex-1"><a class="font-medium text-gray-900 hover:text-brand-600" href="{{ route('clients.deadlines', $deadline->client) }}">{{ $deadline->client->name }}</a> <span class="text-gray-600">· {{ $deadline->title }}</span></span>
                                        <span class="rounded-full px-2 py-0.5 text-xs {{ $deadline->side === 'client' ? 'bg-yellow-100 text-yellow-800' : 'bg-blue-100 text-blue-800' }}">{{ __(\App\Models\Deadline::SIDES[$deadline->side]) }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        </x-section>
                    @endif

                    @if ($waiting->isNotEmpty())
                        <x-section :title="__('Waiting for the client')" flush>
                            <ul class="divide-y divide-gray-200">
                                @foreach ($waiting as $review)
                                    <li class="{{ $item }}"><span class="flex-1"><a class="font-medium text-gray-900 hover:text-brand-600" href="{{ route('clients.reviews', $review->client) }}">{{ $review->client->name }}</a> <span class="text-gray-600">· {{ $review->pending_count }} {{ __('posts waiting') }}</span></span>
                                        <span class="{{ $review->due_at->isPast() ? $flag : 'text-gray-600' }}">{{ __('due') }} {{ $review->due_at->diffForHumans() }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        </x-section>
                    @endif

                    @if ($unanswered->isNotEmpty())
                        <x-section :title="__('Comments without a reply')" flush>
                            <ul class="divide-y divide-gray-200">
                                @foreach ($unanswered as $row)
                                    <li class="{{ $item }}"><a class="font-medium text-gray-900 hover:text-brand-600" href="{{ route('clients.comments', $row->client) }}">{{ $row->client->name }}</a> · {{ $row->n }}</li>
                                @endforeach
                            </ul>
                        </x-section>
                    @endif

                    @if ($tokens->isNotEmpty())
                        <x-section :title="__('Tokens expiring')" flush>
                            <ul class="divide-y divide-gray-200">
                                @foreach ($tokens as $account)
                                    <li class="{{ $item }}"><a class="font-medium text-gray-900 hover:text-brand-600" href="{{ route('clients.accounts', $account->client) }}">{{ $account->client->name }} {{ $account->platform }}</a> <span class="text-gray-600">· {{ $account->token_expires_at->isPast() ? __('expired') : __('expires').' '.$account->token_expires_at->diffForHumans() }}</span></li>
                                @endforeach
                            </ul>
                        </x-section>
                    @endif

                    @if ($failedJobs)
                        <p class="rounded-xl border border-gray-300 bg-white px-6 py-4"><a class="font-medium text-gray-900 hover:text-brand-600" href="{{ route('jobs.index') }}">{{ $failedJobs }} {{ __('failed jobs') }}</a></p>
                    @endif
                </div>
            </div>

            @if ($clients->isNotEmpty())
                <x-section :title="__('Per client, the next 7 days')" flush class="overflow-x-auto">
                    <table class="w-full text-left">
                        <thead class="text-xs uppercase tracking-wide text-gray-600">
                            <tr><th class="px-6 py-2 font-medium">{{ __('Client') }}</th><th class="px-6 py-2 font-medium">{{ __('Going out') }}</th><th class="px-6 py-2 font-medium">{{ __('Waiting for client') }}</th><th class="px-6 py-2 font-medium">{{ __('Not sent yet') }}</th>@if (config('features.publishing'))<th class="px-6 py-2 font-medium">{{ __('Failed') }}</th><th class="px-6 py-2 font-medium">{{ __('Comments to answer') }}</th>@endif</tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            @foreach ($clients as $client)
                                @php($cells = [[$week->where('client_id', $client->id)->count(), route('calendar', ['client' => $client->slug]), ''], [$waiting->where('client_id', $client->id)->sum('pending_count'), route('clients.reviews', $client), 'text-yellow-700'], [$drafts->where('client_id', $client->id)->count(), route('calendar', ['client' => $client->slug]), ''], ...(config('features.publishing') ? [[$stuck->where('client_id', $client->id)->count(), route('calendar', ['client' => $client->slug]), 'text-red-700'], [$unanswered->firstWhere('client_id', $client->id)?->n ?? 0, route('clients.comments', $client), 'text-red-700']] : [])])
                                <tr>
                                    <td class="px-6 py-2.5"><a class="font-medium text-gray-900 hover:text-brand-600" href="{{ route('calendar', ['client' => $client->slug]) }}">{{ $client->name }}</a></td>
                                    @foreach ($cells as [$n, $url, $color])
                                        <td class="px-6 py-2.5 tabular-nums {{ $n ? $color : 'text-gray-500' }}">@if ($n)<a class="hover:underline" href="{{ $url }}">{{ $n }}</a>@else 0 @endif</td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </x-section>
            @endif
        </div>
    </div>
</x-app-layout>
