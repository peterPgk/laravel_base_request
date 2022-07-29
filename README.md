# Laravel Base Resource

## Dynamically build needed resources from FrontEnd side

The idea of the package is to upgrade Laravel JsonResource to be able dynamically, at run-time
to construct FE needed data.

You need only one Resource declaration, where with closures all possible properties/resources will be declared.

```php
/**
 * table for SomeModel is some_model
 * table for OtherModel is other_model
 * table for ThirdModel is third_model
 */
class SomeModelResource extends BasicResource
{
    protected function getDefinition($request): array
    {
        return [
            'id' => fn () => $this->id,
            'name' => fn() => $this->name,
            'someOtherProp' => fn() => $this->some_other_prop,
            'otherResource' => fn() => OtherModelResource::make($this->other_resource),
            'collectionResource' => fn() => ThirdModelResource::collection($this->other_resource)
            ...
        ]
    }
}
```

### Group repetitive props in `namedSets`

```php
public function namedSetSomeName()
{
    return [
        'id',
        'collectionResource' => [
            'third_model' => [
            //Wanted props from nested resource can be declared in BE or can be directly called from FE
                self::INCLUDE_ON => 'id,name', 
                self::EXCLUDE_ON => 'label'
            ]
        ],
    ]   
}
```

After definition declaration, resource can be used:

- `{url}?include_on_some_model=name,someOtherProp,otherResource`
returned resource will include `name`, `some_oter_prop` and all porps from `otherResource`
- `{url}?include_on_some_model=name,someOtherProp,otherResource&include_on_other_model=id,label,someForthRelationalResource`
same as above, but will filter `otherResource` to return only `id`, `label` ...
- `{url}?exclude_on_some_model=id`
will return all defined props except `id`
- `{url}?include_on_some_model=someName,otherProp`
will return props, defined in `someName` named set + all listed extra props
- 
