<?php

namespace Tests\Feature\Migration;

use App\Models\User;
use App\Modules\Migration\MigrationStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SkipImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Force migration mode = import so the route exists, and stub the
        // peer + secret so the seal call has somewhere to go.
        config()->set('migration.mode', 'import');
        config()->set('migration.peer_url', 'https://beta.test');
        config()->set('migration.handoff_secret', str_repeat('a', 64));

        // The container caches config (including app.key) before our
        // setUp() runs; if app.key is missing the auth middleware blows up
        // resolving the session encrypter. Use a real-shaped key so the
        // request pipeline doesn't trip on it.
        config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

        Http::fake([
            'beta.test/api/migration/seal' => Http::response(['migration_status' => 'completed'], 200),
        ]);
    }

    public function test_pending_user_can_skip_and_lands_completed(): void
    {
        $user = User::factory()->create([
            'migration_status' => MigrationStatus::PENDING->value,
        ]);

        $response = $this->actingAs($user)
            ->withoutMiddleware([
                \Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class,
                \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
            ])
            ->postJson(route('migration.import.skip'));

        $response->assertOk();
        $response->assertJson(['status' => MigrationStatus::COMPLETED->value]);

        $user->refresh();
        $this->assertSame(MigrationStatus::COMPLETED, $user->migration_status);
        $this->assertNotNull($user->migration_completed_at);

        Http::assertSent(fn ($request) => $request->url() === 'https://beta.test/api/migration/seal'
            && $request->method() === 'POST'
            && str_starts_with($request->header('Authorization')[0] ?? '', 'Bearer '));
    }

    public function test_failed_user_can_skip(): void
    {
        $user = User::factory()->create([
            'migration_status' => MigrationStatus::FAILED->value,
        ]);

        $response = $this->actingAs($user)
            ->withoutMiddleware([
                \Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class,
                \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
            ])
            ->postJson(route('migration.import.skip'));

        $response->assertOk();
        $user->refresh();
        $this->assertSame(MigrationStatus::COMPLETED, $user->migration_status);
    }

    public function test_in_progress_user_cannot_skip(): void
    {
        $user = User::factory()->create([
            'migration_status' => MigrationStatus::IN_PROGRESS->value,
        ]);

        $response = $this->actingAs($user)
            ->withoutMiddleware([
                \Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class,
                \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
            ])
            ->postJson(route('migration.import.skip'));

        $response->assertStatus(409);
        $user->refresh();
        $this->assertSame(MigrationStatus::IN_PROGRESS, $user->migration_status);
        Http::assertNothingSent();
    }

    public function test_completed_user_cannot_skip(): void
    {
        $user = User::factory()->create([
            'migration_status' => MigrationStatus::COMPLETED->value,
        ]);

        $response = $this->actingAs($user)
            ->withoutMiddleware([
                \Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class,
                \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
            ])
            ->postJson(route('migration.import.skip'));

        $response->assertStatus(409);
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $response = $this->withoutMiddleware([
                \Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class,
                \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
            ])
            ->postJson(route('migration.import.skip'));

        // Laravel's auth middleware returns 401 for JSON requests.
        $this->assertContains($response->status(), [401, 302]);
        Http::assertNothingSent();
    }

    public function test_skip_proceeds_locally_even_if_peer_seal_fails(): void
    {
        Http::fake([
            'beta.test/api/migration/seal' => Http::response('boom', 500),
        ]);

        $user = User::factory()->create([
            'migration_status' => MigrationStatus::PENDING->value,
        ]);

        $response = $this->actingAs($user)
            ->withoutMiddleware([
                \Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class,
                \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
            ])
            ->postJson(route('migration.import.skip'));

        $response->assertOk();
        $user->refresh();
        $this->assertSame(MigrationStatus::COMPLETED, $user->migration_status);
    }
}
