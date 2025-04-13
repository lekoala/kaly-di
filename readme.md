# Kaly DI

[![Latest Stable Version](http://poser.pugx.org/lekoala/kaly-di/v)](https://packagist.org/packages/lekoala/kaly-di) [![Total Downloads](http://poser.pugx.org/lekoala/kaly-di/downloads)](https://packagist.org/packages/lekoala/kaly-di) [![Latest Unstable Version](http://poser.pugx.org/lekoala/kaly-di/v/unstable)](https://packagist.org/packages/lekoala/kaly-di) [![License](http://poser.pugx.org/lekoala/kaly-di/license)](https://packagist.org/packages/lekoala/kaly-di) [![PHP Version Require](http://poser.pugx.org/lekoala/kaly-di/require/php)](https://packagist.org/packages/lekoala/kaly-di)

> Minimalist and Modern Dependency Injection Container

Kaly DI is a lightweight and flexible dependency injection (DI) container designed for modern PHP applications.

**Key Features**

* **PSR-11 Compliance:**  Fully compatible with the PSR-11 Container Interface, ensuring interoperability with other PSR-11 compliant libraries and frameworks.
* **Clean and Minimalist Design:**  Kaly DI avoids unnecessary complexity, keeping the core concepts simple and easy to understand.
* **No Magic, No Attributes:**  Kaly DI intentionally avoids reliance on annotations or attributes. This prevents tight coupling between your code and the DI container, promoting a cleaner architecture and greater flexibility, in line with PSR-11 principles.
* **PHP-Based Definitions:** Define your dependencies exclusively in PHP code using a powerful `Definitions` object. This enables strong type checking, autocompletion, and refactoring support from your IDE.
* **Auto-wiring:** Your dependencies are injected automatically based on their types. Auto-wiring can be fine tuned using `resolvers`.
* **Invariable Results:**  Calling `->get()` with the same identifier will consistently return the same object instance, providing predictable behavior and efficient resource management. If you need a new instance (bypassing the cache), you can define a factory closure in your definitions or, alternatively, clone the container to get a completely fresh state.
* **Powerful Injector:** The built-in injector allows you to dynamically call methods, automatically injecting dependencies from the container or through provided arguments. This facilitates seamless integration and flexible code execution.
* **Focus on Performance:** The implementation is very light and is designed to be very fast.
* **Clear Error Reporting:** Provides helpful error messages to simplify debugging issues.
* **Easy to use:** Minimal amount of code to start using it, and all of it is highly readable.

**Example (Quick Start)**

```php
use Kaly\DI\Container;
use Kaly\DI\Definitions;

// Define dependencies
$definitions = Definitions::create()
    ->set(\PDO::class, new \PDO('sqlite::memory:')) // Define a PDO service

// Create the container
$container = new Container($definitions);

// Get an instance of MyClass that is using the PDO service
$myObject = $container->get(MyClass::class);

// Now you can use the object
// $myObject->someMethod();
```

## Installation

```
composer require lekoala/kaly-di
```

## Using definitions

There is not much to say about the container itself since it has only `get` and `has` methods.

Most of the configuration is done through the `Definitions` class.

### Setting services

Simply use the `set` with the service id and the value.

Typically, the id is the name of the class or the interface, but it can be any string.
Using the name of the class allows the container to use it to resolve types.

The value is typically a class-string or a Closure, but you can register actual object instances.

Services can also be set conditionally (see `setDefault` or `miss` methods).

### Binding interfaces

Use `add`, `bind` or `bindAll` to bind a given object/class to one or multiple interfaces. When requesting
that interface from the container, you will get the proper object.

### Setting parameters

You can use the `parameter`, `parameters` and `parametersArray` methods to set parameters
for a given service.

In order to reference a given id from the container, use the `containerParameter` method or wrap
the id in the `ServiceName` class.

```php
  $di = new Container(Definitions::create()
            // Will resolve parameter 'v' with TestObject6. It could also be any custom id from the container.
            ->containerParameter(TestZIntersectionClass::class, 'v', TestObject6::class)
            ...
```

For more advanced way to deal with parameters, use resolvers (see below).

### Adding callbacks

You can configure specific callbacks that can apply further configuration on a given object.

These callbacks can be set by:
- Interface
- Class
- Parent class
- Service name

If multiple callbacks apply to an object (e.g., one for an interface it implements and another for its concrete class), they are all executed in a deterministic order (e.g., interface callbacks before class callbacks).

### Resolve complex parameters

You can define "resolvers" which can determine how a given parameter class is resolved. There are three main cases:

```php
// Case 1 : when resolving pdo classes, if the name is backupDb, using backupDb id
    ...->resolve(PDO::class, 'backupDb', 'backupDb')

// Case 2 : when resolving pdo classes, for any name, resolve using the closure
    ...->resolveAll(PDO::class, function (string $name, string $class) {...}

// Case 3 : when resolving pdo classes, if the interface is TestObjectTwoPdosInterface, resolve using the closure
    ...->resolve(PDO::class, TestObjectTwoPdosInterface::class, function (string $name, string $class) {...}
```

Resolvers primarily target named types. Intersection types usually represent specific combinations that are best provided explicitly via parameters or dedicated factory definitions.

### Merging definitions

You can also merge definitions together. This can be useful when collecting definitions from a
modular application. You can simply pass a `Definitions` object to another, or use the `merge` method.

```php
//module1/config.php

return Definitions::create()
    ->set('id', 'value')
    ->lock();

//module2/config.php

return Definitions::create()
    ->set('otherid', 'value')
    ->lock();

//app/config.php

foreach($modules as $module) {
    // if you manage priority or pass along current config, modules
    // could even define things conditionally using ->miss or ->setDefault
    $definitions = $definitions->merge($module->config());
}
```

## Getting fresh instances

Since all instances are cached, there are only two way to get "fresh" (new) objects from the container:

- Use factories inside the container: this is the recommended approach
- If not possible, simply clone the container to get a fresh container without any cached instances

## Injector

### Making classes

You can use `make` and `makeArray` to create classes (or classes from interfaces) with the Injector.

```php
$injector = new Injector();
$inst = $injector->make(MyClass::class, v: 'test');
$inst = $injector->makeArray(MyClass::class, ['v' => 'test', 'v2' => 'test2']);
```

You can provide a Container to the Injector build classes from interfaces or provided missing arguments.

Note: make always returns a new instance, even when using a container. 
It uses the container only to resolve missing constructor arguments that weren't provided directly. 
If you need the container's potentially cached instance (if one exists for that ID), use `$container->get()` instead.

```php
$injector = new Injector($container);
$inst = $injector->make(MyClass::class);
// if $container->has(MyInterface::class)
$inst = $injector->make(MyInterface::class);
```

### Calling functions

You can call arbitrary callable with `invoke` and `invokeArray`.

```php
$injector = new Injector();
$inst = $injector->invoke($fn, v: 'test');
$inst = $injector->invokeArray($fn, ['v' => 'test', 'v2' => 'test2']);
```

## More examples

Check the unit test for various use cases
