<x-mail::message>
@if ($kind === 'completed')
# {{ $review->client->name }}: {{ __('review finished') }}

@foreach ($review->posts as $post)
- `{{ $post->name }}`: {{ __($post->pivot->decision === 'approved' ? 'approved' : 'changes requested') }} ({{ $post->pivot->decided_by }}){{ $post->pivot->comment ? ' — '.$post->pivot->comment : '' }}
@endforeach

<x-mail::button :url="route('clients.reviews', $review->client)">{{ __('Open reviews') }}</x-mail::button>
@elseif ($kind === 'overdue')
# {{ $review->client->name }}: {{ __('approval overdue') }}

{{ __(':n posts were due :due and have no answer yet.', ['n' => $review->pending()->count(), 'due' => $review->due_at->translatedFormat('D d-m-Y H:i')]) }}

<x-mail::button :url="route('clients.reviews', $review->client)">{{ __('Open reviews') }}</x-mail::button>
@else
# {{ $kind === 'reminder' ? __('Reminder: ') : '' }}{{ __(':n posts for :client are ready for your approval', ['n' => $review->pending()->count(), 'client' => $review->client->name]) }}

{{ __('Please approve them or ask for changes by :due. It works on your phone, no login needed.', ['due' => $review->due_at->translatedFormat('D d-m-Y H:i')]) }}

<x-mail::button :url="$review->url()">{{ __('Review the posts') }}</x-mail::button>

{{ __('The link is personal and valid until :until. Please do not forward it.', ['until' => $review->linkExpiresAt()->format('d-m-Y')]) }}
@endif

{{ config('app.name') }}
</x-mail::message>
