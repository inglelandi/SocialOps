{{-- An upload bigger than the server takes in one send (PHP post_max_size): it never reaches the form, so this page says so in the client's language. Nothing about the client, since the link is not checked yet. --}}
<x-guest-layout>
    <h1 class="text-lg font-semibold text-gray-900">{{ __('Too much at once') }}</h1>
    <p class="mt-2 text-sm text-gray-600">{{ __('That was more than the server takes in one send, so nothing arrived. Please send fewer files at a time, or smaller videos.') }}</p>
    <a href="{{ $back }}" class="mt-5 inline-flex w-full items-center justify-center rounded-md bg-brand-500 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-600">{{ __('Back to the upload page') }}</a>
</x-guest-layout>
