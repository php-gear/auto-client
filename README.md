# Auto Client
##### A command-line tool to generate a Javascript client-side API for a PHP REST API

## Introduction

This package provides a command-line tool for use either with the Laravel framework (as an Artisan command) or with the Electro framework (as a Workman command). 

*TO DO: provide an explanation of the rationale behind this library and some usage examples.*

> **Note:** currently, this tool generates code for use with AngularJS 1.x, and it expects an Angular service called `remote` of type `RemoteService`.
> <p>This requirement is temporary and it will be removed on a later version of this tool.

## Documentation

Additional documentation will be written, as soon as I find the time for it.

## Installation

#### Runtime requirements

- PHP >= 7.0
- AngularJS 1.x

##### Optionally, one of

- Laravel >= 4.2
- Electro >= 1.0

### Installing on Laravel

On the command-line, type:

```sh
composer require php-gear/auto-client
```

Register the Artisan command on `artisan.php`:

```php
Artisan::resolve (PhpGear\AutoClient\Laravel\AutoClientCommand::class);
```

### Installing on Electro

```sh
workman install php-gear/auto-client
```

## Usage

### On Laravel

#### Configuring

On `config/app.php`, define the APIs to be exported to Javascript.

Example:

```php
return [
  'autoclient' => [
    'APIs' => [
      // Endpoint URL => [controller class, target directory, Angular module name] 
      'API/something' => [SomethingController::class, 'App/remote', 'App'],
    ],
  ],
  ///... the rest of the existing file
];
```

#### Running the generator

```sh
artisan autoclient:generate
```

### On Electro

#### Running the generator

```sh
workman autoclient:generate
```

## License

This library is open-source software licensed under the **MIT license**.

See the accompanying `LICENSE` file.

Copyright &copy; 2018 Cláudio Silva
