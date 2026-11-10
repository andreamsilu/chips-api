<?php

namespace App\Modules\Administration;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use App\Support\Domain;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

final class AdminAccountsController extends Controller
{
    public const ROLES = ['customer', 'vendor', 'rider', 'support', 'admin'];

    public function index(Request $request): JsonResponse
    {
        $query = User::with('roles')->withCount('orders');
        if ($request->routeIs('api.v1.admin.customers.*')) {
            $query->whereHas('roles', fn (Builder $roles) => $roles->where('name', 'customer'));
        }

        return response()->json(AdminQuery::page($request, $query, ['users.name', 'users.email', 'users.public_id', 'users.phone'], [
            'role' => ['rules' => ['sometimes', Rule::in(self::ROLES)], 'apply' => fn (Builder $q, string $role) => $q->whereHas('roles', fn (Builder $roles) => $roles->where('name', $role))],
            'status' => ['rules' => ['sometimes', Rule::in(['active', 'suspended'])], 'apply' => fn (Builder $q, string $status) => $status === 'active' ? $q->whereNull('suspended_at') : $q->whereNotNull('suspended_at')],
        ], ['created_at' => 'users.created_at', 'name' => 'users.name', 'email' => 'users.email', 'id' => 'users.public_id']));
    }

    public function show(Request $request, User $user): JsonResponse
    {
        $this->customerScope($request, $user);

        return response()->json(['data' => AdminData::row($user->load('roles')->loadCount('orders')) + ['permissions' => AdminAccess::permissions($user), 'unrestricted' => $user->hasRole('admin') && (bool) (DB::table('admin_access_profiles')->where('user_id', $user->id)->value('unrestricted') ?? true)]]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = AdminQuery::body($request, ['name' => ['required', 'string', 'max:160'], 'email' => ['required', 'email', 'max:255', 'unique:users,email'], 'phone' => ['nullable', 'regex:/^\+255[67]\d{8}$/', 'unique:users,phone'], 'password' => ['required', 'string', 'max:1024', 'confirmed', Password::min(12)->mixedCase()->numbers()], 'password_confirmation' => ['required', 'string'], 'roles' => ['required', 'array', 'min:1', 'max:5'], 'roles.*' => ['required', 'distinct', Rule::in(self::ROLES)]]);
        $user = DB::transaction(function () use ($data, $request): User {
            Role::where('name', 'admin')->lockForUpdate()->firstOrFail();
            $user = User::create(collect($data)->only(['name', 'email', 'password'])->all());
            $user->forceFill(['phone' => $data['phone'] ?? null])->save();
            $user->roles()->sync(Role::whereIn('name', $data['roles'])->pluck('id'));
            $this->audit($request->user(), $user, 'user.created', ['roles' => $data['roles']]);

            return $user;
        }, 3);

        return response()->json(['data' => AdminData::row($user->load('roles'))], 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $this->customerScope($request, $user);
        $data = AdminQuery::body($request, ['name' => ['sometimes', 'required', 'string', 'max:160'], 'phone' => ['sometimes', 'nullable', 'regex:/^\+255[67]\d{8}$/', Rule::unique('users', 'phone')->ignore($user->id)], 'status' => ['sometimes', Rule::in(['active', 'suspended'])]]);
        DB::transaction(function () use ($data, $request, $user): void {
            Role::where('name', 'admin')->lockForUpdate()->firstOrFail();
            $target = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            if (($data['status'] ?? null) === 'suspended') {
                Domain::require($request->user()->id !== $target->id, 'You cannot suspend your own account.');
                $this->preserveAdministrator($target);
                $target->suspended_at = now();
                $target->tokens()->delete();
            } elseif (($data['status'] ?? null) === 'active') {
                $target->suspended_at = null;
            }
            $target->forceFill(collect($data)->only(['name', 'phone'])->all())->save();
            $this->audit($request->user(), $target, 'user.updated', ['fields' => array_keys($data), 'status' => $data['status'] ?? null]);
        }, 3);

        return response()->json(['data' => AdminData::row($user->fresh()->load('roles'))]);
    }

    public function roles(Request $request, User $user): JsonResponse
    {
        $data = AdminQuery::body($request, ['roles' => ['required', 'array', 'min:1', 'max:5'], 'roles.*' => ['required', 'distinct', Rule::in(self::ROLES)]]);
        DB::transaction(function () use ($data, $request, $user): void {
            Role::where('name', 'admin')->lockForUpdate()->firstOrFail();
            $target = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            if (! in_array('admin', $data['roles'], true)) {
                $this->preserveAdministrator($target);
            }
            $target->roles()->sync(Role::whereIn('name', $data['roles'])->pluck('id'));
            $target->tokens()->delete();
            $this->audit($request->user(), $target, 'roles.updated', ['roles' => $data['roles']]);
        }, 3);

        return response()->json(['data' => AdminData::row($user->fresh()->load('roles'))]);
    }

    public function permissions(Request $request, User $user): JsonResponse
    {
        $data = AdminQuery::body($request, ['unrestricted' => ['required', 'boolean'], 'permissions' => ['required_if:unrestricted,false', 'array', 'max:30'], 'permissions.*' => ['required', 'distinct', Rule::in(AdminAccess::catalogue())]]);
        DB::transaction(function () use ($data, $request, $user): void {
            Role::where('name', 'admin')->lockForUpdate()->firstOrFail();
            $target = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            Domain::require($target->hasRole('admin'), 'Only administrators have administrative permission grants.');
            if (! $data['unrestricted']) {
                $this->preserveAdministrator($target);
            }
            DB::table('admin_access_profiles')->updateOrInsert(['user_id' => $target->id], ['unrestricted' => $data['unrestricted'], 'updated_at' => now()]);
            DB::table('admin_permission_grants')->where('user_id', $target->id)->delete();
            foreach ($data['unrestricted'] ? [] : ($data['permissions'] ?? []) as $permission) {
                DB::table('admin_permission_grants')->insert(['user_id' => $target->id, 'permission' => $permission]);
            }
            $target->tokens()->delete();
            $this->audit($request->user(), $target, 'permissions.updated', $data);
        }, 3);

        return response()->json(['data' => ['id' => $user->public_id, 'permissions' => AdminAccess::permissions($user)]]);
    }

    public function password(Request $request, User $user): JsonResponse
    {
        $data = AdminQuery::body($request, ['password' => ['required', 'string', 'max:1024', 'confirmed', Password::min(12)->mixedCase()->numbers()], 'password_confirmation' => ['required', 'string']]);
        DB::transaction(function () use ($request, $user, $data): void {
            $target = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $target->password = $data['password'];
            $target->save();
            $target->tokens()->delete();
            $this->audit($request->user(), $target, 'password.reset');
        }, 3);

        return response()->json(['message' => 'Password changed and existing sessions revoked.']);
    }

    public function catalogue(): JsonResponse
    {
        return response()->json(['data' => ['roles' => self::ROLES, 'permissions' => AdminAccess::catalogue()]]);
    }

    private function customerScope(Request $request, User $user): void
    {
        if ($request->routeIs('api.v1.admin.customers.*')) {
            abort_unless($user->roles()->where('name', 'customer')->exists(), 404);
            if (! $request->isMethod('GET')) {
                Domain::require(! $user->roles()->where('name', 'admin')->exists(), 'Manage administrator accounts through account administration.');
            }
        }
    }

    private function preserveAdministrator(User $user): void
    {
        if (! $user->roles()->where('name', 'admin')->exists() || $user->suspended_at !== null) {
            return;
        }
        $others = User::whereKeyNot($user->id)->whereNull('suspended_at')->whereHas('roles', fn (Builder $q) => $q->where('name', 'admin'))
            ->where(function (Builder $q): void {
                $q->whereNotIn('id', DB::table('admin_access_profiles')->select('user_id'))
                    ->orWhereIn('id', DB::table('admin_access_profiles')->where('unrestricted', true)->select('user_id'));
            });
        Domain::require($others->exists(), 'At least one active unrestricted administrator must remain.');
    }

    private function audit(User $actor, User $subject, string $action, array $metadata = []): void
    {
        AuditLog::record(['actor_id' => $actor->id, 'action' => $action, 'subject_type' => 'User', 'subject_id' => $subject->public_id, 'metadata' => $metadata]);
    }
}
