<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Support\OpenApiDocument;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FinanceAndOperationsTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_database_rejects_direct_mutation_of_audit_history(): void
    {
        $audit = AuditLog::record(['actor_id' => null, 'action' => 'test', 'subject_type' => 'test', 'subject_id' => 'test', 'metadata' => []]);
        $this->expectException(QueryException::class);
        DB::table('audit_logs')->where('id', $audit->id)->update(['action' => 'rewritten']);
    }

    public function test_seed_does_not_create_a_known_password_account(): void
    {
        $this->seed();
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseHas('categories', ['name' => 'Chips']);
    }

    public function test_openapi_contract_matches_routes_and_all_schema_references_resolve(): void
    {
        $document = app(OpenApiDocument::class)->build();
        $saved = json_decode(file_get_contents(base_path('docs/openapi.json')), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($document, $saved);
        $this->assertArrayHasKey('/api/v1/checkout', $document['paths']);
        $this->assertArrayHasKey('requestBody', $document['paths']['/api/v1/checkout']['post']);
        $this->getJson('/api/v1/openapi.json')->assertOk();
        $walk = function (array $value) use (&$walk, $document): void {
            foreach ($value as $key => $child) {
                if ($key === '$ref') {
                    $this->assertArrayHasKey(str_replace('#/components/schemas/', '', $child), $document['components']['schemas']);
                } elseif (is_array($child)) {
                    $walk($child);
                }
            }
        };
        $walk($document);
    }
}
