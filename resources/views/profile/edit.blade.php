<x-app-layout>
    <x-slot name="header">
        <h2 class="text-2xl font-semibold tracking-tight text-gray-900">
            {{ __('Profile') }}
        </h2>
    </x-slot>

    <div class="py-6">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-4">
            @php($deleteHelp = __('Once your account is deleted, all of its resources and data will be permanently deleted. Before deleting your account, please download any data or information that you wish to retain.'))
            <x-section icon="user-circle" :title="__('Profile Information')" :description="__('Update your account\'s profile information and email address.')">
                <div class="max-w-xl py-5">@include('profile.partials.update-profile-information-form')</div>
            </x-section>

            <x-section icon="lock-closed" :title="__('Update Password')" :description="__('Ensure your account is using a long, random password to stay secure.')">
                <div class="max-w-xl py-5">@include('profile.partials.update-password-form')</div>
            </x-section>

            <x-section icon="trash" :title="__('Delete Account')" :description="$deleteHelp">
                <div class="max-w-xl py-5">@include('profile.partials.delete-user-form')</div>
            </x-section>
        </div>
    </div>
</x-app-layout>
