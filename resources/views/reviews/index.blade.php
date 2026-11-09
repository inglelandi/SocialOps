<x-app-layout :client="$client">
    <x-slot name="header">
        <h2 class="text-2xl font-semibold tracking-tight text-gray-900">{{ $client->name }}: {{ __('approvals') }}</h2>
    </x-slot>

    @php($approval = $client->profile['approval'])
    @php($policy = __('Policy: :policy, :hours h to answer, approvers: :approvers.', ['policy' => $approval['policy'], 'hours' => $approval['sla_hours'], 'approvers' => collect($approval['approvers'])->map(fn ($a) => "{$a['name']} <{$a['email']}>")->join(', ') ?: __('none yet, so you send the link yourself')]).($approval['auto_approve_on_timeout'] ? ' '.__('Posts are approved automatically when the time runs out.') : ''))
    <div class="py-6">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-4">
            @if (session('status'))
                <p class="flex items-center gap-1.5 text-sm text-green-600"><x-icon name="check-circle" class="h-5 w-5" />{{ __(ucfirst(str_replace('-', ' ', session('status')))) }}.</p>
            @endif
            @error('posts') <p class="text-sm text-red-600">{{ $message }}</p> @enderror

            <x-section icon="paper-airplane" :title="__('Send posts for approval')" :description="$policy">
                <form method="POST" action="{{ route('reviews.store', $client) }}" class="py-5">
                    @csrf
                    <div class="space-y-2">
                        @forelse ($candidates as $post)
                            <label class="flex items-center gap-3 text-sm">
                                <input type="checkbox" name="posts[]" value="{{ $post->id }}" class="rounded border-gray-300" />
                                <span class="font-mono">{{ $post->name }}</span>
                                <x-state-badge :state="$post->state" />
                                <span class="text-gray-600">{{ $post->scheduled_at?->translatedFormat('D d-m-Y H:i') ?? __('not scheduled') }}</span>
                            </label>
                        @empty
                            <x-empty-state icon="paper-airplane" action-icon="plus" :action="__('New post')" :href="route('posts.create', $client)">{{ __('No draft posts waiting. A post shows here once it exists on the calendar; tick it and send it, the approvers get the link by email.') }}</x-empty-state>
                        @endforelse
                    </div>
                    @if ($candidates->isNotEmpty())
                        <x-primary-button class="mt-4"><x-icon name="paper-airplane" class="h-4 w-4" />{{ __('Send for approval') }}</x-primary-button>
                    @endif
                </form>
            </x-section>

            @foreach ($reviews as $review)
                <x-section icon="envelope" :title="__('Sent :at', ['at' => $review->created_at->translatedFormat('D d-m-Y H:i')])" :description="__('due :at', ['at' => $review->due_at->translatedFormat('D d-m-Y H:i')])" class="text-sm">
                    <div class="flex flex-wrap items-center gap-3 py-4">
                        @if ($review->completed_at)
                            <span class="rounded-full bg-green-100 px-2 py-0.5 text-xs text-green-800">{{ __('Answered :at', ['at' => $review->completed_at->translatedFormat('D d-m-Y H:i')]) }}</span>
                        @elseif ($review->due_at->isPast())
                            <span class="rounded-full bg-red-100 px-2 py-0.5 text-xs text-red-800">{{ __('Overdue') }}</span>
                        @else
                            <span class="rounded-full bg-yellow-100 px-2 py-0.5 text-xs text-yellow-800">{{ __('Waiting') }}{{ $review->reminded_at ? ', '.__('reminded') : '' }}</span>
                        @endif
                        <x-text-input type="text" class="block min-w-0 flex-1 font-mono text-xs" :value="$review->url()" readonly onclick="this.select()" />
                    </div>
                    @foreach ($review->posts as $post)
                        <div class="flex flex-wrap items-baseline gap-3 py-2.5">
                            <a href="{{ route('posts.edit', $post) }}" class="font-mono font-medium text-gray-900 hover:text-brand-600">{{ $post->name }}</a>
                            @if ($post->pivot->decision)
                                <span class="{{ $post->pivot->decision === 'approved' ? 'text-green-700' : 'text-orange-700' }}">{{ __($post->pivot->decision === 'approved' ? 'Approved' : 'Changes requested') }} · {{ $post->pivot->decided_by }}</span>
                                @if ($post->pivot->comment) <span class="text-gray-700">“{{ $post->pivot->comment }}”</span> @endif
                            @else
                                <span class="text-gray-500">{{ __('no answer yet') }}</span>
                            @endif
                        </div>
                    @endforeach
                </x-section>
            @endforeach
        </div>
    </div>
</x-app-layout>
