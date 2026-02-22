# Resolvers

Resolvers are a powerful feature of Kaly DI that allow you to fine-tune auto-wiring, especially when dealing with multiple parameters of the same type.

## When to use Resolvers

Auto-wiring usually works by looking up a service by its type hint. If you have multiple services for the same type (e.g., two different `PDO` connections), the container needs a rule to decide which one to inject.

## Resolver Patterns

### 1. Match by Parameter Name

Specify which service to use based on the name of the constructor parameter.

```php
// If a constructor has a parameter named $backupDb of type PDO, use the 'backup_connection' service
$definitions->resolve(PDO::class, 'backupDb', 'backup_connection');
```

### 2. Wildcard (Match All)

Apply a closure to resolve *all* parameters of a given type.

```php
// Every time a PDO instance is needed, use this logic to determine the ID
$definitions->resolveAll(PDO::class, function (string $name, string $class) {
    if ($name === 'readOnly') {
        return 'replica_db';
    }
    return 'main_db';
});
```

### 3. Match by Consuming Class

Specify which service to use when the dependency is requested by a specific class or interface implementation.

```php
// When MyRepository needs a PDO instance, give it 'main_db'
$definitions->resolve(PDO::class, MyRepository::class, 'main_db');

// When BackupService needs a PDO instance, give it 'backup_db'
$definitions->resolve(PDO::class, BackupService::class, 'backup_db');
```

## Priority and Ordering

Resolvers are evaluated in a strict, deterministic order of precedence. The first resolver that matches is used:

1. **Specific Parameter Name matches** (e.g., `'backupDb'`) - *Most specific*
2. **Consuming Class/Interface matches** (e.g., `MyRepository::class`) - *Contextual*
3. **Wildcard resolvers** (`*`) - *Global fallback*
