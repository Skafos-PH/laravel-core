<?php

namespace Skafos\Traits;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

trait HandlesModelCrud
{
    use TransformsResponses, DecodesQueries;

    /**
     * The resource to be used.
     *
     * @var \Illuminate\Database\Eloquent\Model
     */
    protected $model;

    /**
     * The columns exluded from queries.
     *
     * @var array
     */
    protected $excludeFromQuery = [
        'id',
        'password',
        'remember_token',
        'deleted_at',
    ];

    /**
     * Display a listing of the resource.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        return $this->okResponse($this->queryModel($request));
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        $request->validate($this->createRules());

        $createdModel = $this->model->create($request->all());

        return $this->createdResponse($createdModel);
    }

    /**
     * Display the specified resource.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  integer  $id
     * @return \Illuminate\Http\Response
     */
    public function show(Request $request, $id)
    {
        return $this->okResponse(
            $this->loadRelations($request, $this->model->findOrFail($id))
        );
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  integer  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        $request->validate($this->updateRules());

        $isUpdated = $this->model->findOrFail($id)->update($request->all());

        return $this->noContentResponse($isUpdated);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  integer  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy(Request $request, $id)
    {
        $isDeleted = $this->model->findOrFail($id)->delete();

        return $this->noContentResponse($isDeleted);
    }

    /**
     * Get the rules for storing a created resource.
     *
     * @return array
     */
    protected function createRules()
    {
        return [];
    }

    /**
     * Get the rules for updating a specified resource.
     *
     * @return array
     */
    protected function updateRules()
    {
        return [];
    }

    /**
     * Query resource based on request parameters.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return array
     */
    protected function queryModel(Request $request, $model = null)
    {
        $model = $model ?: $this->model;

        $results = $this->getBuilder($request, $model)
            ->applyScopes($request, $model)
            ->applyBasicQueries($request, $model)
            ->applyCustomQueries($request, $model)
            ->transformResults($request, $model);

        return $this->loadRelations($request, $results);
    }

    /**
     * Get the appropriate builder for querying the resource.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  mixed  $model
     * @return $this
     */
    protected function getBuilder(Request $request, &$model)
    {
        $model = $model instanceof Builder ? $model : $model->query();

        return $this;
    }

    /**
     * Apply initial queries to model.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  mixed  $model
     * @return $this
     */
    protected function applyScopes(Request $request, &$model)
    {
        if (!$request->has('scope')) {
            return $this;
        }

        $scopes = explode(',', $request->query('scope'));

        foreach ($scopes as $scope) {
            try {
                $model = $model->{Str::camel($scope)}();
            } catch (\Exception $e) {
            }
        }

        return $this;
    }

    /**
     * Apply basic queries to model.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  mixed  $model
     * @return $this
     */
    protected function applyBasicQueries(Request $request, &$model)
    {
        return $this->applyWhere($request, $model)
            ->applyNot($request, $model)
            ->applySort($request, $model);
    }

    /**
     * Apply where conditions to model.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  mixed  $model
     * @param  array  $except
     * @return $this
     */
    protected function applyWhere(Request $request, &$model)
    {
        $where = array();
        $whereIn = array();

        $tableName = $this->model->getTable();
        $strict = filter_var($request->query('strict', false), FILTER_VALIDATE_BOOLEAN);

        $attributes = $this->getModelAttributes();

        $params = Arr::only($request->all(), $attributes);

        foreach ($params as $key => $value) {
            $tableKey = $tableName . '.' . $key;

            switch (Schema::getColumnType($tableName, $key)) {
                case 'boolean':
                    $where[] = [$tableKey, '=', filter_var($value, FILTER_VALIDATE_BOOLEAN)];
                    break;

                case 'integer':
                    $multiple = explode(',', $value);

                    if (count($multiple) > 1) {
                        $numbers = array_filter($multiple, function ($number) { 
                            return is_numeric($number); 
                        });
                        $whereIn[] = ['column' => $tableKey, 'in' => $numbers];
                    } else {
                        $where = array_merge($where, $this->decodeNumericQuery($value, $tableKey));
                    }
                case 'float':
                    $where = array_merge($where, $this->decodeNumericQuery($value, $tableKey));
                    break;

                case 'date':
                    $where = array_merge($where, $this->decodeDateQuery($value, $tableKey));
                    break;

                case 'datetime':
                    $where = array_merge($where, $this->decodeDateTimeQuery($value, $tableKey));
                    break;

                case 'string':
                case 'text':
                    $where[] = $strict ? [$tableKey, '=', $value] : [$tableKey, 'like', '%' . $value . '%'];
                    break;
            }
        }

        count($where) > 0 && $model->where($where);

        if (count($whereIn) > 0) {
            foreach ($whereIn as $condition) {
                $model->whereIn($condition['column'], $condition['in']);
            }
        }

        return $this;
    }

    /**
     * Apply not conditions to model.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  mixed  $model
     * @return $this
     */
    protected function applyNot(Request $request, &$model)
    {
        if (!$request->has('not')) {
            return $this;
        }

        $notParameters = explode('|', $request->query('not'));

        foreach ($notParameters as $n) {
            $not = explode(':', $n);

            if (count($not) == 1) {
                $model->whereNotIn('id', explode(',', $not[0]));
            } elseif (count($not) == 2 && Schema::hasColumn($this->model->getTable(), $not[0])) {
                $model->whereNotIn($not[0], explode(',', $not[1]));
            }
        }

        return $this;
    }

    /**
     * Apply sorting conditions to model.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  mixed  $model
     * @return $this
     */
    protected function applySort(Request $request, &$model)
    {
        if (!$request->has('sort')) {
            return $this;
        }

        $sortParameters = explode(',', $request->query('sort'));

        foreach ($sortParameters as $sp) {
            $sort = explode(':', $sp);

            if (Schema::hasColumn($this->model->getTable(), $sort[0])) {
                $model->orderBy($sort[0], count($sort) == 2 ? $sort[1] : 'asc');
            } elseif (Str::endsWith($sort[0], '_count')) {
                try {
                    $model->withCount(preg_replace('/_count$/', '', $sort[0]))
                        ->orderBy($sort[0], count($sort) == 2 ? $sort[1] : 'asc');
                } catch (\Exception $e) {}
            }
        }

        return $this;
    }

    /**
     * Apply custom queries to model.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  mixed  $model
     * @return $this
     */
    protected function applyCustomQueries(Request $request, &$model)
    {
        return $this;
    }

    /**
     * Transform results to based on query.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  mixed  $model
     * @return array
     */
    protected function transformResults(Request $request, $model)
    {
        $page = $request->query('page');
        $limit = $request->query('limit');
        $take = $request->query('take');
        $return = $request->query('return');
        $unique = $return && strpos($return, 'unique:') === 0;

        switch ($return) {
            case 'count':
                return $model->count();

            case 'first':
                return $model->first();

            default:
                if ($unique) {
                    list(, $column) = explode(':', $return);

                    return $model->select($column)->distinct()->get();
                }

                if (is_numeric($page) && is_numeric($limit)) {
                    return $model->paginate($limit);
                }

                if (is_numeric($take)) {
                    return $model->take($take)->get();
                }

                return $model->get();
        }
    }

    /**
     * Load relations of results.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  mixed  $results
     * @return array
     */
    protected function loadRelations(Request $request, $results)
    {
        if (!$request->has('with') || !$results) {
            return $results;
        }

        $relations = explode(',', $request->query('with'));

        foreach ($relations as $relation) {
            try {
                $results->load(Str::camel($relation));
            } catch (\Exception $e) {
            }
        }

        return $results;
    }

    /**
     * Get all of the model's attributes.
     *
     * @return array
     */
    protected function getModelAttributes()
    {
        return Arr::except(Schema::getColumnListing($this->model->getTable()), $this->excludeFromQuery);
    }
}
