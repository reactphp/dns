# Changelog

## 0.4.3 (2016-07-31)

* Feature: Allow for cache adapter injection (#38 by @WyriHaximus)

  ```php
  $factory = new React\Dns\Resolver\Factory();

  $cache = new MyCustomCacheInstance();
  $resolver = $factory->createCached('8.8.8.8', $loop, $cache);
  ```

* Feature: Support Promise cancellation (#35 by @clue)

  ```php
  $promise = $resolver->resolve('reactphp.org');

  $promise->cancel();
  ```

## 0.4.2 (2016-02-24)

* Repository maintenance, split off from main repo, improve test suite and documentation
* First class support for PHP7 and HHVM (#34 by @clue)
* Adjust compatibility to 5.3 (#30 by @clue)

## 0.4.1 (2014-04-13)

* Bug fix: Fixed PSR-4 autoload path (@marcj/WyriHaximus)

## 0.4.0 (2014-02-02)

* BC break: Bump minimum PHP version to PHP 5.4, remove 5.3 specific hacks
* BC break: Update to React/Promise 2.0
* Bug fix: Properly resolve CNAME aliases
* Dependency: Autoloading and filesystem structure now PSR-4 instead of PSR-0
* Bump React dependencies to v0.4

## 0.3.2 (2013-05-10)

* Feature: Support default port for IPv6 addresses (@clue)

## 0.3.0 (2013-04-14)

* Bump React dependencies to v0.3

## 0.2.6 (2012-12-26)

* Feature: New cache component, used by DNS

## 0.2.5 (2012-11-26)

* Version bump

## 0.2.4 (2012-11-18)

* Feature: Change to promise-based API (@jsor)

## 0.2.3 (2012-11-14)

* Version bump

## 0.2.2 (2012-10-28)

* Feature: DNS executor timeout handling (@arnaud-lb)
* Feature: DNS retry executor (@arnaud-lb)

## 0.2.1 (2012-10-14)

* Minor adjustments to DNS parser

## 0.2.0 (2012-09-10)

* Feature: DNS resolver
