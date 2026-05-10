@props(['game', 'team'])

@php
    $teamSlug = $team->slug ?? null;
    $teamType = $team->type ?? 'club';
@endphp

@if($teamSlug && $teamType === 'club')
    {{-- Hover-revealed shortcut to /game/{id}/explore/team/{slug}. The
         containing row must carry the `group` class so `group-hover:` fires.
         On touch devices (no real hover) the icon stays softly visible
         instead of being unreachable. --}}
    <a href="{{ route('game.explore.team', ['gameId' => $game->id, 'slug' => $teamSlug]) }}"
       title="{{ __('transfers.explore_view_team', ['team' => $team->name]) }}"
       aria-label="{{ __('transfers.explore_view_team', ['team' => $team->name]) }}"
       {{ $attributes->merge(['class' => 'inline-flex items-center justify-center text-text-muted hover:text-text-primary transition-opacity opacity-0 group-hover:opacity-80 hover:!opacity-100 pointer-coarse:opacity-50 pointer-coarse:group-hover:opacity-50']) }}>
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" fill="currentColor" class="size-3.5">
            <path d="M6.22 8.72a.75.75 0 0 0 1.06 1.06l5.22-5.22v1.69a.75.75 0 0 0 1.5 0v-3.5a.75.75 0 0 0-.75-.75h-3.5a.75.75 0 0 0 0 1.5h1.69L6.22 8.72Z"/>
            <path d="M3.5 6.75c0-.69.56-1.25 1.25-1.25H7A.75.75 0 0 0 7 4H4.75A2.75 2.75 0 0 0 2 6.75v4.5A2.75 2.75 0 0 0 4.75 14h4.5A2.75 2.75 0 0 0 12 11.25V9a.75.75 0 0 0-1.5 0v2.25c0 .69-.56 1.25-1.25 1.25h-4.5c-.69 0-1.25-.56-1.25-1.25v-4.5Z"/>
        </svg>
    </a>
@endif
