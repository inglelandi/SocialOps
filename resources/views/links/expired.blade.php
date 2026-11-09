{{-- An expired or incomplete client link (App\Http\ExpiredLink): nothing about the client, the posts or the files, since anyone can type the address --}}
<x-guest-layout>
    <div class="flex h-11 w-11 items-center justify-center rounded-full bg-brand-50 text-brand-600" aria-hidden="true">
        <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
    </div>
    <h1 class="mt-4 text-lg font-semibold text-gray-900">{{ __('This link has expired or is incomplete') }}</h1>

    @if ($answer === 'sent')
        <p class="mt-4 rounded-lg bg-green-50 p-3 text-sm text-green-800" role="status">{{ __('We have told the agency; they will send you a new link.') }}</p>
    @elseif ($answer === 'limited')
        <p class="mt-4 rounded-lg bg-yellow-100 p-3 text-sm text-yellow-800" role="status">{{ __('You have asked several times in the last hour. Please try again later, or contact the agency directly.') }}</p>
    @else
        <p class="mt-2 text-sm text-gray-600">{{ __('The links we send you work for a limited time, and only when they are opened in full.') }}</p>
        <p class="mt-2 text-sm text-gray-600">{{ __('If you copied the link, check that nothing is missing at the end. Otherwise ask for a new one and we will send it to you.') }}</p>
        <form method="POST" action="{{ route('links.request') }}" class="mt-5">
            <input type="hidden" name="path" value="{{ $path }}">
            <x-primary-button class="w-full justify-center"><x-icon name="envelope" class="h-4 w-4" />{{ __('Ask for a new link') }}</x-primary-button>
        </form>
    @endif
</x-guest-layout>
