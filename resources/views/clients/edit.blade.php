<x-app-layout :client="$client">
    <x-slot name="header">
        <h2 class="text-2xl font-semibold tracking-tight text-gray-900">{{ $client->name }} <span class="font-mono text-base text-gray-600">{{ $client->code }}</span></h2>
    </x-slot>
    <div class="py-6">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <style>[x-cloak] { display: none !important }</style>
            @php($tools = ['plan' => __('Plan with Claude'), 'import' => __('Import posts')])
            <div x-data="{ tab: localStorage.getItem('client-tab') || 'basics' }" x-init="if (! $el.querySelector(`[data-tab='${tab}']`)) tab = 'basics'; $watch('tab', t => localStorage.setItem('client-tab', t))" class="md:flex md:gap-8">
                @include('clients._nav', ['tools' => $tools])
                <div class="min-w-0 flex-1 space-y-6">
                    <form method="POST" action="{{ route('clients.update', $client) }}">
                        @method('PATCH')
                        @include('clients._form')
                    </form>
                    <form method="POST" action="{{ route('clients.destroy', $client) }}" x-show="tab === 'basics'" x-cloak class="text-right" onsubmit="return confirm('{{ __('Delete this client and everything it owns?') }}')">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="text-sm text-red-600 hover:underline">{{ __('Delete this client') }}</button>
                    </form>

                    <div x-show="tab === 'plan'" x-cloak class="space-y-6">
                    @if (config('features.claude_api'))
                        <x-section :title="__('Post from a prompt')" :description="__('Write what the post should be about. Claude combines your words with the brand profile and the latest trend research and puts one post on the calendar as a Draft, with captions in both languages. You get an email with the link; from the post page you can download it or send it for approval.')">
                            <div class="py-5">
                                @if (session('status') === 'post-started')
                                    <p class="mb-3 text-sm text-green-600">{{ __('The post is being written. It appears on the calendar as a Draft in a minute or two, a few minutes more when the trends are researched again.') }}</p>
                                @endif
                                @if ($accounts === [])
                                    <p class="text-sm text-red-600">{{ __('Fill in at least one account handle in the profile above and save first.') }}</p>
                                @else
                                    <form method="POST" action="{{ route('clients.draft', $client) }}" class="grid gap-4 sm:grid-cols-4">
                                        @csrf
                                        <div class="sm:col-span-4">
                                            <x-input-label for="instruction" :value="__('What the post is about')" />
                                            <textarea id="instruction" name="instruction" rows="3" class="mt-1 block w-full border-gray-300 focus:border-brand-500 focus:ring-brand-500 rounded-md shadow-sm" placeholder="{{ __('e.g. A Reel about the sunset cocktails this weekend, using whatever is trending on Instagram right now') }}" required>{{ old('instruction') }}</textarea>
                                        </div>
                                        <div>
                                            <x-input-label for="date" :value="__('Date (optional, otherwise tomorrow)')" />
                                            <x-text-input id="date" name="date" type="date" class="mt-1 block w-full" :value="old('date')" min="{{ now()->toDateString() }}" />
                                        </div>
                                        <div class="sm:col-span-2">
                                            <x-input-label :value="__('Platforms')" />
                                            @foreach ($accounts as $platform)
                                                <label class="block text-sm mt-1"><input type="checkbox" name="platforms[]" value="{{ $platform }}" class="rounded border-gray-300 text-brand-600 focus:ring-brand-500" @checked(in_array($platform, old('platforms', $accounts)))> {{ ucfirst(str_replace('_', ' ', $platform)) }}</label>
                                            @endforeach
                                        </div>
                                        <div>
                                            <label class="flex items-start gap-2 text-sm"><input type="checkbox" name="fresh" value="1" class="mt-1 rounded border-gray-300 text-brand-600 focus:ring-brand-500" @checked(old('fresh'))> {{ __('Research the trends again (otherwise the brief from the last 7 days is used)') }}</label>
                                        </div>
                                        <details class="sm:col-span-4">
                                            <summary class="cursor-pointer text-sm text-gray-600">{{ __('Paste your own trend brief instead (optional)') }}</summary>
                                            <textarea name="brief" rows="3" class="mt-2 block w-full border-gray-300 focus:border-brand-500 focus:ring-brand-500 rounded-md shadow-sm">{{ old('brief') }}</textarea>
                                        </details>
                                        <div class="sm:col-span-4">
                                            <x-primary-button>{{ __('Write the post') }}</x-primary-button>
                                            <x-input-error class="mt-2" :messages="$errors->draft->all()" />
                                        </div>
                                    </form>
                                @endif
                            </div>
                        </x-section>

                        <x-section :title="__('Plan with Claude')" :description="__('Claude researches current trends for this client, follows the brand profile and puts the posts on the calendar as Drafts with captions in both languages. It takes a few minutes; you get an email when it is done. Nothing reaches the client without your review.')">
                            <div class="py-5">
                                @if (session('status') === 'plan-started')
                                    <p class="mb-3 text-sm text-green-600">{{ __('Plan started. The Drafts appear on the calendar in a few minutes.') }}</p>
                                @endif
                                @if ($accounts === [])
                                    <p class="text-sm text-red-600">{{ __('Fill in at least one account handle in the profile above and save first.') }}</p>
                                @else
                                    <form method="POST" action="{{ route('clients.plan', $client) }}" class="grid gap-4 sm:grid-cols-4">
                                        @csrf
                                        <div>
                                            <x-input-label for="posts" :value="__('Posts')" />
                                            <x-text-input id="posts" name="posts" type="number" min="1" max="{{ \App\Planning\PlanRequest::MAX_POSTS }}" class="mt-1 block w-full" :value="old('posts', 8)" />
                                        </div>
                                        <div>
                                            <x-input-label for="from" :value="__('From')" />
                                            <x-text-input id="from" name="from" type="date" class="mt-1 block w-full" :value="old('from', now()->addDay()->toDateString())" />
                                        </div>
                                        <div>
                                            <x-input-label for="days" :value="__('Days')" />
                                            <x-text-input id="days" name="days" type="number" min="1" max="92" class="mt-1 block w-full" :value="old('days', 30)" />
                                        </div>
                                        <div>
                                            <x-input-label :value="__('Platforms')" />
                                            @foreach ($accounts as $platform)
                                                <label class="block text-sm mt-1"><input type="checkbox" name="platforms[]" value="{{ $platform }}" class="rounded border-gray-300 text-brand-600 focus:ring-brand-500" @checked(in_array($platform, old('platforms', $accounts)))> {{ ucfirst(str_replace('_', ' ', $platform)) }}</label>
                                            @endforeach
                                        </div>
                                        <div class="sm:col-span-4">
                                            <x-input-label for="brief" :value="__('Trend brief (optional)')" />
                                            <textarea id="brief" name="brief" rows="3" class="mt-1 block w-full border-gray-300 focus:border-brand-500 focus:ring-brand-500 rounded-md shadow-sm" placeholder="{{ __('Paste a trend brief here to skip the web research') }}">{{ old('brief') }}</textarea>
                                        </div>
                                        <div class="sm:col-span-4">
                                            <x-primary-button>{{ __('Plan with Claude') }}</x-primary-button>
                                            <x-input-error class="mt-2" :messages="$errors->all()" />
                                        </div>
                                    </form>
                                @endif
                            </div>
                        </x-section>
                    @else
                        @php($mcpDescription = __('Claude plans the posts with their captions and puts them on the calendar as Drafts, with a review sheet in Reports. Press the button and Claude does it at its next run, once an hour.'))
                        <x-section :title="__('Plan with Claude')" :description="$mcpDescription">
                            <div class="py-5" x-data="{ posts: 12, from: '{{ now()->addMonthNoOverflow()->startOfMonth()->toDateString() }}', days: 30, instruction: '', copied: false,
                                text() { return `{{ __('Plan :posts posts for :client (client ":slug") from :from for :days days.', ['posts' => '${this.posts}', 'client' => $client->name, 'slug' => $client->slug, 'from' => '${this.from}', 'days' => '${this.days}']) }}` + (this.instruction.trim() ? ' {{ __('About:') }} ' + this.instruction.trim() + '.' : '') + ` {{ __('Use the Social Ops tools: get-planning-brief first, research the current trends for this client (the last30days skill or web search), plan the posts and write the captions in :languages, then save-plan. Publish nothing.', ['languages' => strtoupper(implode(' + ', array_merge($client->profile['languages'], $client->profile['extra_languages'] ?? [])))]) }}`; },
                                copy() { navigator.clipboard.writeText(this.text()).then(() => { this.copied = true; setTimeout(() => this.copied = false, 2000) }) } }">
                                @if (session('status') === 'job-queued')
                                    <p class="mb-4 rounded-lg bg-green-50 px-4 py-3 text-sm font-semibold text-green-800" role="status">{{ __('Queued. Claude picks it up at its next run; the Drafts and the review sheet land in Reports.') }}</p>
                                @elseif (session('status') === 'job-already-waiting')
                                    <p class="mb-4 rounded-lg bg-yellow-100 px-4 py-3 text-sm font-semibold text-yellow-800" role="status">{{ __('Already waiting for Claude (queued :time)', ['time' => session('queued_at')]) }}</p>
                                @elseif (session('status') === 'plan-request-cancelled')
                                    <p class="mb-4 rounded-lg bg-green-50 px-4 py-3 text-sm font-semibold text-green-800" role="status">{{ __('Plan request cancelled') }}.</p>
                                @endif
                                <form method="POST" action="{{ route('clients.queue', $client) }}" class="grid gap-4 sm:grid-cols-4">
                                    @csrf
                                    <div><x-input-label for="mcp-posts" :value="__('Posts')" /><x-text-input id="mcp-posts" name="posts" type="number" min="1" max="{{ \App\Planning\PlanRequest::MAX_POSTS }}" class="mt-1 block w-full" x-model="posts" required /></div>
                                    <div><x-input-label for="mcp-from" :value="__('From')" /><x-text-input id="mcp-from" name="from" type="date" class="mt-1 block w-full" x-model="from" required /></div>
                                    <div><x-input-label for="mcp-days" :value="__('Days')" /><x-text-input id="mcp-days" name="days" type="number" min="1" max="92" class="mt-1 block w-full" x-model="days" required /></div>
                                    <div class="sm:col-span-4"><x-input-label for="mcp-instruction" :value="__('What the posts are about (optional)')" /><textarea id="mcp-instruction" name="instruction" rows="2" class="mt-1 block w-full border-gray-300 focus:border-brand-500 focus:ring-brand-500 rounded-md shadow-sm" x-model="instruction" placeholder="{{ __('e.g. December: gifts, Christmas opening hours, the New Year party') }}"></textarea></div>
                                    <div class="sm:col-span-4 flex gap-2"><x-primary-button>{{ __('Queue for Claude') }}</x-primary-button><x-secondary-button type="button" x-on:click="copy()"><span x-text="copied ? '{{ __('Copied') }}' : '{{ __('Copy the request') }}'"></span></x-secondary-button></div>
                                    <x-input-error class="sm:col-span-4" :messages="$errors->job->all()" />
                                </form>
                                {{-- the client's Drive folder (the Files tab) is part of the brief get-planning-brief hands Claude --}}
                                <p class="mt-4 text-sm text-gray-600">
                                    <a href="{{ route('clients.files', $client) }}" class="font-medium text-gray-900 hover:text-brand-600">
                                        @if ($documents)
                                            {{ __('Claude also reads :n files from the client\'s Drive folder (:m with previous posts).', ['n' => $documents['read'], 'm' => $documents['previous']]) }}
                                            @if ($documents['designs'] || $documents['waiting'])
                                                {{ __('And :n past designs it described, :m still waiting.', ['n' => $documents['designs'], 'm' => $documents['waiting']]) }}
                                            @endif
                                        @else
                                            {{ __('Link the client\'s Drive folder on the Files tab, so Claude reads the previous posts and the reference files.') }}
                                        @endif
                                    </a>
                                </p>
                                @if ($claudeJobs->isNotEmpty())
                                    <h4 class="mt-6 text-sm font-semibold text-gray-900">{{ __('Waiting for Claude') }}</h4>
                                    <ul class="mt-2 divide-y divide-gray-200 rounded-lg border border-gray-300 text-sm">
                                        @foreach ($claudeJobs as $job)
                                            <li class="flex flex-wrap items-baseline gap-x-3 gap-y-1 px-4 py-2.5">
                                                <span class="min-w-0 flex-1"><span class="font-medium text-gray-900">{{ \App\Planning\ClaudeQueue::describe($job) }}</span>
                                                    @if (filled($job->meta['instruction'] ?? null))<span class="block text-gray-600">{{ __('About:') }} {{ $job->meta['instruction'] }}</span>@endif
                                                </span>
                                                <span class="tabular-nums text-gray-600">{{ __('queued :time', ['time' => $job->created_at->format('d-m-Y H:i')]) }} · {{ $job->created_at->diffForHumans() }}</span>
                                                @if (\App\Planning\ClaudeQueue::isLate($job))<span class="rounded-full bg-red-100 px-2 py-0.5 text-xs text-red-800">{{ __('over 2 hours') }}</span>@endif
                                                <form method="POST" action="{{ route('claude.cancel', $job) }}">@csrf @method('DELETE')<button class="font-medium text-gray-900 hover:text-brand-600">{{ __('Cancel') }}</button></form>
                                            </li>
                                        @endforeach
                                    </ul>
                                @endif
                            </div>
                        </x-section>
                    @endif
                    </div>

                    @php($importDescription = __('One row per post, the same columns as the calendar\'s CSV export. Every row becomes a Draft on the calendar; if any row is wrong, nothing is imported and the errors are listed here.'))
                    <x-section x-show="tab === 'import'" x-cloak :title="__('Import posts from a spreadsheet')" :description="$importDescription">
                        <div class="py-5">
                            <form method="POST" action="{{ route('posts.import', $client) }}" enctype="multipart/form-data" class="flex flex-wrap items-center gap-3">
                                @csrf
                                <input type="file" name="csv" accept=".csv,text/csv" required class="text-sm" />
                                <x-primary-button>{{ __('Import') }}</x-primary-button>
                                <a href="{{ route('posts.import.template', $client) }}" class="text-sm font-medium text-gray-900 hover:text-brand-600">{{ __('Download the template') }}</a>
                            </form>
                            <x-input-error class="mt-2" :messages="$errors->get('csv')" />
                        </div>
                    </x-section>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
