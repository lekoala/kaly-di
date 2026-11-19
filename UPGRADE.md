# Upgrade guide

## After 0.5

`Definitions::bind()` accepts named constructor arguments as its third argument.
They belong to the abstract service id, giving bindings of the same concrete
class independent configurations at their declaration sites.

Move a positional provenance label to the named `source:` argument.

```php
// Before
$di->bind(StorageInterface::class, DatabaseStorage::class, 'core');

// After
$di->bind(StorageInterface::class, DatabaseStorage::class, source: 'core');

// With a constructor argument
$di->bind(StorageInterface::class, DatabaseStorage::class, [
    'table' => 'documents',
], source: 'core');
```
