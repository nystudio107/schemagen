#!/usr/bin/env php
<?php

require __DIR__ . '/vendor/autoload.php';
require 'config.php';
require 'helperFunctions.php';

use Nette\PhpGenerator\Printer;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\SingleCommandApplication;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Encoder\XmlEncoder;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;

$application = new Application();

(new SingleCommandApplication())
    ->setName('Download Schema.org data and generate models')
    ->setVersion('1.0.0')
    ->addArgument('source', InputArgument::OPTIONAL, 'The data source URL or file location')
    ->addArgument('outputDir', InputArgument::OPTIONAL, 'The output directory')
    ->addOption('skipSuperseded', 's', InputOption::VALUE_OPTIONAL, 'Whether superseded entities should be skipped', false)
    ->addOption('craft-version', 'c', InputOption::VALUE_OPTIONAL, 'Craft version to generate the models for. Defaults to 3.', 3)
    ->setCode(function(InputInterface $input, OutputInterface $output) {
        $source = $input->getArgument('source') ?? SCHEMA_SOURCE;
        $outputDir = $input->getArgument('outputDir') ?? OUTPUT_DIR;
        $craftVersion = ($input->getOption('craft-version') ?? '3.x');

        // ensure output folders exist
        try {
            nukeDir($outputDir);
            ensureDir($outputDir);
        } catch (RuntimeException $exception) {
            $output->writeln("<error>{$exception->getMessage()}</error>");
            return Command::FAILURE;
        }

        // Fetch the latest schema release name
        $schemaReleases = SCHEMA_RELEASES;
        $output->writeln("<info>Fetching schema releases data</info> - <comment>$schemaReleases</comment>");
        $schemaRelease = getSchemaVersion($schemaReleases);

        // Fetch the tree.jsonld ref: https://schema.org/docs/developers.html
        $schemaTree = SCHEMA_TREE;
        $treeDest = dirname($outputDir) . '/' . TREE_FILE_NAME;
        $output->writeln("<info>Fetching schema tree</info> - <comment>$schemaTree</comment>");
        $tree = file_get_contents($schemaTree);
        if ($tree) {
            file_put_contents($treeDest, $tree);
        }

        $output->writeln("<info>Fetching data source</info> - <comment>$source</comment>");
        $data = file_get_contents($source);

        $encoders = [new XmlEncoder(), new JsonEncoder()];
        $normalizers = [new ObjectNormalizer()];

        $serializer = new Serializer($normalizers, $encoders);
        $json = $serializer->decode($data, 'json');

        if (!is_array($json) || !array_key_exists('@graph', $json)) {
            $output->writeln('<error>Unrecognized data structure</error>');
            return Command::FAILURE;
        }

        $properties = [];
        $classes = [];
        $enums = [];

        $output->writeln('<info>Parsing the received data ...</info>');

        // Parse the JSON into entity groups
        foreach ($json['@graph'] as $entity) {
            if (!empty($entity['schema:supersededBy'])) {
                $output->write("<comment>A deprecated entity encountered</comment> - {$entity['@id']}");

                // Skip entities if required
                if ($input->getOption('skipSuperseded')) {
                    $output->writeln(" ... <info>skipping</info>");
                    continue;
                }
                $output->writeln('');
            }

            $id = $entity['@id'];
            $type = $entity['@type'];

            $types = (array)$type;

            // Some entities are enums AND classes
            foreach ($types as $type) {
                switch ($type) {
                    case 'rdf:Property':
                        if (empty($entity['schema:domainIncludes'])) {
                            break;
                        }

                        // Normalize to an array
                        if (is_array($entity['schema:domainIncludes']) && !is_array(reset($entity['schema:domainIncludes']))) {
                            $entity['schema:domainIncludes'] = [$entity['schema:domainIncludes']];
                        }

                        foreach ($entity['schema:domainIncludes'] ?? [] as $domainIncludes) {
                            foreach ($domainIncludes as $key => $containingClass) {
                                $properties[$containingClass][$id] = $entity;
                            }
                        }
                        break;
                    case 'rdfs:Class':
                        $classes[$id] = $entity;
                        break;
                    default:
                        if (substr($type, 0, 7) === 'schema:') {
                            // Enums should be treated as classes, too
                            $classes[$id] = $entity;
                        } else {
                            $output->writeln("<error>Cannot handle type $type");
                            return Command::FAILURE;
                        }
                }
            }
        }

        $output->writeln('<info>Generating traits and building the hierarchy tree ...</info>');
        // First pass to generate traits and create hierarchy tree
        $entityTree = [];
        $propertiesBySchemaName = [];

        foreach ($classes as $id => $classDef) {
            if (str_starts_with($id, 'schema:')) {
                $schemaName = getTextValue($classDef['rdfs:label']);
                $schemaClass = getSchemaClassName($schemaName);
                $schemaTraitName = $schemaClass . 'Trait';
                $schemaInterfaceName = $schemaClass . 'Interface';
                $propertiesBySchemaName[$schemaName] = $properties[$id] ?? [];

                $trait = printTraitFile($schemaClass, $properties[$id] ?? [], $schemaRelease, $craftVersion);
                saveGeneratedFile($outputDir . $schemaTraitName . '.php', $trait);
                $interface = printInterfaceFile($schemaClass, $schemaRelease, $craftVersion);
                saveGeneratedFile($outputDir . $schemaInterfaceName . '.php', $interface);

                $entityTree[$schemaName] = [];

                if (!empty($classDef['rdfs:subClassOf'])) {
                    // Ensure normalized form
                    if (is_array($classDef['rdfs:subClassOf']) && !is_array(reset($classDef['rdfs:subClassOf']))) {
                        $classDef['rdfs:subClassOf'] = [$classDef['rdfs:subClassOf']];
                    }

                    // Build a list of parent entities
                    foreach ($classDef['rdfs:subClassOf'] as $subclassDef) {
                        // Interested only in schema types
                        if (substr($subclassDef['@id'], 0, 7) === 'schema:') {
                            $subClassOf = substr($subclassDef['@id'], 7);
                            $entityTree[$schemaName][] = $subClassOf;
                        }
                    }
                }

                if (!empty($classDef['@type']) && is_string($classDef['@type']) && str_starts_with($classDef['@type'], 'schema:')) {
                    $subClassOf = substr($classDef['@type'], 7);
                    $entityTree[$schemaName][] = $subClassOf;
                }
            }
        }

        $output->writeln('<info>Generating models and including traits ...</info>');

        // Loop again, now with all the traits generated and relations known.
        foreach ($classes as $id => $classDef) {
            if (str_starts_with($id, 'schema:')) {
                $schemaTraits = [];
                $schemaInterfaces = [];

                $schemaName = getTextValue($classDef['rdfs:label']);
                $schemaClass = getSchemaClassName($schemaName);
                $schemaDescriptionRaw = rtrim(getTextValue($classDef['rdfs:comment'], false), "\n");
                $schemaDescription = wordwrap(getTextValue($classDef['rdfs:comment']));
                $schemaScope = getScope($schemaName);

                // Add the schemaName itself as an ancestor so its properties, Trait, and Interface are included
                $ancestors = [];
                $ancestors[] = $schemaName;
                loadAllAncestors($ancestors, $entityTree, $schemaName);
                $ancestors = array_unique($ancestors);

                $schemaExtends = $ancestors[1] ?? 'Thing';

                // Include all ancestor traits
                foreach ($ancestors as $ancestor) {
                    $schemaTraits[] = getSchemaClassName($ancestor) . 'Trait';
                    $schemaInterfaces[] = getSchemaClassName($ancestor) . 'Interface';
                }

                // Load google field information
                $googleFields = getGoogleFields(array_merge([$schemaName], $ancestors));
                $required = wrapValuesInSingleQuotes(cleanArray($googleFields['required']));
                $recommended = wrapValuesInSingleQuotes(cleanArray($googleFields['recommended']));

                $googleRequiredSchemaAsArray = '[' . implode(", ", $required) . ']';
                $googleRecommendedSchemaAsArray = '[' . implode(", ", $recommended) . ']';

                $schemaPropertyTypes = [];
                $schemaPropertyDescriptions = [];

                $schemaPropertyExpectedTypesAsArray = "[\n";
                $schemaPropertyDescriptionsAsArray = "[\n";

                // Load property information
                $fieldsToParse = [];
                foreach ($ancestors as $ancestor) {
                    $fields = $propertiesBySchemaName[$ancestor];
                    foreach ($fields as $fieldDef) {
                        $handle = getTextValue($fieldDef['rdfs:label']);
                        $fieldsToParse[$handle] = $fieldDef;
                    }
                }

                ksort($fieldsToParse);

                foreach ($fieldsToParse as $fieldDef) {
                    $fieldData = compileFieldData($fieldDef);
                    $types = wrapValuesInSingleQuotes($fieldData['propertyTypesAsArray']);
                    $description = str_replace("'", '\\\'', $fieldData['propertyDescription']);

                    $schemaPropertyTypes[] = "    '" . $fieldData['propertyHandle'] . "' => [" . implode(', ', $types) . "]";
                    $schemaPropertyDescriptions[] = "    '" . $fieldData['propertyHandle'] . "' => '" . $description . "'";
                }

                $schemaPropertyExpectedTypesAsArray .= implode(",\n", $schemaPropertyTypes) . "\n]";
                $schemaPropertyDescriptionsAsArray .= implode(",\n", $schemaPropertyDescriptions) . "\n]";

                $file = createFileWithHeader($craftVersion);
                $file->addNamespace(MODEL_NAMESPACE)
                    ->addUse(PARENT_MODEL);

                $class = $file->addClass(MODEL_NAMESPACE . '\\' . $schemaClass);
                $class->addComment("schema.org version: $schemaRelease")
                    ->addComment("$schemaName - $schemaDescription\n");
                decorateWithPackageInfo($class, $schemaScope);

                $class->setExtends(PARENT_MODEL);

                foreach ($schemaInterfaces as $schemaInterface) {
                    $class->addImplement(MODEL_NAMESPACE . '\\' . $schemaInterface);
                }

                $properties = [];
                $properties[] = $class->addProperty('schemaTypeName', $schemaName)
                    ->setStatic()
                    ->setPublic()
                    ->addComment("The Schema.org Type Name\n")
                    ->addComment('@var string');

                $properties[] = $class->addProperty('schemaTypeScope', $schemaScope)
                    ->setStatic()
                    ->setPublic()
                    ->addComment("The Schema.org Type Scope\n")
                    ->addComment('@var string');

                $properties[] = $class->addProperty('schemaTypeExtends', $schemaExtends)
                    ->setStatic()
                    ->setPublic()
                    ->addComment("The Schema.org Type Extends\n")
                    ->addComment('@var string');

                $properties[] = $class->addProperty('schemaTypeDescription', $schemaDescriptionRaw)
                    ->setStatic()
                    ->setPublic()
                    ->addComment("The Schema.org Type Description\n")
                    ->addComment('@var string');

                if ($craftVersion !== 3) {
                    foreach ($properties as $property) {
                        $property->setType('string');
                    }
                }

                foreach ($schemaTraits as $schemaTrait) {
                    $class->addTrait(MODEL_NAMESPACE . '\\' . $schemaTrait);
                }

                $class->addMethod('getSchemaPropertyNames')
                    ->addComment('@inheritdoc')
                    ->setPublic()
                    ->setReturnType('array')
                    ->setBody('return array_keys($this->getSchemaPropertyExpectedTypes());');

                $class->addMethod('getSchemaPropertyExpectedTypes')
                    ->addComment('@inheritdoc')
                    ->setPublic()
                    ->setReturnType('array')
                    ->setBody("return $schemaPropertyExpectedTypesAsArray;");

                $class->addMethod('getSchemaPropertyDescriptions')
                    ->addComment('@inheritdoc')
                    ->setPublic()
                    ->setReturnType('array')
                    ->setBody("return $schemaPropertyDescriptionsAsArray;");

                $class->addMethod('getGoogleRequiredSchema')
                    ->addComment('@inheritdoc')
                    ->setPublic()
                    ->setReturnType('array')
                    ->setBody("return $googleRequiredSchemaAsArray;");

                $class->addMethod('getGoogleRecommendedSchema')
                    ->addComment('@inheritdoc')
                    ->setPublic()
                    ->setReturnType('array')
                    ->setBody("return $googleRecommendedSchemaAsArray;");

                $class->addMethod('defineRules')
                    ->addComment('@inheritdoc')
                    ->setPublic()
                    ->setReturnType('array')
                    ->setBody(<<<'METHOD'
                        $rules = parent::defineRules();
                        $rules = array_merge($rules, [
                            [$this->getSchemaPropertyNames(), 'validateJsonSchema'],
                            [$this->getGoogleRequiredSchema(), 'required', 'on' => ['google'], 'message' => 'This property is required by Google.'],
                            [$this->getGoogleRecommendedSchema(), 'required', 'on' => ['google'], 'message' => 'This property is recommended by Google.']
                        ]);
                    
                        return $rules;
                    METHOD
                    );

                $model = (new Printer())->printFile($file);

                saveGeneratedFile($outputDir . $schemaClass . '.php', $model);
            }
        }

        $output->writeln('<info>All done!</info>');

        return Command::SUCCESS;
    })
    ->run();

$application->run();
