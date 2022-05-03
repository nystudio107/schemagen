<?php

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
    if (!@mkdir($dir) && !is_dir($dir)) {
        throw new \RuntimeException(sprintf('Directory "%s" was not created', $dir));
    }
}

/**
 * Given a schema name, generate a class name for the schema.
 *
 * @param string $schemaName
 * @return string
 */
function getSchemaClassName(string $schemaName): string
{
    if (!preg_match('/^[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*$/', $schemaName) || strtolower($schemaName) === 'class') {
        return 'Schema' . $schemaName;
    }

    return $schemaName;
}

/**
 * Generate PHP code for a class field based on a property definition. If
 *
 * @param array $propertyDef
 * @param
 * @return string
 */
function makeField(array $propertyDef): string
{
    $fieldData = compileFieldData($propertyDef);
    $fieldData['propertyDescription'] = wordwrap($fieldData['propertyDescription'], 75, "\n     * ");
    return parseTemplate(file_get_contents(getTemplatePath(FIELD_TEMPLATE)), $fieldData);
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

    foreach ($propertyTypes as &$type) {
        $type = substr($type['@id'], 7);
        $propertyTypesAsArray[] = $type;
    }

    $propertyType = implode('|', $propertyTypes);

    return compact(
        'propertyDescription',
        'propertyType',
        'propertyTypesAsArray',
        'propertyHandle'
    );
}

/**
 * Return the path to the template based on the current CRAFT_VERSION
 *
 * @param string $template
 * @return string
 */
function getTemplatePath(string $template): string
{
    return TEMPLATES_DIR . 'v' . CRAFT_VERSION . '/' . $template;
}

/**
 * Make the trait.
 *
 * @param string $schemaName
 * @param array $properties
 * @return string
 */
function makeTrait(string $schemaName, array $properties): string
{
    $fields = [];

    foreach ($properties as $propertyDef) {
        $fields[] = makeField($propertyDef);
    }

    $schemaPropertiesAsFields = implode("", $fields);
    $craftVersion = CRAFT_VERSION;
    $currentYear = date("Y");
    $namespace = MODEL_NAMESPACE;
    $schemaScope = getScope($schemaName);
    $schemaTraitName = $schemaName . 'Trait';


    return parseTemplate(file_get_contents(getTemplatePath(TRAIT_TEMPLATE)), compact(
            'schemaPropertiesAsFields',
            'schemaName',
            'craftVersion',
            'currentYear',
            'namespace',
            'schemaScope',
            'schemaTraitName')
    );
}

/**
 * Parse a template using an array of variables.
 *
 * @param string $template
 * @param array $variables
 *
 * @return string
 */
function parseTemplate(string $template, array $variables): string
{
    $output = $template;
    foreach ($variables as $key => $value) {
        if (!is_array($value)) {
            $output = str_replace('{@' . $key . '}', $value, $output);
        }
    }

    return $output;
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
 * @param string $fileName
 * @param string $content
 */
function saveGeneratedFile(string $fileName, string $content): void
{
    $path = OUTPUT_FOLDER . $fileName;
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
