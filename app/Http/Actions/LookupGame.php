<?php

namespace App\Http\Actions;

use App\Models\Game;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class LookupGame
{
    public function __invoke(Request $request)
    {
        $request->validate([
            'query' => ['required', 'string', 'max:255'],
        ]);

        $query = trim($request->input('query'));

        if (Str::isUuid($query)) {
            $games = Game::with(['user:id,name,email', 'team:id,name'])
                ->where('id', $query)
                ->get();
        } else {
            $userIds = User::query()
                ->where('email', 'like', '%'.$query.'%')
                ->orWhere('name', 'like', '%'.$query.'%')
                ->limit(20)
                ->pluck('id');

            if ($userIds->isEmpty()) {
                return response()->json(['found' => false, 'results' => []]);
            }

            $games = Game::with(['user:id,name,email', 'team:id,name'])
                ->whereIn('user_id', $userIds)
                ->orderByDesc('current_date')
                ->limit(50)
                ->get();
        }

        if ($games->isEmpty()) {
            return response()->json(['found' => false, 'results' => []]);
        }

        return response()->json([
            'found' => true,
            'results' => $games->map(fn (Game $game) => [
                'game_id' => $game->id,
                'game_mode' => $game->game_mode,
                'season' => $game->season,
                'current_date' => $game->current_date?->format('Y-m-d'),
                'user_name' => $game->user->name,
                'user_email' => $game->user->email,
                'team_name' => $game->team?->name,
                'setup_completed' => $game->setup_completed_at !== null,
            ])->values(),
        ]);
    }
}
