<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ApiDocumentationTest extends TestCase
{
    use RefreshDatabase;

    public function test_swagger_page_and_spec_are_accessible_without_login(): void
    {
        $this->get('/docs')->assertOk()->assertSee('Swagger API')->assertSee('swagger-ui');
        $this->getJson('/docs/openapi.json')->assertOk()->assertJsonPath('openapi', '3.0.3');
        $this->getJson('/docs/csrf')->assertOk()->assertJsonStructure(['token'])->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_all_data_routes_and_account_mutations_are_documented(): void
    {
        $spec = $this->getJson('/docs/openapi.json')->json();
        $operationIds = [];
        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();
            $chatData = str_contains($route->getActionName(), 'MessagesController@') && ! str_ends_with($route->getActionName(), '@index');
            $accountMutation = str_starts_with($route->getActionName(), 'App\\Http\\Controllers\\') && ! in_array('GET', $route->methods()) && ! str_starts_with($uri, 'docs/');
            if (! $chatData && ! $accountMutation && $uri !== 'api/user') {
                continue;
            }
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $this->assertArrayHasKey('/'.$uri, $spec['paths'], $uri);
                $this->assertArrayHasKey(strtolower($method), $spec['paths']['/'.$uri], $uri.' '.$method);
            }
        }
        foreach ($spec['paths'] as $path) {
            foreach ($path as $operation) {
                $operationIds[] = $operation['operationId'];
                $this->assertNotEmpty($operation['responses']);
            }
        }
        $this->assertSame(count($operationIds), count(array_unique($operationIds)));
    }

    public function test_chat_contracts_match_session_and_bearer_responses(): void
    {
        $user = User::factory()->create();
        $spec = $this->getJson('/docs/openapi.json')->json();
        $this->assertSame([['sessionAuth' => []]], $spec['paths']['/messages/fetchMessages']['post']['security']);
        $this->assertSame([['bearerAuth' => []]], $spec['paths']['/messages/api/fetchMessages']['post']['security']);
        $this->assertSame('string', $spec['paths']['/messages/fetchMessages']['post']['responses']['200']['content']['application/json']['schema']['properties']['messages']['type']);
        $this->assertSame('array', $spec['paths']['/messages/api/fetchMessages']['post']['responses']['200']['content']['application/json']['schema']['properties']['messages']['type']);

        $this->actingAs($user)->postJson('/messages/fetchMessages', ['id' => $user->id])->assertOk();
        $this->assertIsString($this->postJson('/messages/fetchMessages', ['id' => $user->id])->json('messages'));
        $this->app['auth']->forgetGuards();
        $this->withToken($user->createToken('docs-test')->plainTextToken)
            ->postJson('/messages/api/fetchMessages', ['id' => $user->id])->assertOk()->assertJsonPath('messages', []);
        $this->postJson('/messages/api/idInfo', ['id' => 999])->assertOk()->assertJsonPath('id', $user->id)->assertJsonMissingPath('fetch');
    }

    public function test_document_uses_configured_chat_prefixes(): void
    {
        config(['chatify.routes.prefix' => 'chat', 'chatify.api_routes.prefix' => 'chat/rest']);
        $spec = $this->getJson('/docs/openapi.json')->json();
        $this->assertArrayHasKey('/chat/poll', $spec['paths']);
        $this->assertArrayHasKey('/chat/rest/sendMessage', $spec['paths']);
        $this->assertArrayNotHasKey('/messages/poll', $spec['paths']);
    }
}
