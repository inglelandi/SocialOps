<x-guest-layout>
    <h1 class="text-lg font-semibold text-gray-900">{{ __('Create the first staff login') }}</h1>
    <p class="mt-1 text-sm text-gray-600">{{ __('This page works only while the app has no users. Add colleagues later from the Staff page.') }}</p>
    <form method="POST" action="{{ route('setup.store') }}" class="mt-4 space-y-4">
        @csrf
        @include('staff._fields')
        <x-primary-button class="w-full justify-center"><x-icon name="user-plus" class="h-4 w-4" />{{ __('Create and log in') }}</x-primary-button>
    </form>
</x-guest-layout>
