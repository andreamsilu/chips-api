<?php

namespace App\Modules\Administration;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class AdminQuery
{
    public static function validate(Request $request, array $rules = [], array $sorts = []): array
    {
        $rules = array_merge([
            'page' => ['sometimes', 'integer', 'between:1,10000'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'date_from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'date_to' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'sort' => ['sometimes', Rule::in(array_keys($sorts) ?: ['created_at', 'id'])],
            'direction' => ['sometimes', Rule::in(['asc', 'desc'])],
        ], $rules);
        if ($request->filled('date_from')) {
            $rules['date_to'][] = 'after_or_equal:date_from';
        }
        $unknown = array_diff(array_keys($request->query()), array_keys($rules));
        if ($unknown !== []) {
            throw ValidationException::withMessages([reset($unknown) => 'This filter is not supported.']);
        }
        $input = $request->query();
        foreach ($rules as $name => $rule) {
            if (in_array('boolean', $rule, true) && isset($input[$name]) && in_array($input[$name], ['true', 'false'], true)) {
                $input[$name] = $input[$name] === 'true';
            }
        }

        return validator($input, $rules)->validate();
    }

    public static function dates(Builder $query, array $data, ?string $column = null): Builder
    {
        $column ??= $query->getModel()->qualifyColumn('created_at');
        if (! empty($data['date_from'])) {
            $query->where($column, '>=', CarbonImmutable::parse($data['date_from'], 'Africa/Dar_es_Salaam')->startOfDay()->utc());
        }
        if (! empty($data['date_to'])) {
            $query->where($column, '<', CarbonImmutable::parse($data['date_to'], 'Africa/Dar_es_Salaam')->addDay()->startOfDay()->utc());
        }

        return $query;
    }

    public static function page(Request $request, Builder $query, array $columns, array $filters = [], array $sorts = [], ?callable $transform = null): LengthAwarePaginator
    {
        $model = $query->getModel();
        $sorts = $sorts ?: ['created_at' => $model->qualifyColumn('created_at'), 'id' => $model->getQualifiedKeyName()];
        $data = self::validate($request, array_map(fn (array $filter): array => $filter['rules'], $filters), $sorts);
        self::dates($query, $data);
        if (isset($data['search']) && $data['search'] !== '') {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($data['search'])).'%';
            $query->where(function (Builder $nested) use ($columns, $pattern): void {
                foreach ($columns as $column) {
                    if (is_callable($column)) {
                        $column($nested, $pattern);
                    } else {
                        $nested->orWhereRaw('LOWER('.$column.") LIKE ? ESCAPE '!'", [$pattern]);
                    }
                }
            });
        }
        foreach ($filters as $name => $filter) {
            if (! array_key_exists($name, $data) || $data[$name] === null || $data[$name] === '') {
                continue;
            }
            if (isset($filter['apply'])) {
                $filter['apply']($query, $data[$name]);
            } else {
                $query->where($model->qualifyColumn($filter['column'] ?? $name), $data[$name]);
            }
        }
        $sort = $sorts[$data['sort'] ?? array_key_first($sorts)];
        $query->orderBy($sort, $data['direction'] ?? 'desc');
        if ($sort !== $model->getQualifiedKeyName()) {
            $query->orderBy($model->getQualifiedKeyName(), $data['direction'] ?? 'desc');
        }
        $page = $query->paginate((int) ($data['per_page'] ?? 20), ['*'], 'page', (int) ($data['page'] ?? 1))->appends($data);

        return $page->through($transform ?? fn ($row): array => AdminData::row($row));
    }

    public static function body(Request $request, array $rules): array
    {
        $allowed = array_unique(array_map(fn (string $key): string => explode('.', $key)[0], array_keys($rules)));
        $unknown = array_diff(array_keys($request->all()), $allowed);
        if ($unknown !== []) {
            throw ValidationException::withMessages([reset($unknown) => 'This field is not allowed.']);
        }

        return $request->validate($rules);
    }

    public static function enum(array $values): array
    {
        return ['rules' => ['sometimes', 'nullable', Rule::in($values)]];
    }

    public static function identifier(string $column = ''): array
    {
        return ['rules' => ['sometimes', 'nullable', 'ulid']] + ($column !== '' ? ['column' => $column] : []);
    }

    public static function boolean(): array
    {
        return ['rules' => ['sometimes', 'nullable', 'boolean']];
    }
}
