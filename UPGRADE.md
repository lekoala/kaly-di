# Upgrade guide

## After 0.5

Remove the provenance argument from `set()`, `bind()`, `bindConvention()` and
`rebind()`, including calls using `source:`. Remove reads of `sourceFor()`.
Kaly already reports module ownership at composition time. If a standalone
application needs ownership labels, track them where it assembles definitions.
The container's conflict diagnostics still identify the conflicting service ids
or convention templates.

```php
// Before
$di->bind(StorageInterface::class, DatabaseStorage::class, 'core');

// After
$di->bind(StorageInterface::class, DatabaseStorage::class);

// With constructor arguments for this binding
$di->bind(StorageInterface::class, DatabaseStorage::class, [
    'table' => 'documents',
]);
```

`rebind()` keeps the existing configuration by default. Pass
`resetConfiguration: true` to clear the constructor parameters and callbacks
registered under the replaced id as part of the replacement.
