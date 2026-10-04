<?php

namespace Tests\Feature;

use App\Enums\RecordStatus;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\CompiledRouteCollection;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LoginRedirectTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private ?array $compiledRoutes = null;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'http://localhost']);
        $this->user = User::factory()->create([
            'role' => UserRole::SurveyTeamLeader,
            'status' => RecordStatus::Active,
            'password' => Hash::make('KnownLoginPassword123!'),
        ]);
    }

    public static function rootDeployments(): array
    {
        return [
            'root without cached routes' => [false, ''],
            'root with cached routes' => [true, ''],
            'public directory without cached routes' => [false, '/public'],
            'public directory with cached routes' => [true, '/public'],
            'public index.php with cached routes' => [true, '/public/index.php'],
            'subdirectory index.php with cached routes' => [true, '/survey/index.php'],
        ];
    }

    #[DataProvider('rootDeployments')]
    public function test_guest_root_login_and_authenticated_dashboard_work_for_get_and_head(bool $compiled, string $base): void
    {
        $this->configureDeployment($compiled, $base);
        $rootUrl = 'http://localhost'.$base.'/';
        $loginUrl = 'http://localhost'.$base.'/login';

        $this->get($rootUrl)->assertRedirect($loginUrl);
        $this->call('HEAD', $rootUrl)->assertRedirect($loginUrl);
        $this->assertGuest();
        $this->get($loginUrl)->assertOk()->assertSee('name="password"', false);
        $login = $this->post($loginUrl, ['email' => $this->user->email, 'password' => 'KnownLoginPassword123!'])
            ->assertRedirect(rtrim($rootUrl, '/'))->assertSessionHasNoErrors();
        $this->assertAuthenticatedAs($this->user);

        // Apache adds the slash when following a redirect to a physical directory.
        $dashboardUrl = rtrim($login->headers->get('Location'), '/').'/';
        $this->get($dashboardUrl)->assertOk()->assertSee('Survey Team Dashboard');
        $this->call('HEAD', $rootUrl)->assertOk()->assertContent('');
        $this->reloadCompiledRoutes();
        $this->get($rootUrl.'?source=login')->assertOk()->assertSee('Survey Team Dashboard');
        $this->reloadCompiledRoutes();
        $this->call('HEAD', $rootUrl.'?source=login')->assertOk()->assertContent('');
        $this->assertAuthenticatedAs($this->user);
    }

    public function test_cached_public_root_keeps_inactive_account_protection(): void
    {
        $this->configureDeployment(true, '/public');
        $this->user->update(['status' => RecordStatus::Inactive]);
        $this->actingAs($this->user)->get('http://localhost/public/')
            ->assertRedirect('http://localhost/public/login')->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_cached_public_root_rejects_post_instead_of_treating_it_as_a_dashboard_request(): void
    {
        $this->configureDeployment(true, '/public');
        $this->actingAs($this->user);
        $response = $this->post('http://localhost/public/', ['entry_date' => today()->toDateString()])
            ->assertStatus(405);
        $allowedMethods = array_map('trim', explode(',', $response->headers->get('Allow')));
        $this->assertContains('GET', $allowedMethods);
        $this->assertContains('HEAD', $allowedMethods);
        $this->assertAuthenticatedAs($this->user);
        $this->assertDatabaseCount('survey_daily_entries', 0);
        $this->get('http://localhost/public/')->assertOk()->assertSee('Survey Team Dashboard');
    }

    private function configureDeployment(bool $compiled, string $base): void
    {
        $scriptName = str_ends_with($base, '/index.php') ? $base : $base.'/index.php';
        $this->withServerVariables([
            'SCRIPT_FILENAME' => public_path('index.php'),
            'SCRIPT_NAME' => $scriptName,
            'PHP_SELF' => $scriptName,
        ]);

        if ($compiled) {
            $router = app('router');
            $this->compiledRoutes = $router->getRoutes()->compile();
            $this->reloadCompiledRoutes();
            $this->assertInstanceOf(CompiledRouteCollection::class, $router->getRoutes());
        }
    }

    private function reloadCompiledRoutes(): void
    {
        if ($this->compiledRoutes !== null) {
            $router = app('router');
            $router->setCompiledRoutes($this->compiledRoutes);
            app('url')->setRoutes($router->getRoutes());
        }
    }

    protected function prepareUrlForRequest($uri)
    {
        // Keep browser directory-root URLs intact; Laravel's test helper trims their slash.
        if (is_string($uri) && preg_match('#^https?://#', $uri)) {
            return $uri;
        }

        return parent::prepareUrlForRequest($uri);
    }
}
