<?php

use Nette\PhpGenerator\ClassLike;
use Nette\PhpGenerator\PhpFile;

function nukeDir(string $path): void
{
    if (file_exists($path)) {
        $directoryIterator = new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS);
        $recursiveIteratorIterator = new RecursiveIteratorIterator($directoryIterator, RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($recursiveIteratorIterator as $file) {
            $file->isDir() ? rmdir($file) : unlink($file);
        }
    }
}

/**
 * Ensure a folder exists or throw an exception
 *
 * @param string $dir
 * @throws RuntimeException if unable to ensure that a directory exists
 */
function ensureDir(string $dir): void
{
    if (!@mkdir($dir, 0777, true) && !is_dir($dir)) {
        throw new RuntimeException(sprintf('Directory "%s" was not created', $dir));
    }
}

/**
 * Get the current schema release version from the GitHub API
 *
 * @param string $schemaReleases
 * @return string
 */
function getSchemaVersion(string $schemaReleases): string
{
    $schemaRelease = 'latest';
    // Per: https://stackoverflow.com/questions/37141315/file-get-contents-gets-403-from-api-github-com-every-time
    $opts = [
        'http' => [
            'method' => 'GET',
            'header' => [
                'User-Agent: PHP'
            ]
        ]
    ];
    $context = stream_context_create($opts);
    $data = file_get_contents($schemaReleases, false, $context);
    if ($data) {
        $data = json_decode($data);
        if (is_array($data)) {
            $schemaRelease = $data[0]->tag_name ?? 'latest';
        }
    }

    return $schemaRelease;
}

/**
 * Given a schema name, generate a class name for the schema.
 *
 * @param string $schemaName
 * @return string
 */
function getSchemaClassName(string $schemaName): string
{
    $invalidClassnames = ['class', 'false', 'true', 'float'];
    if (!preg_match('/^[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*$/', $schemaName) || in_array(strtolower($schemaName), $invalidClassnames, true)) {
        return 'Schema' . $schemaName;
    }

    return $schemaName;
}

/**
 * Compile a field's data from property definition array.
 *
 * @param array $propertyDef
 * @return array{propertyDescription: string, propertyType: string, propertyTypesAsArray: array, propertyHandle: string}
 */
function compileFieldData(array $propertyDef): array
{
    $propertyDescription = getTextValue($propertyDef['rdfs:comment']) ?? '';

    $propertyTypes = empty($propertyDef['schema:rangeIncludes']['@id']) ? $propertyDef['schema:rangeIncludes'] : ([$propertyDef['schema:rangeIncludes']] ?? []);
    $propertyHandle = getTextValue($propertyDef['rdfs:label']) ?? '';

    $propertyTypesAsArray = [];
    $propertyPhpTypesAsArray = [];

    foreach ($propertyTypes as &$schemaType) {
        $schemaType = substr($schemaType['@id'], 7);
        switch ($schemaType) {
            case 'Text':
            case 'Url':
                $phpType = 'string';
                break;
            case 'Integer':
                $phpType = 'int';
                break;
            case 'Number':
            case 'Float':
                $phpType = 'float';
                break;
            case 'Boolean':
                $phpType = 'bool';
                break;
            default:
                $phpType = '';
                break;
        }
        $propertyTypesAsArray[] = $schemaType;
        $propertyPhpTypesAsArray[] = $phpType;
    }

    $propertyPhpTypesAsArray = array_merge(
        array_filter($propertyPhpTypesAsArray),
        $propertyTypesAsArray,
    );

    $propertyType = implode('|', $propertyPhpTypesAsArray);

    return compact(
        'propertyDescription',
        'propertyType',
        'propertyTypesAsArray',
        'propertyHandle'
    );
}

function printInterfaceFile(string $schemaName, string $schemaRelease, string $craftVersion): string
{
    $schemaInterfaceName = $schemaName . 'Interface';
    $schemaScope = getScope($schemaName);

    $file = createFileWithHeader($craftVersion);
    $interface = $file->addInterface(MODEL_NAMESPACE . '\\' . $schemaInterfaceName);
    $interface->addComment("schema.org version: $schemaRelease")
        ->addComment("Interface for $schemaName.\n");
    decorateWithPackageInfo($interface, $schemaScope);

    return (new Nette\PhpGenerator\PsrPrinter)->printFile($file);
}

/**
 * Make the trait.
 *
 * @param string $schemaName
 * @param array $properties
 * @return string
 */
function printTraitFile(string $schemaName, array $properties, string $schemaRelease, string $craftVersion): string
{
    foreach ($properties as &$fieldDef) {
        $fieldDef = compileFieldData($fieldDef);
        $fieldDef['propertyDescription'] = wordwrap($fieldDef['propertyDescription']);
    }
    unset($fieldDef);

    $schemaScope = getScope($schemaName);
    $schemaTraitName = $schemaName . 'Trait';

    $file = createFileWithHeader($craftVersion);

    $trait = $file->addTrait(MODEL_NAMESPACE . '\\' . $schemaTraitName);
    $trait->addComment("schema.org version: $schemaRelease")
        ->addComment("Trait for $schemaName.\n");

    decorateWithPackageInfo($trait, $schemaScope);

    foreach ($properties as $fieldDef) {
        $trait->addProperty($fieldDef['propertyHandle'])
            ->setPublic()
            ->addComment($fieldDef['propertyDescription'] . "\n")
            ->addComment("@var {$fieldDef['propertyType']}");
    }

    return (new Nette\PhpGenerator\PsrPrinter)->printFile($file);
}

/**
 * Get the scope URL for a given schema name.
 *
 * @param string $schemaName
 * @return string
 */
function getScope(string $schemaName): string
{
    return 'https://schema.org/' . $schemaName;
}

/**
 * Save a generated file.
 *
 * @param string $path
 * @param string $content
 */
function saveGeneratedFile(string $path, string $content): void
{
    file_put_contents($path, $content);
}

/**
 * Get google fields based on an array of schemas (inherited and current)
 *
 * @param array $schemas
 * @return array{required: array, recommended: array}
 */
function getGoogleFields(array $schemas): array
{
    $required = [];
    $recommended = [];

    foreach ($schemas as $schema) {
        switch ($schema) {
            case 'Thing':
                $required = array_merge($required, ['name', 'description']);
                $recommended = array_merge($recommended, ['url', 'image']);
                break;

            case 'Article':
            case 'NewsArticle':
            case 'BlogPosting':
                $required = array_merge($required, ['author', 'datePublished', 'headline', 'image', 'publisher']);
                $recommended = array_merge($recommended, ['dateModified', 'mainEntityOfPage']);
                break;

            case 'SocialMediaPosting':
                $required = array_merge($required, ['datePublished', 'headline', 'image']);
                break;

            case 'LiveBlogPosting':
                $recommended = array_merge($recommended, ['coverageEndTime', 'coverageStartTime']);
                break;
        }
    }

    return compact('required', 'recommended');
}

function cleanArray(array $array): array
{
    $array = array_unique($array);
    sort($array);

    return $array;
}

/**
 * If the field value is a localized node, just get the first value and be done with it.
 *
 * @param mixed $fieldValue
 * @param bool $removeBreaks
 * @return string
 */
function getTextValue(mixed $fieldValue, bool $removeBreaks = true): string
{
    if (is_array($fieldValue)) {
        $fieldValue = $fieldValue['@value'];
    }
    $fieldValue = html_entity_decode($fieldValue);
    if ($removeBreaks) {
        $fieldValue = str_replace(['<br />', '\n', "\n"], ' ', $fieldValue);
    }

    return $fieldValue;
}

function loadAllAncestors(array &$ancestors, array $entityTree, string $className): void
{
    foreach ($entityTree[$className] as $parentClassName) {
        $ancestors[] = $parentClassName;
        loadAllAncestors($ancestors, $entityTree, $parentClassName);
    }
}

/**
 * Wrap an array's values in single quotes.
 *
 * @param array $array
 * @return array
 */
function wrapValuesInSingleQuotes(array $array): array
{
    array_walk($array, function (&$value) {
        $value = "'$value'";
    });

    return $array;
}

/**
 * @param string $craftVersion
 * @return PhpFile
 */
function createFileWithHeader(string $craftVersion): PhpFile
{
    $currentYear = date("Y");
    $file = new Nette\PhpGenerator\PhpFile;
    $file->addComment("SEOmatic plugin for Craft CMS $craftVersion\n")
        ->addComment("A turnkey SEO implementation for Craft CMS that is comprehensive, powerful, and flexible\n")
        ->addComment('@link      https://nystudio107.com')
        ->addComment("@copyright Copyright (c) $currentYear nystudio107");
    return $file;
}

/**
 * @param ClassLike $classLike
 * @param string $schemaScope
 */
function decorateWithPackageInfo(ClassLike $classLike, string $schemaScope)
{
    $classLike
        ->addComment('@author    nystudio107')
        ->addComment('@package   Seomatic')
        ->addComment("@see       $schemaScope");
}
