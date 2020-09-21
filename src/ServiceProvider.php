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
        $filterRelations = function ($relationKeys, $includedKeys, $group) {
            foreach ($relationKeys as $relationKey => $relationClosure) {
                if (!is_integer($relationKey)) {
                    continue;
                }

                unset($relationKeys[$relationKey]);

                $relationKeys[$relationClosure] = function () {
                    //
                };
            }

            if (!isset($includedKeys) && request()->has('include')) {
                $includedKeys = request()->input('include') ?? [];
            }

            if (isset($includedKeys)) {
                if (is_string($includedKeys)) {
                    $includedKeys = preg_split('/[^a-z0-9_.]/i', $includedKeys);
                } elseif (!is_array($includedKeys)) {
                    $includedKeys = [];
                }

                $relationKeys = array_filter($relationKeys, function ($relationKey) use ($includedKeys, $group) {
                    return array_filter($includedKeys, function ($includedKey) use ($relationKey, $group) {
                        return (
                            ($group ? $group . '.' : '') . $relationKey === $includedKey ||
                            Str::startsWith($includedKey, ($group ? $group . '.' : '') . $relationKey . '.')
                        );
                    });
                }, ARRAY_FILTER_USE_KEY);

                ksort($relationKeys);
            }

            return $relationKeys;
        };

        Builder::macro('allowedRelations', function (array $relationKeys, $group = null, $includedKeys = null) use ($filterRelations) {
            $relationKeys = $filterRelations($relationKeys, $includedKeys, $group);
            $this->with($relationKeys);

            return $this;
        });

        $filterFields = function ($fields, $includedKeys, $group) {
            $fields = is_array($fields) ? $fields : [$fields];

            foreach ($fields as $fieldKey => $fieldColumn) {
                if (!is_integer($fieldKey)) {
                    continue;
                }

                unset($fields[$fieldKey]);
                preg_match('/[a-z0-9_]+$/', $fieldColumn, $fieldColumnMatches);
                $fields[$fieldColumnMatches[0]] = $fieldColumn;
            }

            foreach ($fields as $fieldKey => $fieldColumn) {
                $fields[$fieldKey] = $fieldColumn;
            }

            if (!isset($includedKeys) && request()->has('fields')) {
                $includedKeys = request()->input('fields') ?? [];
            }

            if (isset($includedKeys)) {
                if (is_string($includedKeys)) {
                    $includedKeys = preg_split('/[^a-z0-9_.]/i', $includedKeys);
                } elseif (!is_array($includedKeys)) {
                    $includedKeys = [];
                }

                $fields = array_filter($fields, function ($fieldColumn, $fieldKey) use ($includedKeys, $group) {
                    return in_array(($group ? $group . '.' : '') . $fieldKey, $includedKeys);
                }, ARRAY_FILTER_USE_BOTH);
            }

            return $fields;
        };

        Builder::macro('allowedFields', function (array $fields, $group = null, $includedKeys = null) use ($filterFields) {
            $fields = $filterFields($fields, $includedKeys, $group);

            $columnFields = array_filter($fields, function ($fieldColumn) {
                return !Str::contains($fieldColumn, ' ');
            });

            if (count($columnFields) > 0) {
                $this->addSelect(array_values($columnFields));
            }

            $rawFields = array_filter($fields, function ($fieldColumn) {
                return Str::contains($fieldColumn, ' ');
            });

            foreach ($rawFields as $rawFieldExpression) {
                $this->selectRaw($rawFieldExpression);
            }

            return $this;
        });

        // Builder::macro('allowedSelectRaw', function ($field, $bindings = [], $group = null, $includedKeys = null) use ($filterFields) {
        //     $fields = $filterFields([$field], $includedKeys, null, $group);

        //     if (count($fields) > 0) {
        //         $this->selectRaw($field, $bindings);
        //     }

        //     return $this;
        // });

        // TODO: appends

        $filterFilters = function ($filterKeys, &$includedKeys, $group) {
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

            return $filterKeys;
        };

        $applyFilters = function ($filterKeys, $includedKeys, $group, $boolean) {
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
                    }
                    case 'not_in': {
                        $this->whereIn($filterData[0], $filterValue, $boolean, true);
                    }
                }
            }
        };

        Builder::macro('allowedFilters', function (array $filterKeys, $group = null, $includedKeys = null) use ($filterFilters, $applyFilters) {
            $filterKeys = $filterFilters($filterKeys, $includedKeys, $group);
            $applyFilters->call($this, $filterKeys, $includedKeys, $group, 'and');

            return $this;
        });

        Builder::macro('orAllowedFilters', function (array $filterKeys, $group = null, $includedKeys = null) use ($filterFilters, $applyFilters) {
            $filterKeys = $filterFilters($filterKeys, $includedKeys, $group);
            $applyFilters->call($this, $filterKeys, $includedKeys, 'or');

            return $this;
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
