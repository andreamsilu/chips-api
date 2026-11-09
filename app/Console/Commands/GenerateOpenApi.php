<?php

namespace App\Console\Commands;

use App\Support\OpenApiDocument;
use Illuminate\Console\Command;

final class GenerateOpenApi extends Command
{
    protected $signature = 'marketplace:openapi';

    protected $description = 'Generate the OpenAPI contract from registered API routes and explicit schemas';

    public function handle(OpenApiDocument $document): int
    {
        file_put_contents(base_path('docs/openapi.json'), json_encode($document->build(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL);
        $this->info('Generated docs/openapi.json');

        return self::SUCCESS;
    }
}
