#!/usr/bin/env php
<?php

require __DIR__ . '/vendor/autoload.php';
require 'config.php';
require 'helperFunctions.php';

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
    ->setCode(function (InputInterface $input, OutputInterface $output) {
        $source = $input->getArgument('source') ?? 'https://schema.org/version/latest/schemaorg-current-https.jsonld';
        $outputDir = $input->getArgument('outputDir') ?? 'output';


        // ensure output folders exist
        try {
            nukeDir($outputDir);
            ensureDir($outputDir);
        } catch (RuntimeException $exception) {
            $output->writeln("<error>{$exception->getMessage()}</error>");
            return Command::FAILURE;
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
                            // Seems unneeded?
                            $enums[$type][] = $entity;
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
                $propertiesBySchemaName[$schemaName] = $properties[$id] ?? [];

                $trait = makeTrait($schemaClass, $properties[$id] ?? []);
                saveGeneratedFile($schemaTraitName . '.php', $trait);

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
            }
        }

        $output->writeln('<info>Generating models and including traits ...</info>');

        // Loop again, now with all the traits generated and relations known.
        foreach ($classes as $id => $classDef) {
            if (str_starts_with($id, 'schema:')) {
                $schemaTraitStatements = [];

                $schemaName = getTextValue($classDef['rdfs:label']);
                $schemaClass = getSchemaClassName($schemaName);
                $schemaDescriptionRaw = rtrim(getTextValue($classDef['rdfs:comment'], false), "\n");
                $schemaDescription = wordwrap(getTextValue($classDef['rdfs:comment']), 75, "\n * ");
                $schemaScope = getScope($schemaName);

                $schemaTraitStatements[] = "    use {$schemaClass}Trait;";
                $ancestors = [];
                loadAllAncestors($ancestors, $entityTree, $schemaName);
                $ancestors = array_unique($ancestors);

                // Include all ancestor traits
                foreach ($ancestors as $ancestor) {
                    $schemaTraitStatements[] = '    use ' . getSchemaClassName($ancestor) . 'Trait;';
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

                    $schemaPropertyTypes[] = "            '" . $fieldData['propertyHandle'] . "' => [" . implode(', ', $types) . "]";
                    $schemaPropertyDescriptions[] = "            '" . $fieldData['propertyHandle'] . "' => '" . $description . "'";
                }

                $schemaPropertyExpectedTypesAsArray .= implode(",\n", $schemaPropertyTypes) . "\n        ]";
                $schemaPropertyDescriptionsAsArray .= implode(",\n", $schemaPropertyDescriptions) . "\n        ]";

                $currentYear = date("Y");
                $namespace = MODEL_NAMESPACE;
                $schemaTraitStatements = implode("\n", $schemaTraitStatements);

                $stringType = $input->getOption('craft-version') == 3 ? '' : 'string ';

                $model = parseTemplate(file_get_contents(getTemplatePath(MODEL_TEMPLATE)), compact(
                        'stringType',
                        'currentYear',
                        'namespace',
                        'schemaName',
                        'schemaDescription',
                        'schemaDescriptionRaw',
                        'schemaScope',
                        'schemaClass',
                        'schemaTraitStatements',
                        'googleRequiredSchemaAsArray',
                        'googleRecommendedSchemaAsArray',
                        'schemaPropertyExpectedTypesAsArray',
                        'schemaPropertyDescriptionsAsArray'
                    )
                );

                saveGeneratedFile($schemaClass . '.php', $model);
            }
        }

        $output->writeln('<info>All done!</info>');

        return Command::SUCCESS;
    })
    ->run();

$application->run();
