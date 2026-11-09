<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BackfillUserPublicIds extends Command
{
    protected $signature = 'marketplace:backfill-user-ids';

    protected $description = 'Backfill existing user public identifiers in bounded restartable batches';

    public function handle(): int
    {
        DB::table('users')->whereNull('public_id')->orderBy('id')->chunkById(500, function ($users): void {
            foreach ($users as $user) {
                DB::table('users')->where('id', $user->id)->whereNull('public_id')->update(['public_id' => (string) Str::ulid()]);
            }
        });

        return self::SUCCESS;
    }
}
