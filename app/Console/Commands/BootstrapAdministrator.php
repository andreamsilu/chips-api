<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BootstrapAdministrator extends Command
{
    protected $signature = 'marketplace:bootstrap-admin {email} {--confirm : Acknowledge granting administrative privileges}';

    protected $description = 'Grant the first administrator role to an existing account through trusted operator access';

    public function handle(): int
    {
        if (! $this->option('confirm')) {
            $this->error('Explicit --confirm is required.');

            return self::FAILURE;
        }
        DB::transaction(function (): void {
            $role = Role::where('name', 'admin')->lockForUpdate()->firstOrFail();
            if (DB::table('role_user')->where('role_id', $role->id)->exists()) {
                throw new \RuntimeException('An administrator already exists. Use the authorized role API.');
            }$user = User::where('email', $this->argument('email'))->firstOrFail();
            $user->roles()->attach($role->id);
            AuditLog::record(['actor_id' => null, 'action' => 'admin.bootstrapped', 'subject_type' => 'User', 'subject_id' => $user->public_id, 'metadata' => []]);
        });
        $this->info('Administrator granted and audited.');

        return self::SUCCESS;
    }
}
