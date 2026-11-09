{{-- After Mark published on a repeating post: its next occurrence was drafted (Post::spawnRepeat()) and goes through approval again. --}}
@if (session('repeat'))
    <p class="text-sm text-green-600" role="status">{{ __('Next occurrence drafted:') }} <a href="{{ route('posts.edit', session('repeat')['id']) }}" class="font-mono text-xs text-gray-900 underline hover:text-brand-600">{{ session('repeat')['name'] }}</a>. {{ __('Send it for review when it is ready.') }}</p>
@endif
