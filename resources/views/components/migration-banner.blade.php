@php
    $user = auth()->user();
    $shouldShow = config('migration.mode') === 'export'
        && $user
        && $user->migration_status?->value === \App\Modules\Migration\MigrationStatus::PENDING->value
        && \App\Modules\Migration\MigrationGate::isUserAllowed($user->id);
@endphp

@if($shouldShow)
    <div class="bg-emerald-500 text-emerald-950 text-center text-xs py-1.5 px-4">
        <span class="font-semibold">{{ __('migration.banner_label') }}</span>
        —
        {{ __('migration.banner_body') }}
        ·
        <form method="POST" action="{{ route('migration.start') }}" class="inline">
            @csrf
            <button type="submit" class="underline font-semibold hover:text-emerald-100 cursor-pointer">{{ __('migration.banner_cta') }}</button>
        </form>
    </div>
@endif
