<?php

namespace App\Http\Resources;

use App\Models\Interfaces\Identifiable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;


/**
 * Class BasicResource
 * @package App\Http\Resources
 *
 * @property Model $resource
 *
 * To filter attributes in the BackEnd side of the site
 * 1) on single resource:
 *      static::make($model)->include($includes)->exclude($excludes);
 * 2) on resource collection:
 *      static::collection($models, $includes, $excludes);
 *      static::collection($models, $request) - in $request we can have include_on_{...} ... parameters to be applied to
 *          the collection members
 *
 * To filter attributes from the FrontEnd side:
 *      add to the request key '<static::INCLUDE_ON><model datatable name>' with value $includes (e.g. include_on_ports='cableEnd')
 *      add to the request key '<static::EXCLUDE_FROM><model datatable name>' with value $includes
 *          (e.g. include_on_ports='all_attributes'&exclude_from_ports='cableEnd' or only exclude_from_ports='cableEnd').
 */
class BasicResource extends JsonResource
{
    //prefixes in front of model's table, used for passing attribute/set names in the request
    const INCLUDE_ON = 'include_on_';
    const EXCLUDE_FROM = 'exclude_from_';

    //delimiters
    const ALIAS_DELIMITER   = ':';
    const LIST_DELIMITER    = ',';

    //prefix for the named set getter name.
    const NAMED_SET_PREFIX = 'namedSet';

    //Keys to access the out-of-the-box named attribute sets
    const DEFAULT_ATTRIBUTES = 'default_attributes';//see namedSetDefaultAttributes()
    const PROPER_ATTRIBUTES  = 'proper_attributes'; //see namedSetProperAttributes()
    const ALL_ATTRIBUTES  = 'all_attributes'; //see namedSetAllAttributes()


    /**
     * Hide some fields from the response
     * @var array
     */
    protected array $hiddenFields = [
        'created_at',
        'updated_at',
        'deleted_at',
    ];
    
    protected array $hideFields = [];

    /**
     * @var array
     */
    protected array $including = [];
    /**
     * @var array
     */
    protected array $excluding = [];

    /**
     * @var bool
     */
    protected bool $camelCased = true;


    /**
     * Transform the resource into an array.
     *
     * @param Request $request
     * @return array
     */
    public function toArray($request)
    {
        $this->hiddenFields = array_merge($this->hiddenFields, $this->hideFields);
        return $this->evaluateAttributes($request);
    }


    /**
     * Create new anonymous resource collection, allowing for custom serialization to be specified,
     * by providing names of resource attributes and/or attribute sets to be included and/or excluded.
     * Also, $request can be passed as second argument, which allow all FE filters to be applied to the members of collection
     *
     * @param mixed $resource
     * @param null|array|Request $include
     * @param null|array $exclude
     * @return AnonymousResourceCollection
     */
    public static function collection($resource, $include = null, $exclude = null)
    {
        if ($include === null && $exclude === null) {
            return parent::collection($resource);
        }

        if ($include instanceof Request) {
            return tap(
                parent::collection($resource),
                fn($parent) => $parent->collection->each->toArray($include)
            );
        }

        return tap(
            parent::collection($resource),
            fn($parent) => $parent->collection->each(
                fn($resource) => $resource->include($include)->exclude($exclude)
            )
        );
    }


    /**
     * @param null|string|string[] $attributes
     * Key(s) to include in the returned resource.
     *
     * @return BasicResource
     */
    public function include($attributes): self
    {
        return $this->addAttributesToList($attributes ?: [], 'including');
    }


    /**
     * @param null|string|string[] $attributes
     * Key(s) to exclude from the returned resource.
     *
     * @return BasicResource
     */
    public function exclude($attributes): self
    {
        return $this->addAttributesToList($attributes ?: [], 'excluding');
    }

    public function hideFields($fields)
    {
        $fields = is_array($fields) ? $fields : func_get_args();
        $this->hiddenFields = array_merge($this->hiddenFields, $fields);
    }

    /**
     * @return array
     */
    public function namedSetModelAttributes(): array
    {
        return array_keys($this->resource->attributesToArray());
    }

    /**
     * All possible attributes to be included in the serialization.
     *
     * The default implementation computes them from the definition.
     *
     * @return array
     */
    public function namedSetAllAttributes(): array
    {
        return array_keys($this->getDefinition(null));
    }


    /**
     * The attributes to be included in the serialization by default,
     * that is when nothing else was specified.
     *
     * The default implementation returns the proper attributes.
     *
     * @return array
     */
    public function namedSetDefaultAttributes(): array
    {
        return $this->namedSetProperAttributes();
    }


    /**
     * The attributes of $this->resource itself (assumes an Eloquent Model),
     * without any relations or computed attributes added in the static class.
     *
     * The default implementation returns camel-cased versions of
     * all its db and appended computed-via-accessors attributes,
     * without the ones, specified as hidden.
     *
     * @return array
     */
    public function namedSetProperAttributes(): array
    {
        if (! $this->resource) return [];
        $this->resource->makeHidden($this->hiddenFields);

        $visible = $this->resource->getVisible();
        $attributes = empty($visible)
            ? Schema::getColumnListing($this->resource->getTable())
            : $visible;
        return array_diff($attributes, $this->resource->getHidden());
    }

    /**
     * Attributes for select list
     * @return string[]
     */
    public function namedSetForList(): array
    {
        return [
            'id',
            'name',
            'label'
        ];
    }

    /**
     * Attributes, to append in all resources
     * @return string[]
     */
    public function getPermanentAttributes(): array
    {
        return [
            'id' => $this->resource->getKey(),
            'uuid' => $this->resource instanceof Identifiable ? $this->resource->getUuid() : '',
        ];
    }

    /**
     * Populates include/exclude properties to be able to calculate wanted attributes
     *
     * @param $request
     */
    protected function evaluate($request)
    {
        $this->include($request->get($this->getIncludeKey(), empty($this->including) ? static::DEFAULT_ATTRIBUTES : []));
        $this->exclude($request->get($this->geExcludeKey(), []));
    }

    /**
     * Constructs a serialization of $this->resource,
     * by including only the dynamically specified and potentially aliased attributes,
     * and lazily evaluating their values from the resource serialization definition.
     * Meant to be returned from toArray($request)
     * @param $request
     * @return array
     */
    protected function evaluateAttributes($request): array
    {
        $this->evaluate($request);

        $definition = $this->getDefinition($request);
        $evaluatableAttributes = $this->getEvaluatableAttributes();

        $evaluatedResource = [];
        foreach ($evaluatableAttributes as $definitionKey => $responseKey) {
            if (array_key_exists($definitionKey, $definition)) {
                $evaluatedResource[$responseKey] = value($definition[$definitionKey]);
            }
        }
        return $evaluatedResource;
    }


    /**
     * @return array
     * An associative array containing the attribute names,
     * requested by the FE for the resource serialization.
     *
     * Key is the resource definition key,
     * value is the name the FE asks to see instead of the key.
     */
    protected function getEvaluatableAttributes(): array
    {
        $include = array_merge(
            array_combine(array_keys($this->getPermanentAttributes()), array_keys($this->getPermanentAttributes())),
            $this->including
        );

        return array_diff_key($include, $this->excluding);
    }

    /**
     * @param Request|null $request
     * @return array
     * An array of all possibly present attributes in the returned resource.
     * This is what would have been returned by the parent::toArray() method,
     * however extracted, to support aliases and evaluating closures
     * (evaluated in the static::evaluateAttributes() method and returned by self::toArray()).
     *
     * Default implementation is the camel-cased serialization of the model's attributes.
     */
    protected function getDefinition($request): array
    {
        if (! $this->resource) return [];
        $this->resource->makeHidden($this->hiddenFields);

        return $this->toCamelCase(
            array_merge($this->getPermanentAttributes(), $this->resource->attributesToArray())
        );

        //return [
        //    //'fooBar'  => $this->foo_bar,//assuming there is a foo_bar attribute in $this->resource
        //    //'baz'     => fn() => BazResource::collection($this->baz()->whereFoo('bar')->get()),//scoped relationship baz on $this->resource (default serialization)
        //    //'bazAll'  => fn() => BazResource::collection($this->baz),//unscoped relationship baz on $this->resource (default serialization)
        //    //'bazWithNested => fn() => BazResource::collection($this->baz, $include, $exclude),//unscoped relationship baz on $this->resource (custom serialization)
        //];
    }


    /**
     * Get the request key holding the list of items to include in the resource.
     * This default implementation assumes $this->resource instanceof Model.
     * @return string
     */
    protected function getIncludeKey(): string
    {
        return $this->resource
            ? static::INCLUDE_ON . $this->resource->getTable()
            : '';
    }

    /**
     * Get the request key holding the list of items to exclude from the resource.
     * This default implementation assumes $this->resource instanceof Model.
     * @return string
     */
    protected function geExcludeKey(): string
    {
        return $this->resource
            ? static::EXCLUDE_FROM . $this->resource->getTable()
            : '';
    }

    /**
     * Recursively converts resource keys to camelCase
     * @param array $attributes
     * @return array
     */
    protected function toCamelCase(array $attributes): array
    {
        $convertedAttributes = [];
        foreach ($attributes as $key => $value) {
            $key = Str::camel($key);
            if (is_array($value)) {
                $convertedAttributes[$key] = $this->toCamelCase($value);
                continue;
            }
            $convertedAttributes[$key] = $value;
        }
        return $convertedAttributes;
    }


    /**
     * @param string|string[] $attributes
     * The string may be
     * 1) the name of
     *   a. - an attribute of the resource (e.g = name)
     *   b. - a computed attribute in the static class (e.g =id,name,position), or
     *   c. - a set of attributes in the static class
     *   (accessed via a getter in the static class) (e.g =namedSetSome);
     * 2) a LIST_DELIMITER-(comma-)separated list of the above. (e.g =namedSetSome,name,position)
     *
     * 1)a. and 1)b. may be aliased by separating with ALIAS_DELIMITER
     *      the definition name and the alias. (e.g =position:pos)
     *
     * @param string $list
     * The list which will be modified.
     * It is either 'including', or 'excluding'.
     *
     * @return BasicResource
     */
    private function addAttributesToList($attributes = [], string $list = 'excluding'): self
    {
        $attributes = is_string($attributes) ? explode(static::LIST_DELIMITER, $attributes) : $attributes;

        $attributes = $this->parseAttributes($attributes);

        $attributes = $this->aliasAttributes($attributes);

        //add to the already present items in the list
        $this->{$list} = array_merge($this->{$list}, $this->camelCased
            ? collect($attributes)->mapWithKeys(fn ($attribute, $key) => [Str::camel($key) => Str::camel($attribute)])->all()
            : $attributes
        );

        return $this;
    }

    /**
     * We can provide alias for attributes
     *
     * @param array $parsedAttributes
     * @return array
     */
    private function aliasAttributes(array $parsedAttributes): array
    {
        $attributes = [];
        foreach ($parsedAttributes as $attribute) {
            $key = $name = null;
            if (Str::contains($attribute, static::ALIAS_DELIMITER)) {
                [$key, $name] = explode(static::ALIAS_DELIMITER, $attribute);
            } else {
                $key = $attribute;
            }
            $attributes[$key] = $name ?: $key;
        }
        return $attributes;
    }

    /**
     * Separate sets from attributes (2-levels deep)
     *
     * @param $attributes
     * @return array
     */
    private function parseAttributes($attributes): array
    {
        $parsedAttributes = [];

        foreach ($attributes as $attribute) {
            $innerList = explode(static::LIST_DELIMITER, $attribute);
            foreach ($innerList as $innerAttribute) {
                if (method_exists($this, $method = static::NAMED_SET_PREFIX . Str::studly($innerAttribute))) {
                    $parsedAttributes = array_merge($parsedAttributes, $this->parseSet($this->{$method}()));
                } else {
                    $parsedAttributes[] = $innerAttribute;
                }
            }
        }
        return $parsedAttributes;
    }

    /**
     * When we use namedSet, and have some Resource to be parsed in one of wanted attributes,
     *      we can provide a `deeply nested` filters to the specified in the definition Request
     * IMPORTANT: This will modify Illuminate\Http\Request $request object
     *
     * @example:
     *      In the definition we have attributes which is processed with Resources
     *      function getDefinition() => [
     *          ...
     *          'wantedAttribute' => fn() => SomeResource::make($model),
     *          'otherWantedAttribute' => fn() => SomeOtherResource::collection($models),
     *          ...
     *      ]
     *
     *      so, we can filter this attribute providing filters like in the top-level once:
     *
     *      function namedSetSome() => [
     *          ...
     *          'wantedAttribute' => ['attribute_table_name' => [
     *                  self::INCLUDE_ON => 'namedSet,some_attribute_not_in_the_named_set,otherAttribute,
     *                  self::EXCLUDE_FROM => 'namedSet,some_attribute_not_in_the_named_set,otherAttribute
     *          ],
     *          'regularAttribute,
     *          ...
     *      ]
     *
     * @param array $namedSet
     * @return array
     */
    protected function parseSet(array $namedSet)
    {
//        [$extended, $regular] = collect($namedSet)->partition(fn($definition) => is_array($definition));
//
//        /** @var Collection $extended */
//        return $extended
//            ->each(fn($definition) => $this->modifyRequest($definition))
//            ->keys()
//            ->merge($regular);

        $definitions = [];

        foreach ($namedSet as $key => $definition) {
            if (is_array($definition)) {
                $this->modifyRequest($definition);
                $definitions[] = $key;
            } else {
                $definitions[] = $definition;
            }
        }

        return $definitions;
    }

    /**
     *
     * @param array $item
     * @return void
     */
    protected function modifyRequest(array $item)
    {
        $key = array_key_first($item);
        foreach ($item[$key] as $type => $fields) {
            \request()->merge([
                ($type . $key) => $fields
            ]);
        }
    }
}
