PHP PIPELINE ROUTER
=======================================

Router with pipelines for php projects. Working with PHP-DI & HttpFoundation from Symfony.

Install
-------

To install with composer:

```sh
composer require mklyuev/php-pipeline-router
```

Requires PHP 7.1 or newer.

#### Example

```php
$router = new Router();

$router->get('users/{id}', function (Request $request, Response $response) {
    $response->setContent(json_encode([
        'user' => $request->get('id')
    ]));
    
    $response->send();
});

$router->post('users', 'App\Controllers\UsersController@create', [
    CheckForAdminRights::class,
    ValidatePostUserData::class
]);

$router->get('users', 'App\Controllers\UsersController@getList');

$request = Request::createFromGlobals();

$router->handle($request);
```

#### Custom DI container
```php
$container = (new Container);
$container->set('Doctrine\ORM\EntityManagerInterface', $entityManager);

$router->setContainer($container);

```


