# SchemaGen CLI Command

Generates PHP models representing schema.org JSON-LD types

## Requirements

The SchemaGen CLI tool is packaged via Docker container, so you'll need [Docker desktop](https://www.docker.com/products/docker-desktop) for your platform installed to run it.

## Installation

1. Clone the git repo with:
```
git clone https://github.com/nystudio107/schemagen.git
```

2. Go into the project's directory:
```
   cd schemagen
```

## Usage

This project uses Docker to shrink-wrap the devops it needs to run around the project.

To make using it easier, we're using a Makefile and the built-in `make` utility to create local aliases. You can run the following from terminal in the project directory:

- `make clean` - removes the `output/` & `vendor/` directories, as well as the `composer.lock` file
- `make schemagen` - installs the Composer packages as needed, then generates all of the schema.org PHP JSON-LD models to the `output/` directory

Additional arguments can be passed into the `make schemagen` command:

```
make schemagen SOURCE OUTPUT_DIR
```

- `SOURCE` - the URL to the schema.org types to use, defaults to `https://schema.org/version/latest/schemaorg-current-https.jsonld`
- `OUTPUT_DIR` - the directory to output the models to, defaults to `output/`

Additional options can be passed into the `make schemagen` command:

- `c X` - The Craft version to generate the models for. Defaults to 3.
- `-s` command line option that can be used, which controls whether superseded entities should be skipped, defaults to `false`

Additional options can also be set in the `config.php` file:

```php
<?php
const SCHEMA_SOURCE = 'https://schema.org/version/latest/schemaorg-current-https.jsonld';
const MODEL_NAMESPACE = 'nystudio107\\seomatic\\models\\jsonld';
const TEMPLATES_DIR = 'templates/';
const INTERFACE_TEMPLATE = 'interface.php.template';
const TRAIT_TEMPLATE = 'trait.php.template';
const MODEL_TEMPLATE = 'model.php.template';
const FIELD_TEMPLATE = 'field.template';
const OUTPUT_FOLDER = 'dist/jsonld/';
```

Brought to you by [nystudio107](http://nystudio107.com)
