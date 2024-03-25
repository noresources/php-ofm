noresources/ofm
===========
Object to file mapping. A Doctrine Persistence API implementation
that serialize object to structured text files.

## Installation

```bash
composer require noresources/ofm
```

## Features
* Directory and filename mapping strategy interface
  * Class name based directory mapping strategy implementation
  * Object identifier based file name mapping implementation
* ObjectManager implementation using file serialization interfaces from [NoreSources Data](https://github.com/noresources/ofm)
* Configuration and setup utility similar to Doctrine ORM

## References
* [Doctrine ORM](https://github.com/doctrine/orm)
* [NoreSources Persistence](https://github.com/noresources/php-persistence)
* [NoreSources Data](https://github.com/noresources/ns-php-data)
