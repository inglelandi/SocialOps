<x-guest-layout>
    <h1 class="text-lg font-semibold text-gray-900">{{ $review->client->name }}</h1>
    @php($pending = $posts->whereNull('pivot.decision'))
    @php($approved = $posts->where('pivot.decision', 'approved')->count())
    <p class="mt-1 text-sm text-gray-600">
        @if ($pending->isEmpty())
            {{ __('All answered, thank you! We take it from here.') }}
        @else
            {{ __('Approve each one by :due, or tell us what to change.', ['due' => $review->due_at->translatedFormat('D d-m-Y H:i')]) }}
        @endif
    </p>
    <p class="mt-3 text-sm font-medium text-gray-800">{{ trans_choice('{1} :count post|[2,*] :count posts', $posts->count()) }} · {{ $approved }} {{ __('approved') }} · {{ $posts->count() - $approved - $pending->count() }} {{ __('to rework') }} · {{ $pending->count() }} {{ __('waiting for you') }}</p>
    <div class="mt-1 h-1.5 rounded bg-gray-200"><div class="h-1.5 rounded bg-green-500" style="width: {{ $posts->count() ? round(($posts->count() - $pending->count()) / $posts->count() * 100) : 0 }}%"></div></div>

    @if ($pending->isNotEmpty())
        <div class="mt-4">
            <x-input-label for="reviewer-name" :value="__('Your name (asked once, goes on every answer)')" />
            <x-text-input id="reviewer-name" type="text" class="mt-1 block w-full" :value="old('name', $name)" required />
        </div>
        @if ($pending->count() > 1)
            <form method="POST" action="{{ request()->fullUrl() }}" data-decide class="mt-3">
                @csrf
                <input type="hidden" name="post_id" value="all">
                <input type="hidden" name="name" value="{{ old('name', $name) }}">
                <button type="submit" name="decision" value="approved" class="w-full py-3 rounded-md bg-green-600 text-white font-semibold">{{ __('Approve all :n', ['n' => $pending->count()]) }}</button>
            </form>
        @endif
    @endif

    @if (session('status'))
        <p class="mt-3 rounded-md bg-green-50 p-3 text-sm text-green-800">{{ __(session('status') === 'approved' ? 'Approved, thank you!' : 'Noted, we will make the changes.') }}</p>
    @endif
    @if (session('notice'))
        <p class="mt-3 rounded-md bg-yellow-50 p-3 text-sm text-yellow-800" role="status">{{ session('notice') }}</p>
    @endif
    @if ($errors->any())
        <ul class="mt-3 list-disc pl-5 text-sm text-red-600">
            @foreach ($errors->all() as $error) <li>{{ $error }}</li> @endforeach
        </ul>
    @endif

    <div class="mt-4 space-y-8">
        @foreach ($posts as $post)
            <section id="post-{{ $post->id }}" class="space-y-3 {{ $post->pivot->decision ? 'opacity-70' : '' }}">
                <p class="text-xs text-gray-600">
                    {{ $post->scheduled_at ? __('Planned for :date', ['date' => $post->scheduled_at->translatedFormat('D d-m-Y H:i')]) : __('Date to be set') }}
                    · {{ str_replace('_', ' ', $post->format) }}
                </p>
                @foreach ($post->platforms as $platform)
                    @include('portal._preview', ['post' => $post, 'platform' => $platform, 'client' => $review->client, 'assetUrl' => fn ($a) => $review->assetUrl($a)])
                @endforeach
                @if ($post->assets->count() > 1)
                    <div class="flex gap-1 overflow-x-auto" aria-label="{{ __('All the pictures of this post') }}">
                        @foreach ($post->assets as $asset)
                            @if ($asset->kind === 'image')<img src="{{ $review->assetUrl($asset) }}" alt="" class="h-16 w-16 flex-none rounded object-cover">@else<video src="{{ $review->assetUrl($asset) }}" class="h-16 w-16 flex-none rounded object-cover" muted></video>@endif
                        @endforeach
                    </div>
                @endif

                @if ($post->pivot->decision)
                    <p class="text-sm {{ $post->pivot->decision === 'approved' ? 'text-green-700' : 'text-orange-700' }}">
                        {{ __($post->pivot->decision === 'approved' ? 'Approved by :name' : 'Changes requested by :name', ['name' => $post->pivot->decided_by]) }}
                        @if ($post->pivot->comment) <span class="block text-gray-700">“{{ $post->pivot->comment }}”</span> @endif
                    </p>
                @else
                    <form method="POST" action="{{ request()->fullUrl() }}" data-decide class="space-y-2">
                        @csrf
                        <input type="hidden" name="post_id" value="{{ $post->id }}">
                        <input type="hidden" name="name" value="{{ old('name', $name) }}">
                        <textarea name="comment" rows="2" maxlength="2000" class="block w-full border-gray-300 focus:border-brand-500 focus:ring-brand-500 rounded-md shadow-sm" placeholder="{{ __('Comment (needed when you ask for changes)') }}">{{ old('post_id') == $post->id ? old('comment') : '' }}</textarea>
                        <div class="flex gap-2">
                            <button type="submit" name="decision" value="approved" class="flex-1 py-3 rounded-md bg-green-600 text-white font-semibold">{{ __('Approve') }}</button>
                            <button type="submit" name="decision" value="changes_requested" class="flex-1 py-3 rounded-md border border-orange-400 text-orange-700 font-semibold">{{ __('Request changes') }}</button>
                        </div>
                    </form>
                @endif
            </section>
        @endforeach
    </div>

    <script>
        // One name for every answer: copied into each form as it is sent.
        const reviewer = document.getElementById('reviewer-name');
        document.querySelectorAll('form[data-decide]').forEach(form => form.addEventListener('submit', e => {
            if (! reviewer.value.trim()) { e.preventDefault(); reviewer.reportValidity(); return; }
            form.querySelector('input[name=name]').value = reviewer.value.trim();
        }));
    </script>
</x-guest-layout>
