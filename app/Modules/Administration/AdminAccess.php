<?php

namespace App\Modules\Administration;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

final class AdminAccess
{
    public const MODULES = ['overview', 'vendors', 'customers', 'orders', 'payments', 'riders', 'finance', 'catalogue', 'promotions', 'administration', 'audit'];

    public static function catalogue(): array
    {
        $permissions = [];
        foreach (self::MODULES as $module) {
            $permissions[] = $module.'.read';
            if (! in_array($module, ['overview', 'audit'], true)) {
                $permissions[] = $module.'.manage';
            }
        }

        return $permissions;
    }

    public static function permissions(User $user): array
    {
        if (! $user->hasRole('admin')) {
            return [];
        }
        $profile = $user->relationLoaded('adminAccessProfile') ? $user->getRelation('adminAccessProfile') : DB::table('admin_access_profiles')->where('user_id', $user->id)->first();
        if ($profile === null || $profile->unrestricted) {
            return self::catalogue();
        }

        return DB::table('admin_permission_grants')->where('user_id', $user->id)->pluck('permission')->all();
    }

    public function handle(Request $request, Closure $next): Response
    {
        $name = str_replace('api.v1.admin.', '', $request->route()->getName());
        $module = match (explode('.', $name)[0]) {
            'dashboard' => 'overview',
            'vendors', 'locations', 'documents' => 'vendors',
            'customers' => 'customers',
            'orders' => 'orders',
            'payments', 'refunds' => 'payments',
            'riders', 'deliveries' => 'riders',
            'finance', 'settlements' => 'finance',
            'categories', 'products' => 'catalogue',
            'coupons', 'campaigns' => 'promotions',
            'audit' => 'audit',
            default => 'administration',
        };
        if ($name === 'vendors.settlements.prepare') {
            $module = 'finance';
        }
        $permission = $module.'.'.($request->isMethod('GET') || $request->isMethod('HEAD') ? 'read' : 'manage');
        abort_unless(in_array($permission, self::permissions($request->user()), true), 403, 'This administrative permission is required: '.$permission);

        return $next($request);
    }
}
