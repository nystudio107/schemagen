# SchemaGen Changelog

## 1.0.3 - UNRELEASED
### Added
* Added `ecs` & `phpstan` code analysis tools
* Added the `code-analysis.yaml` GitHub action

### Changed
* Each `@var` type can also be an array of that schema type
* Moved the source to `src/`
* Remove the Craft version and copyright year from the headers
* ecs code cleanup
* phpstan code cleanup

### Fixed
* Fixed an issue where `Schema` renamed classes were not properly named in `@var` tags

## 1.0.2 - 2023.02.01
### Changed
* Code cleanup
* Better Makefile interface to the Docker container
* Use `8.1-rc-cli-alpine3.17` Docker image

## 1.0.1 - 2022.06.22
### Added
* Refactor to use [`nette/php-generator`](https://github.com/nette/php-generator) for code generation

## 1.0.0 - 2022.05.18
### Added
* Initial release
