@csrf
@php($profile = $client->exists ? $client->profile : \App\Clients\ClientProfile::template())
@php($rows = fn ($items) => array_merge(array_values($items), [[]]))
@php($input = 'block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500')
@php($check = 'rounded border-gray-300 text-brand-600 focus:ring-brand-500')
@php($sub = 'mb-1 block text-xs font-medium text-gray-600')
@php($p = fn ($key, $default = '') => old('p.'.$key, data_get($profile, $key, $default)))
@php($text = fn ($key, $glue = ', ') => is_array($v = $p($key)) ? implode($glue, $v) : (string) $v)
@php($flag = fn ($key) => $errors->any() ? (bool) old('p.'.$key) : (bool) data_get($profile, $key))
@php($platforms = ['instagram' => 'Instagram', 'facebook' => 'Facebook', 'tiktok' => 'TikTok', 'linkedin' => 'LinkedIn'])

@if ($errors->any())
    <div class="mb-4 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700">
        <p class="font-medium">{{ __('Please check these and save again:') }}</p>
        <ul class="mt-1 list-disc pl-5">@foreach ($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul>
    </div>
@endif

<x-section x-show="tab === 'basics'" :title="__('Basics')" :description="__('Who the client is, the accounts and the caption languages.')">
    <x-field :label="__('Name')" for="name">
        <x-text-input id="name" name="name" type="text" class="block w-full text-sm" :value="old('name', $client->name)" required />
    </x-field>
    <x-field :label="__('Type and location')" for="type">
        <div class="grid gap-3 sm:grid-cols-2">
            <x-text-input id="type" name="type" type="text" list="client-types" class="block w-full text-sm" :value="old('type', str_replace('_', ' ', (string) $client->type))" placeholder="{{ __('villa, hotel, pharmacy, anything') }}" required aria-label="{{ __('Type') }}" />
            <datalist id="client-types">
                @foreach (\App\Models\Client::TYPES as $type)
                    <option value="{{ __(str_replace('_', ' ', $type)) }}"></option>
                @endforeach
            </datalist>
            <x-text-input id="location" name="location" type="text" class="block w-full text-sm" :value="old('location', $client->location)" placeholder="Chania, Crete" required aria-label="{{ __('Location') }}" />
        </div>
    </x-field>
    <x-field :label="__('Name in links and code in file names')" for="slug" :help="__('Made from the name when left empty. Changing the link name later breaks links already sent.')">
        <div class="grid gap-3 sm:grid-cols-2">
            <x-text-input id="slug" name="slug" type="text" class="block w-full font-mono text-sm" :value="old('slug', $client->slug)" placeholder="almyra-beach-lounge" />
            <x-text-input id="code" name="code" type="text" class="block w-full font-mono text-sm uppercase" :value="old('code', $client->code)" placeholder="ALMYRA" aria-label="{{ __('In file and post names') }}" />
        </div>
    </x-field>
    <x-field :label="__('Accounts')" :help="__('The name of each account as the client knows it. A client can have more than one on a platform: + adds a line.')">
        <div class="grid gap-3 sm:grid-cols-2">
            @foreach ($platforms as $platform => $label)
                @php($handles = array_values(array_filter(array_map(fn ($h) => trim((string) $h), (array) $p('accounts.'.$platform, [])), 'strlen')) ?: [''])
                <div x-data="{ rows: @js($handles) }">
                    <label for="p-accounts-{{ $platform }}-0" class="{{ $sub }}">{{ $label }}</label>
                    <template x-for="(row, i) in rows" :key="i">
                        <x-text-input ::id="'p-accounts-{{ $platform }}-' + i" name="p[accounts][{{ $platform }}][]" type="text" class="mb-1 block w-full text-sm" x-model="rows[i]" placeholder="@almyra.chania" />
                    </template>
                    <button type="button" class="text-xs font-medium text-gray-600 hover:text-gray-900" x-on:click="rows.push('')">+ {{ __('Add another account') }}</button>
                </div>
            @endforeach
        </div>
    </x-field>
    <x-field :label="__('Languages')" for="p-languages" :help="__('Two-letter codes, the main one first (e.g. el, en). Extra languages are drafted only on request.')">
        <div class="grid gap-3 sm:grid-cols-2">
            <div>
                <label for="p-languages" class="{{ $sub }}">{{ __('Caption languages') }}</label>
                <x-text-input id="p-languages" name="p[languages]" type="text" class="block w-full text-sm" :value="$text('languages')" required />
            </div>
            <div>
                <label for="p-extra" class="{{ $sub }}">{{ __('Extra languages') }}</label>
                <x-text-input id="p-extra" name="p[extra_languages]" type="text" class="block w-full text-sm" :value="$text('extra_languages')" placeholder="de" />
            </div>
        </div>
    </x-field>
</x-section>

<x-section x-show="tab === 'about'" x-cloak :title="__('About the client')" :description="__('What Claude plans and writes from. Every price, dish, service and event in a post comes from here or from the facts under Voice, so the more there is, the more accurate the posts.')">
    <x-field :label="__('What it is')" for="p-about-description" :help="__('The place in a few sentences: what it is, where, since when, who runs it.')">
        <textarea id="p-about-description" name="p[about][description]" rows="4" class="{{ $input }}">{{ $p('about.description') }}</textarea>
    </x-field>
    <x-field :label="__('Who the guests are')" for="p-about-audience" :help="__('Countries, ages, couples or families, budget, locals or visitors.')">
        <textarea id="p-about-audience" name="p[about][audience]" rows="3" class="{{ $input }}">{{ $p('about.audience') }}</textarea>
    </x-field>
    <x-field :label="__('What it offers')" for="p-about-offerings" :help="__('Rooms, menu highlights, services, prices worth naming.')">
        <textarea id="p-about-offerings" name="p[about][offerings]" rows="4" class="{{ $input }}">{{ $p('about.offerings') }}</textarea>
    </x-field>
    <x-field :label="__('What makes it different')" for="p-about-differentiators" :help="__('What a guest gets here and nowhere else.')">
        <textarea id="p-about-differentiators" name="p[about][differentiators]" rows="3" class="{{ $input }}">{{ $p('about.differentiators') }}</textarea>
    </x-field>
    <x-field :label="__('What the client wants now')" for="p-about-goals" :help="__('Bookings, a new menu, awareness, a season to fill.')">
        <textarea id="p-about-goals" name="p[about][goals]" rows="3" class="{{ $input }}">{{ $p('about.goals') }}</textarea>
    </x-field>
    <x-field :label="__('On now')" for="p-about-current" :help="__('Offers, events and seasonal things with their dates. Update it as the season moves.')">
        <textarea id="p-about-current" name="p[about][current]" rows="4" class="{{ $input }}">{{ $p('about.current') }}</textarea>
    </x-field>
</x-section>

<x-section x-show="tab === 'agency'" x-cloak :title="__('Agency')" :description="__('How the team tracks this client on the Clients board. The client never sees these.')">
    <x-field :label="__('Status and person')" for="status">
        <div class="grid gap-3 sm:grid-cols-2">
            <select id="status" name="status" class="{{ $input }}">
                @foreach (\App\Models\Client::STATUSES as $value => $label)<option value="{{ $value }}" @selected(old('status', $client->status ?? 'active') === $value)>{{ __($label) }}</option>@endforeach
            </select>
            <x-text-input id="owner" name="owner" type="text" list="staff-names" class="block w-full text-sm" :value="old('owner', $client->owner)" placeholder="{{ __('Person') }}" aria-label="{{ __('Person') }}" />
            <datalist id="staff-names">@foreach (\App\Models\User::orderBy('name')->pluck('name') as $name)<option value="{{ $name }}">@endforeach</datalist>
        </div>
    </x-field>
    <x-field :label="__('Priority and next plan due')" for="priority">
        <div class="grid gap-3 sm:grid-cols-2">
            <select id="priority" name="priority" class="{{ $input }}">
                <option value="">—</option>
                @foreach (\App\Models\Client::PRIORITIES as $value => $label)<option value="{{ $value }}" @selected(old('priority', $client->priority) === $value)>{{ __($label) }}</option>@endforeach
            </select>
            <x-text-input id="plan_due" name="plan_due" type="date" class="block w-full text-sm" :value="old('plan_due', $client->plan_due?->toDateString())" aria-label="{{ __('Next plan due') }}" />
        </div>
    </x-field>
    <x-field :label="__('Rhythm')" for="posts_per_week" :help="__('What the contract says: posts and stories per week, TikToks per month.')">
        <div class="grid grid-cols-3 gap-3">
            <div><label for="posts_per_week" class="{{ $sub }}">{{ __('Posts per week') }}</label><x-text-input id="posts_per_week" name="posts_per_week" type="number" min="0" max="31" class="block w-full text-sm" :value="old('posts_per_week', $client->posts_per_week)" /></div>
            <div><label for="stories_per_week" class="{{ $sub }}">{{ __('Stories per week') }}</label><x-text-input id="stories_per_week" name="stories_per_week" type="number" min="0" max="31" class="block w-full text-sm" :value="old('stories_per_week', $client->stories_per_week)" /></div>
            <div><label for="tiktoks_per_month" class="{{ $sub }}">{{ __('TikToks per month') }}</label><x-text-input id="tiktoks_per_month" name="tiktoks_per_month" type="number" min="0" max="31" class="block w-full text-sm" :value="old('tiktoks_per_month', $client->tiktoks_per_month)" /></div>
        </div>
    </x-field>
    <x-field :label="__('Pending')" for="pending" :help="__('What we are waiting for right now, as shown on the Clients board.')">
        <textarea id="pending" name="pending" rows="2" class="{{ $input }}" placeholder="{{ __('e.g. 9 posts sent to the client, waiting for approval') }}">{{ old('pending', $client->pending) }}</textarea>
    </x-field>
    <x-field :label="__('Links')" :help="__('Links the team opens often (the Drive folder, the posts presentation).')">
        @foreach ($rows(old('links', $client->links ?? [])) as $i => $row)
            <div class="grid grid-cols-3 gap-2 {{ $i ? 'mt-2' : '' }}">
                <x-text-input name="links[{{ $i }}][label]" type="text" class="block w-full text-sm" :value="$row['label'] ?? ''" placeholder="{{ __('Posts') }}" />
                <x-text-input name="links[{{ $i }}][url]" type="url" pattern="https://.*" class="col-span-2 block w-full font-mono text-sm" :value="$row['url'] ?? ''" placeholder="https://docs.google.com/presentation/d/…" />
            </div>
        @endforeach
    </x-field>
</x-section>

<x-section x-show="tab === 'voice'" x-cloak :title="__('Voice')" :description="__('How the captions sound. Claude follows this on every post, reply and report.')">
    <x-field :label="__('Tone')" for="p-tone">
        <x-text-input id="p-tone" name="p[voice][tone]" type="text" class="block w-full text-sm" :value="$p('voice.tone')" placeholder="{{ __('relaxed, warm, confident; premium without sounding formal') }}" required />
    </x-field>
    <x-field :label="__('Speak as')" for="p-person" :help="__('We, or the name of the place.')">
        <x-text-input id="p-person" name="p[voice][person]" type="text" class="block w-full text-sm" :value="$p('voice.person')" placeholder="{{ __('we, or the name of the place') }}" required />
    </x-field>
    <x-field :label="__('Emoji')" for="p-emoji">
        <x-text-input id="p-emoji" name="p[voice][emoji]" type="text" class="block w-full text-sm" :value="$p('voice.emoji')" placeholder="{{ __('max 1 per caption, never in the first line') }}" required />
    </x-field>
    <x-field :label="__('Call to action')" for="p-cta">
        <x-text-input id="p-cta" name="p[voice][cta_style]" type="text" class="block w-full text-sm" :value="$p('voice.cta_style')" placeholder="{{ __('soft (book a table, link in bio), never pushy') }}" required />
    </x-field>
    <x-field :label="__('Facts to keep consistent')" for="p-facts" :help="__('One per line: hours, season, how to book.')">
        <textarea id="p-facts" name="p[voice][facts]" rows="3" class="{{ $input }}" placeholder="{{ __('Open daily 10:00 to midnight, May to October') }}">{{ $text('voice.facts', "\n") }}</textarea>
    </x-field>
    <x-field :label="__('Phrases never to use')" for="p-banned" :help="__('One per line.')">
        <textarea id="p-banned" name="p[voice][banned_phrases]" rows="3" class="{{ $input }}">{{ $text('voice.banned_phrases', "\n") }}</textarea>
    </x-field>
    <x-field :label="__('Words and phrases to use')" for="p-words" :help="__('One per line: how the client names the place, the dishes, the rooms. Claude uses them when they fit, not in every post.')">
        <textarea id="p-words" name="p[voice][words]" rows="3" class="{{ $input }}">{{ $text('voice.words', "\n") }}</textarea>
    </x-field>
    <x-field :label="__('Extra information')" for="p-notes" :help="__('Anything else the team and Claude should know: the story of the place, what the owner cares about, what to avoid.')">
        <textarea id="p-notes" name="p[notes]" rows="5" class="{{ $input }}">{{ $p('notes') }}</textarea>
    </x-field>
</x-section>

<x-section x-show="tab === 'content'" x-cloak :title="__('Content')" :description="__('The topics the posts rotate through, the hashtags and the text added to every post.')">
    <x-field :label="__('Content pillars')" :help="__('The share of posts each topic gets; the shares add up to 100. Optional: without pillars Claude picks the topics from About the client, the season and the trends.')">
        @php($pillars = old('p.pillars', collect($profile['pillars'] ?? [])->map(fn ($share, $name) => ['name' => str_replace('_', ' ', $name), 'share' => $share])->values()->all()))
        <div class="grid gap-2 sm:grid-cols-2">
            @foreach ($rows($pillars) as $i => $row)
                <div class="flex gap-2">
                    <x-text-input name="p[pillars][{{ $i }}][name]" type="text" class="block w-full text-sm" :value="$row['name'] ?? ''" maxlength="{{ \App\Clients\ClientProfile::PILLAR_MAX }}" placeholder="{{ __('food and drink') }}" />
                    <x-text-input name="p[pillars][{{ $i }}][share]" type="number" min="0" max="100" class="w-20 text-sm" :value="$row['share'] ?? ''" placeholder="%" />
                </div>
            @endforeach
        </div>
        @if ($client->exists && $client->profile['pillars'])
            @php($mix = \App\Planning\PillarMix::for($client))
            <p class="mt-2 text-xs text-gray-600">{{ __('Posts per pillar, :days days back and ahead (:n posts):', ['days' => \App\Planning\PillarMix::DAYS, 'n' => $mix['posts']]) }}
                @foreach ($mix['rows'] as $pillar => $row)<span class="{{ in_array($pillar, $mix['drifted']) ? 'font-medium text-red-600' : '' }}">{{ str_replace('_', ' ', $pillar) }} {{ $row['actual'] }}%</span>@if (! $loop->last) · @endif @endforeach
            </p>
        @endif
    </x-field>
    <x-field :label="__('Hashtags')" for="p-always" :help="__('The ones on every post, the pool Claude picks the rest from, and how many per post at most.')">
        <div class="grid gap-3 sm:grid-cols-2">
            <div>
                <label for="p-always" class="{{ $sub }}">{{ __('On every post') }}</label>
                <x-text-input id="p-always" name="p[hashtags][always]" type="text" class="block w-full text-sm" :value="$text('hashtags.always', ' ')" placeholder="#chania #crete" />
            </div>
            <div>
                <label for="p-max" class="{{ $sub }}">{{ __('Per post, at most') }}</label>
                <x-text-input id="p-max" name="p[hashtags][max_per_post]" type="number" min="0" max="30" class="block w-28 text-sm" :value="$p('hashtags.max_per_post', 5)" required />
            </div>
            <div class="sm:col-span-2">
                <label for="p-pool" class="{{ $sub }}">{{ __('Pool Claude picks from') }}</label>
                <textarea id="p-pool" name="p[hashtags][pool]" rows="2" class="{{ $input }}" placeholder="#chaniabeach #cretesummer">{{ $text('hashtags.pool', ' ') }}</textarea>
            </div>
        </div>
    </x-field>
    <x-field :label="__('Links in captions')">
        <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="p[utm]" value="1" class="{{ $check }}" @checked($flag('utm'))> {{ __('Tag links so Google Analytics shows which post brought the visit') }}</label>
    </x-field>
    <x-field :label="__('Signature line')" :help="__('Added under every caption of that platform at publish time. Optional.')">
        <div class="grid gap-3 sm:grid-cols-2">
            @foreach ($platforms as $platform => $label)
                <div>
                    <label for="p-signature-{{ $platform }}" class="{{ $sub }}">{{ $label }}</label>
                    <x-text-input id="p-signature-{{ $platform }}" name="p[signatures][{{ $platform }}]" type="text" class="block w-full text-sm" :value="$p('signatures.'.$platform)" />
                </div>
            @endforeach
        </div>
    </x-field>
    <x-field :label="__('Placeholders')" :help="__('Written in captions as :tag and filled at publish time.', ['tag' => '{'.'{phone}}'])">
        @foreach ($rows(collect(old('p.fields', collect($profile['fields'] ?? [])->map(fn ($v, $k) => ['key' => $k, 'value' => $v])->values()->all()))->all()) as $i => $row)
            <div class="grid grid-cols-3 gap-2 {{ $i ? 'mt-2' : '' }}">
                <x-text-input name="p[fields][{{ $i }}][key]" type="text" class="block w-full font-mono text-sm" :value="$row['key'] ?? ''" placeholder="phone" />
                <x-text-input name="p[fields][{{ $i }}][value]" type="text" class="col-span-2 block w-full text-sm" :value="$row['value'] ?? ''" placeholder="+30 28210 00000" />
            </div>
        @endforeach
    </x-field>
</x-section>

<x-section x-show="tab === 'approval'" x-cloak :title="__('Approval')" :description="__('Who approves the posts and how long the client has to answer.')">
    <x-field :label="__('Policy')" for="p-policy" :help="__('The hours are how long the client has to answer before a reminder.')">
        <div class="flex gap-2">
            <select id="p-policy" name="p[approval][policy]" class="{{ $input }}">
                @foreach (['none' => __('None: staff approve'), 'optional' => __('Optional: the client may answer'), 'required' => __('Required: the client must approve'), 'multi_level' => __('Multi-level')] as $value => $label)
                    <option value="{{ $value }}" @selected($p('approval.policy', 'required') === $value)>{{ $label }}</option>
                @endforeach
            </select>
            <x-text-input name="p[approval][sla_hours]" type="number" min="1" max="720" class="w-24 text-sm" :value="$p('approval.sla_hours', 48)" title="{{ __('Hours the client has to answer') }}" required />
        </div>
        <label class="mt-3 flex items-center gap-2 text-sm"><input type="checkbox" name="p[approval][auto_approve_on_timeout]" value="1" class="{{ $check }}" @checked($flag('approval.auto_approve_on_timeout'))> {{ __('Approve by itself when the client does not answer in time (only with "Optional")') }}</label>
        <label class="mt-2 flex items-center gap-2 text-sm"><input type="checkbox" name="p[approval][email_monthly_report]" value="1" class="{{ $check }}" @checked($flag('approval.email_monthly_report'))> {{ __('Email every monthly report to the approvers as soon as it is written') }}</label>
    </x-field>
    <x-field :label="__('Approvers')" :help="__('Who gets the approval links: name, email, phone for reminders.')">
        @foreach ($rows(old('p.approval.approvers', $profile['approval']['approvers'] ?? [])) as $i => $row)
            <div class="grid grid-cols-3 gap-2 {{ $i ? 'mt-2' : '' }}">
                <x-text-input name="p[approval][approvers][{{ $i }}][name]" type="text" class="block w-full text-sm" :value="$row['name'] ?? ''" placeholder="{{ __('Name') }}" />
                <x-text-input name="p[approval][approvers][{{ $i }}][email]" type="email" class="block w-full text-sm" :value="$row['email'] ?? ''" placeholder="email" />
                <x-text-input name="p[approval][approvers][{{ $i }}][phone]" type="text" class="block w-full text-sm" :value="$row['phone'] ?? ''" placeholder="+30 …" />
            </div>
        @endforeach
    </x-field>
</x-section>

<x-section x-show="tab === 'timing'" x-cloak :title="__('Timing')" :description="__('When the posts go out and the dates the plan should build around.')">
    @php($days = ['mon' => __('Monday'), 'tue' => __('Tuesday'), 'wed' => __('Wednesday'), 'thu' => __('Thursday'), 'fri' => __('Friday'), 'sat' => __('Saturday'), 'sun' => __('Sunday')])
    @php($slots = old('p.slots', array_map(fn ($s) => array_combine(['day', 'time'], explode(' ', $s) + [1 => '']), $profile['slots'] ?? [])))
    <x-field :label="__('Weekly posting slots')" :help="__('The post form suggests the next free one and the planner dates the posts on them.')">
        @foreach ($rows($slots) as $i => $row)
            <div class="flex gap-2 {{ $i ? 'mt-2' : '' }}">
                <select name="p[slots][{{ $i }}][day]" class="{{ $input }} w-40">
                    <option value="">—</option>
                    @foreach ($days as $value => $label)<option value="{{ $value }}" @selected(strtolower($row['day'] ?? '') === $value)>{{ $label }}</option>@endforeach
                </select>
                <x-text-input name="p[slots][{{ $i }}][time]" type="time" class="text-sm" :value="$row['time'] ?? ''" />
            </div>
        @endforeach
        @if ($client->exists && ($best = \App\Planning\BestTimes::for($client)))
            <p class="mt-2 text-xs text-gray-600">{{ __('Best hours from our numbers of the last :days days: :list', ['days' => \App\Planning\BestTimes::DAYS, 'list' => collect($best)->map(fn ($s) => $days[$s['day']].' '.sprintf('%02d:00', $s['hour']).' ('.__(':n posts, :avg interactions each', ['n' => $s['posts'], 'avg' => $s['avg']]).')')->join(', ')]) }}</p>
        @endif
    </x-field>
    <x-field :label="__('Key dates')" :help="__('Openings, events, anniversaries: marked on the calendar and given to the planner.')">
        @foreach ($rows(old('p.key_dates', $profile['key_dates'] ?? [])) as $i => $row)
            <div class="flex gap-2 {{ $i ? 'mt-2' : '' }}">
                <x-text-input name="p[key_dates][{{ $i }}][date]" type="date" class="text-sm" :value="$row['date'] ?? ''" />
                <x-text-input name="p[key_dates][{{ $i }}][label]" type="text" class="block w-full text-sm" :value="$row['label'] ?? ''" placeholder="{{ __('Season opening') }}" />
            </div>
        @endforeach
    </x-field>
</x-section>

<x-section x-show="tab === 'research'" x-cloak :title="__('Research')" :description="__('Steers the trend research, the planner and the weekly listening report.')">
    <x-field :label="__('Sites to search first')" for="p-sources" :help="__('One per line.')">
        <textarea id="p-sources" name="p[research][sources]" rows="2" class="{{ $input }}" placeholder="cretalive.gr">{{ $text('research.sources', "\n") }}</textarea>
    </x-field>
    <x-field :label="__('Sites never to cite')" for="p-excluded-domains" :help="__('One per line.')">
        <textarea id="p-excluded-domains" name="p[research][excluded_domains]" rows="2" class="{{ $input }}">{{ $text('research.excluded_domains', "\n") }}</textarea>
    </x-field>
    <x-field :label="__('Topics never to post about')" for="p-excluded-topics" :help="__('One per line.')">
        <textarea id="p-excluded-topics" name="p[research][excluded_topics]" rows="2" class="{{ $input }}" placeholder="{{ __('politics') }}">{{ $text('research.excluded_topics', "\n") }}</textarea>
    </x-field>
    <x-field :label="__('Watch every week')" for="p-watch" :help="__('Hashtags (up to 30) and competitor Instagram accounts for the listening report.')">
        <div class="grid gap-3 sm:grid-cols-2">
            <x-text-input id="p-watch" name="p[research][hashtags_to_watch]" type="text" class="block w-full text-sm" :value="$text('research.hashtags_to_watch', ' ')" placeholder="chaniabeach cretefood" />
            <x-text-input id="p-competitors" name="p[research][competitors]" type="text" class="block w-full text-sm" :value="$text('research.competitors', ' ')" placeholder="@otherbeachbar" aria-label="{{ __('Competitor Instagram accounts to watch') }}" />
        </div>
    </x-field>
</x-section>

<x-section x-show="tab === 'more'" x-cloak :title="__('Replies and webhooks')" :description="__('Answers you give often, and the addresses developers connect.')">
    <x-field :label="__('Saved replies')" :help="__('Label, text, and the keywords that trigger it on a new Instagram or Facebook comment. An empty row is ignored; save to get a new empty row.')">
        @foreach ($rows(old('p.saved_replies', $profile['saved_replies'] ?? [])) as $i => $row)
            <div class="grid gap-2 sm:grid-cols-6 {{ $i ? 'mt-2' : '' }}">
                <x-text-input name="p[saved_replies][{{ $i }}][label]" type="text" class="block w-full text-sm" :value="$row['label'] ?? ''" placeholder="{{ __('Opening hours') }}" />
                <x-text-input name="p[saved_replies][{{ $i }}][text]" type="text" class="block w-full text-sm sm:col-span-4" :value="$row['text'] ?? ''" placeholder="{{ __('Ανοιχτά κάθε μέρα 10:00–02:00 / Open daily 10:00–02:00') }}" />
                <x-text-input name="p[saved_replies][{{ $i }}][keywords]" type="text" class="block w-full text-sm" :value="is_array($row['keywords'] ?? null) ? implode(', ', $row['keywords']) : ($row['keywords'] ?? '')" placeholder="{{ __('ώρες, hours') }}" />
            </div>
        @endforeach
    </x-field>
    <x-field :label="__('Webhooks')" :help="__('For developers: an https address that receives a signed JSON message when a post is published, fails or needs a human (docs/api.md).')">
        @foreach ($rows(old('p.webhooks', $profile['webhooks'] ?? [])) as $i => $row)
            <div class="grid gap-2 sm:grid-cols-6 {{ $i ? 'mt-2' : '' }}">
                <x-text-input name="p[webhooks][{{ $i }}][url]" type="url" pattern="https://.*" class="block w-full font-mono text-sm sm:col-span-3" :value="$row['url'] ?? ''" placeholder="https://example.com/hook" />
                <x-text-input name="p[webhooks][{{ $i }}][secret]" type="text" class="block w-full font-mono text-sm" :value="$row['secret'] ?? ''" placeholder="{{ __('secret') }}" />
                <div class="flex flex-wrap items-center gap-3 text-xs sm:col-span-2">
                    @foreach (\App\Webhooks\Webhooks::EVENTS as $event)
                        <label class="flex items-center gap-1"><input type="checkbox" name="p[webhooks][{{ $i }}][events][]" value="{{ $event }}" class="{{ $check }}" @checked(in_array($event, $row['events'] ?? []))> {{ $event }}</label>
                    @endforeach
                </div>
            </div>
        @endforeach
    </x-field>
</x-section>

<div class="mt-4 flex items-center gap-4" x-show="!['plan', 'import'].includes(tab)">
    <x-primary-button>{{ __('Save') }}</x-primary-button>
    <a href="{{ route('clients.index') }}" class="text-sm text-gray-600 hover:text-gray-900">{{ __('Cancel') }}</a>
    @if (session('status') === 'client-updated')
        <p class="text-sm text-gray-600">{{ __('Saved.') }}</p>
    @endif
</div>
