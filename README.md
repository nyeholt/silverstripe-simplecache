# SilverStripe SimpleCache module

## Maintainer

Marcus Nyeholt
<marcus (at) silverstripe (dot) com (dot) au>

## Documentation

Note: SS 2.4 compatible version available in the ss24 branch. It does NOT
have full capabilities as a replacement for static publisher

[GitHub Wiki](http://wiki.github.com/nyeholt/silverstripe-simplecache)

## Licensing

This module is licensed under the BSD license

## Requirements

* QueuedJobs module (http://github.com/nyeholt/silverstripe-queuedjobs)

## Usage

* Add the SimpleCachePublisher extension to a publishable data type and publish
  your page. 
* Change .htaccess to point to simplecache/cache-main.php instead of 
  sapphire/main.php
* Works in much the same way as static publisher, but uses a cache abstraction
  layer to allow for storing cached data in memcache or apc, or other cache
  platform. Uses a filesystem cache by default


