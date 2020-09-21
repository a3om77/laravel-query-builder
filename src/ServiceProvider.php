<?php

namespace A3om77\QueryBuilder;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class ServiceProvider extends \Illuminate\Support\ServiceProvider
{
    /**
     * Register services.
     *
     * @return void
     */
    public function register()
    {
        //
    }

    /**
     * Bootstrap services.
     *
     * @return void
     */
    public function boot()
    {
        Builder::macro('allowedRelations', function (array $relationKeys, $group = null, $includedKeys = null) {
            foreach ($relationKeys as $relationKey => $relationClosure) {
                if (!is_integer($relationKey)) {
                    continue;
                }

                unset($relationKeys[$relationKey]);

                $relationKeys[$relationClosure] = function () {
                    //
                };
            }

            if (!isset($includedKeys) && request()->has('fields')) {
                $includedKeys = request()->input('fields') ?? [];
            }

            if (is_string($includedKeys)) {
                $includedKeys = preg_split('/[^a-z0-9_.]/i', $includedKeys);
            } elseif (!is_array($includedKeys)) {
                $includedKeys = [];
            }

            $includedRelationKeys = array_filter($relationKeys, function ($relationKey) use ($includedKeys, $group) {
                return array_filter($includedKeys, function ($includedKey) use ($relationKey, $group) {
                    return (
                        ($group ? $group . '.' : '') . $relationKey === $includedKey ||
                        Str::startsWith($includedKey, ($group ? $group . '.' : '') . $relationKey . '.')
                    );
                });
            }, ARRAY_FILTER_USE_KEY);

            if (!$includedRelationKeys) {
                $includedRelationKeys = $relationKeys;
            }

            ksort($includedRelationKeys);
            $this->with($includedRelationKeys);

            return $this;
        });

        Builder::macro('allowedColumns', function (array $selectables, $group = null, $includedKeys = null) {
            $selectables = is_array($selectables) ? $selectables : [$selectables];

            foreach ($selectables as $selectableKey => $selectable) {
                if (!is_integer($selectableKey)) {
                    continue;
                }

                unset($selectables[$selectableKey]);
                preg_match('/[a-z0-9_]+$/', $selectable, $selectableMatches);
                $selectables[$selectableMatches[0]] = $selectable;
            }

            if (!isset($includedKeys) && request()->has('fields')) {
                $includedKeys = request()->input('fields', []);
            }

            if (is_string($includedKeys)) {
                $includedKeys = preg_split('/[^a-z0-9_.]/i', $includedKeys);
            } elseif (!is_array($includedKeys)) {
                $includedKeys = [];
            }

            $selectables = array_filter($selectables, function ($selectable, $selectableKey) use ($includedKeys, $group) {
                return in_array(($group ? $group . '.' : '') . $selectableKey, $includedKeys);
            }, ARRAY_FILTER_USE_BOTH);

            if (count($selectables) === 0) {
                return $this;
            }

            $columns = array_filter($selectables, function ($selectable) {
                return !Str::contains($selectable, ' ');
            });

            if (count($columns) > 0) {
                $this->addSelect(array_values($columns));
            }

            $rawExpressions = array_filter($selectables, function ($selectable) {
                return Str::contains($selectable, ' ');
            });

            foreach ($rawExpressions as $rawExpression) {
                $this->selectRaw($rawExpression);
            }

            return $this;
        });

        // TODO: appends

        Builder::macro('allowedFilters', function (array $filterKeys, $group = null, $includedKeys = null, $boolean = 'and') {
            if (is_string($filterKeys)) {
                $filterKeys = [$filterKeys];
            }

            foreach ($filterKeys as $filterKey => $filterData) {
                if (is_string($filterData)) {
                    $filterData = explode('|', $filterData);
                }

                if (count($filterData) === 1) {
                    $filterData = [$filterData[0], 'exact'];
                }

                $filterKeys[$filterKey] = $filterData;
            }

            foreach ($filterKeys as $filterKey => $filterData) {
                if (!is_integer($filterKey)) {
                    continue;
                }

                unset($filterKeys[$filterKey]);
                preg_match('/[a-z0-9_]+$/', $filterData[0], $filterDataMatches);
                $filterKeys[$filterDataMatches[0]] = $filterData;
            }

            if (!isset($includedKeys) && request()->has('filter')) {
                $includedKeys = request()->input('filter') ?? [];
            }

            if (!is_array($includedKeys)) {
                $includedKeys = [];
            }

            $filterKeys = array_filter($filterKeys, function ($filterData, $filterKey) use ($includedKeys, $group) {
                if (isset($filterData[2])) {
                    return true;
                }

                return array_filter($includedKeys, function ($includedKey) use ($filterKey, $group) {
                    return ($group ? $group . '.' : '') . $filterKey === $includedKey;
                }, ARRAY_FILTER_USE_KEY);
            }, ARRAY_FILTER_USE_BOTH);

            ksort($filterKeys);

            foreach ($filterKeys as $filterKey => $filterData) {
                if (isset($includedKeys[$filterKey])) {
                    if (in_array($filterData[1], ['in', 'not_in'])) {
                        if (is_string($includedKeys[$filterKey])) {
                            $includedKeys[$filterKey] = explode(',', $includedKeys[$filterKey]);
                        }

                        foreach ($includedKeys[$filterKey] as $includedFilterValue) {
                            if (!is_string($includedFilterValue)) {
                                abort(400, 'The `' . $filterData[1] . '` filter contains unallowed array in array');
                            }
                        }
                    } else {
                        if (is_array($includedKeys[$filterKey])) {
                            abort(400, 'The `' . $filterData[1] . '` filter should be a string');
                        }
                    }
                }
            }

            foreach ($filterKeys as $filterKey => $filterData) {
                $filterValue = $includedKeys[($group ? $group . '.' : '') . $filterKey] ?? $filterData[2] ?? null;

                switch ($filterData[1]) {
                    case 'exact': {
                        $this->where($filterData[0], '=', $filterValue, $boolean);

                        continue;
                    }
                    case 'like': {
                        $this->where($filterData[0], 'like', '%' . $filterValue . '%', $boolean);

                        continue;
                    }
                    case 'like_splitted': {
                        $this->where(function ($where) use ($filterData, $filterValue, $boolean) {
                            foreach (preg_split('/\s+/', $filterValue) as $filterValuePart) {
                                $where->where($filterData[0], 'like', '%' . $filterValuePart . '%', $boolean);
                            }
                        });

                        continue;
                    }
                    case 'like_start': {
                        $this->where($filterData[0], 'like', '%' . $filterValue, $boolean);

                        continue;
                    }
                    case 'like_end': {
                        $this->where($filterData[0], 'like', $filterValue . '%', $boolean);

                        continue;
                    }
                    case 'in': {
                        $this->whereIn($filterData[0], $filterValue, $boolean);

                        continue;
                    }
                    case 'not_in': {
                        $this->whereIn($filterData[0], $filterValue, $boolean, true);

                        continue;
                    }
                }
            }

            return $this;
        });

        Builder::macro('orAllowedFilters', function (array $filterKeys, $group = null, $includedKeys = null) {
            return $this->allowedFilters($filterKeys, $group, $includedKeys, 'or');
        });

        Builder::macro('allowedOrders', function (array $orderKeys, $defaultOrderKeys = null, $group = null, $includedKeys = null) {
            foreach ($orderKeys as $orderKey => $orderColumn) {
                if (!is_integer($orderKey)) {
                    continue;
                }

                unset($orderKeys[$orderKey]);
                preg_match('/[a-z0-9_]+$/', $orderColumn, $orderColumnMatches);
                $orderKeys[$orderColumnMatches[0]] = $orderColumn;
            }

            foreach ($orderKeys as $orderKey => $orderColumn) {
                $orderKeys['-' . $orderKey] = $orderColumn;
            }

            if (!isset($includedKeys) && request()->has('order')) {
                $includedKeys = request()->input('order') ?? [];
            }

            if (is_string($includedKeys)) {
                $includedKeys = preg_split('/[^a-z0-9_.-]/i', $includedKeys);
            } elseif (!is_array($includedKeys)) {
                $includedKeys = [];
            }

            $includedOrderKeys = [];

            foreach ($includedKeys as $includedKey) {
                $includedOrderKeys = array_merge($includedOrderKeys, array_filter($orderKeys, function ($orderKey) use ($includedKey) {
                    return $orderKey === $includedKey || Str::startsWith($includedKey, $orderKey . '.');
                }, ARRAY_FILTER_USE_KEY));
            }

            if ($group) {
                $includedOrderKeys = array_filter($includedOrderKeys, function ($orderKey) use ($group) {
                    return Str::startsWith($orderKey, $group . '.') || Str::startsWith($orderKey, '-' . $group . '.');
                });

                $includedOrderKeys = array_map(function ($orderKey) use ($group) {
                    $orderKey = Str::replaceFirst($group . '.', '', $orderKey);
                    $orderKey = Str::replaceFirst('-' . $group . '.', '-', $orderKey);

                    return $orderKey;
                }, $includedOrderKeys);
            }

            if (!$includedOrderKeys && $defaultOrderKeys) {
                if (is_string($defaultOrderKeys)) {
                    $defaultOrderKeys = preg_split('/[^a-z0-9_-]+/', $defaultOrderKeys);
                }

                foreach ($defaultOrderKeys as $defaultOrderKey) {
                    if (isset($orderKeys[$defaultOrderKey])) {
                        $includedOrderKeys = [$defaultOrderKey => $orderKeys[$defaultOrderKey]];
                    }
                }
            }

            foreach ($includedOrderKeys as $orderKey => $orderColumn) {
                $this->orderBy($orderColumn, $orderKey[0] === '-' ? 'desc' : 'asc');
            }

            return $this;
        });

        Builder::macro('allowedPaginate', function ($counts, $defaultCount = null, $includedCount = null, $includedPage = null) {
            $counts = is_integer($counts) ? [$counts] : $counts;
            $counts = collect($counts)->sort();
            $minCount = $counts->first();
            $maxCount = $counts->last();
            $defaultCount = $defaultCount ?? $minCount;

            if (!isset($includedCount) && request()->has('count')) {
                $includedCount = (int) (request()->input('count') ?? 0);
            }

            if ($includedCount < $minCount || $includedCount > $maxCount) {
                $includedCount = $defaultCount;
            }

            return $this->paginate($includedCount, '*', 'page', $includedPage);
        });
    }
}
