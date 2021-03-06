<?php

namespace Uteq\Move\Fields;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Uteq\Move\Facades\Move;
use Uteq\Move\Resource;

class Select extends Field
{
    public string $component = 'select-field';

    public $options;

    public $resourceName = null;

    public ?\Closure $customIndexName = null;

    public array $settings = [];

    public bool $multiple = false;

    public $query = null;

    public $customQuery = null;

    public ?string $ajaxUrl = null;

    protected static $resourceCache = [];

    public $version = 1;

    public function settings(array $settings)
    {
        $this->settings = array_replace($this->settings, $settings);

        return $this;
    }

    public function indexName(\Closure $indexName): self
    {
        $this->customIndexName = $indexName;

        return $this;
    }

    public function showResourceUrl(): ?string
    {
        if (! Move::resolveResource($this->resourceName)->can('view')) {
            return null;
        }

        if (! $this->value) {
            return null;
        }

        return route(move()::getPrefix() . '.show', [
            'resource' => $this->resourceRouteName(),
            'model' => $this->value,
        ]);
    }

    public function resourceRouteName(): string
    {
        return str_replace('.', '/', Move::getByClass($this->resourceName ?? null) ?? '');
    }

    public function resourceName(Model $model = null): ?string
    {
        $model ??= $this->resourceName
            ? $this->cachedResource($this->resourceName, $this->value, $model)
            : null;

        if (! $model) {
            return null;
        }

        if (! $this->resourceName) {
            return null;
        }

        if ($this->customIndexName) {
            $callback = $this->customIndexName;

            return $callback($model, $this);
        }

        $resourceName = $this->resourceName;

        return $resourceName::title($model);
    }

    public function cachedResource($name, $value, $model): ?Model
    {
        if (! isset(static::$resourceCache[$name][$value]) || empty(static::$resourceCache[$name][$value])) {
            static::$resourceCache[$name] ??= [];
            static::$resourceCache[$name][$value] = $name::$model::find($value);
        }

        return static::$resourceCache[$name][$value];
    }

    public function resource($resource): self
    {
        if (! class_exists($resource)) {
            throw new \Exception(sprintf(
                '%s: the given resource %s does not exist',
                __METHOD__,
                $resource
            ));
        }

        if (! is_subclass_of($resource, Resource::class)) {
            throw new \Exception(sprintf(
                '%s: the given resource %s is not a subclass of %s',
                __METHOD__,
                $resource,
                Resource::class
            ));
        }

        $this->resourceName = $resource;

        return $this;
    }

    public function options($options): self
    {
        $this->options = $options;

        return $this;
    }

    public function query($query): self
    {
        $this->query = $query;

        return $this;
    }

    protected function queryHandler($resourceName): Builder
    {
        $handler = $this->query;

        return $handler
            ? $handler($resourceName::relationQuery(), $this)
            : $resourceName::relationQuery();
    }

    public function getOptions(): array
    {
        $options = $this->options;

        $options = is_callable($options)
            ? $options($this ?? null)
            : $options;

        if (count($options ??= [])) {
            return $options;
        }

        if ($resourceName = $this->resourceName) {
            $customQuery = $this->customQuery ?? fn ($builder) => $builder;

            return $customQuery($this->queryHandler($resourceName))
                ->get()
                ->mapWithKeys(fn ($item) => [$item->getKey() => $this->resourceName($item)])
                ->toArray();
        }

        return [];
    }

    public function customQuery($customQuery): self
    {
        $this->customQuery = $customQuery;

        return $this;
    }

    public function ajax(string $url, $defaultOption = null, array $settings = []): self
    {
        is_callable($defaultOption)
            ? $this->resolveDefaultOption($defaultOption)
            : $this->options(fn () => $defaultOption);

        $this->settings = array_replace_recursive([
            'ajax' => [
                'url' => $url,
                'dateType' => 'json',
                'delay' => 250,
            ],
            'minimumInputLength' => 2,
            'language' => [
                'inputTooShort' => <<<JS
                    function() {
                        return 'Minimaal 2 karakters vereist';
                    }
                    JS
            ],
        ], $settings);

        return $this;
    }

    public function resolveDefaultOption($option): self
    {
        $this->options(function ($field) use ($option) {
            $store = isset($field->resource->store)
                ? $field->resource->store
                : $field->resource->getAttributes();

            return is_callable($option)
                ? $option($store[$this->attribute] ?? null)
                : $option;
        });

        return $this;
    }

    public function multiple($multiple = true)
    {
        $this->multiple = $multiple;

        if ($this->multiple) {
            $this->mapTags();
        } else {
            unset($this->beforeStore['multiple']);
        }

        return $this;
    }

    /**
     * This makes sure that when using the 'multiple' implementation the tags will be mapped to the correct value.
     */
    protected function mapTags()
    {
        $this->beforeStore(function ($value, $field, \Domain\Documents\Models\Document $model) {
            $model = (clone $model)->refresh();

            $value = collect($value)
                ->map(fn ($value) => $model->{$field}[$value] ?? $value)
                ->toArray();

            return $value;
        }, 'multiple');
    }

    public function values()
    {
        $fieldStore = $this->fieldStore();

        return $this->store() ?
            ($this->multiple ? $fieldStore : array_keys($fieldStore))
            : null;
    }

    public function fieldStore()
    {
        return is_array($this->store()) ? $this->store() : [$this->store() => true];
    }

    public function version($version)
    {
        $this->version = $version;

        return $this;
    }

    public function getVersion()
    {
        $version = $this->version;

        return is_callable($version)
            ? $version($this)
            : $version;
    }
}
