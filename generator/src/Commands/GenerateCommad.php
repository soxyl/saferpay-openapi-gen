<?php

namespace Soxyl\Saferpay\OpenAPIGen\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Yaml\Yaml;

class GenerateCommad extends Command
{
    protected function configure()
    {
        $this
          ->setName('generate')
          ->setDescription('This command generates an OpenAPI v3 spec based on the Saferpay JSON API docs')
          ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $io = new SymfonyStyle($input, $output);

        $spec = [
            'openapi' => '3.0.0',
            'info' => [
                'title' => 'Saferpay JSON API',
                // 'description' => null,
                'version' => '1.10.0',
            ],
            'servers' => [
                [
                    'url' => 'https://www.saferpay.com/api/Payment/v1',
                    'description' => 'Production System',
                ],
                [
                    'url' => 'https://test.saferpay.com/api/Payment/v1',
                    'description' => 'Test System',
                ],
            ],
            'paths' => [],
            'security' => [
                [
                    'BasicAuth' => [],
                ],
            ],
            'components' => [
                'schemas' => [], // Reusable schemas (data models)
                // 'parameters' => [], // Reusable path, query, header and cookie parameters
                'securitySchemes' => [// Security scheme definitions (see Authentication)
                    'BasicAuth' => [
                        'type' => 'http',
                        'scheme' => 'basic',
                    ],
                ],
                'requestBodies' => [], // Reusable request bodies
                'responses' => [], // Reusable responses, such as 401 Unauthorized or 400 Bad Request
                // 'headers' => [], // Reusable response headers
                // 'examples' => [], // Reusable examples
                // 'links' => [], // Reusable links
                // 'callbacks' => [], // Reusable callbacks
            ],
        ];

        $data = $this->gatherData($io);

        foreach ($data['types'] as $name => $params) {
            $shortName = $this->shortName($name);

            $spec['components']['schemas'][$shortName] = $this->paramInfosToSpec($shortName, $params);
        }
        $spec['components']['schemas']['ErrorResponse'] = $this->paramInfosToSpec('ErrorResponse', $data['errorhandling']['response']);

        foreach ($data['requests'] as $name => $info) {
            $shortName = $this->shortName($name);

            // Request Body
            $spec['components']['schemas'][$shortName.'Request'] = $this->paramInfosToSpec($shortName.'Request', $info['request']);
            $requestBody = [
                'required' => true,
                'content' => [
                    'application/json' => [
                        'schema' => [
                            '$ref' => '#/components/schemas/'.$shortName.'Request',
                        ],
                    ],
                ],
            ];
            $spec['components']['requestBodies'][$shortName] = $requestBody;

            // Response
            $spec['components']['schemas'][$shortName.'Response'] = $this->paramInfosToSpec($shortName.'Response', $info['response']);
            $response = [
                'description' => '',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            '$ref' => '#/components/schemas/'.$shortName.'Response',
                        ],
                    ],
                ],
            ];

            $spec['components']['responses'][$shortName] = $response;

            // Actual Path
            $path = [
                // 'summary' => $info['description'],
                'description' => $info['description'],
                'tags' => [explode('/', $info['uri'])[1]],
                'requestBody' => [
                    '$ref' => '#/components/requestBodies/'.$shortName,
                ],
                'responses' => [
                    '200' => [
                        '$ref' => '#/components/responses/'.$shortName,
                    ],
                ],
            ];

            foreach ($data['errorhandling']['errorcodes'] as $code => $message) {
                if (200 === $code) {
                    continue;
                }

                $path['responses'][$code] = [
                    'description' => $message,
                    'content' => [
                        'application/json' => [
                            'schema' => [
                                '$ref' => '#/components/schemas/ErrorResponse',
                            ],
                        ],
                        'text/plain' => [
                            'schema' => [
                                'type' => 'string',
                            ],
                        ],
                    ],
                ];
            }

            $spec['paths'][$info['uri']][strtolower($info['method'])] = $path;
        }

        $yaml = Yaml::dump($spec, 100, 2, Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE);
        echo $yaml;
    }

    private function shortName(string $name): string
    {
        if ('Common_' === substr($name, 0, 7)) {
            $name = substr($name, 7);
        }

        if ('Payment_Models_Data_' === substr($name, 0, 20)) {
            $name = substr($name, 20);
        }

        if ('Payment_Models_' === substr($name, 0, 15)) {
            $name = substr($name, 15);
        }

        if ('Payment_v1_' === substr($name, 0, 11)) {
            $name = substr($name, 11);
        }

        $name = str_replace('_', '', $name);

        return $name;
    }

    private function paramInfosToSpec(string $title, array $params): array
    {
        $result = [
            'type' => 'object',
            'title' => $title,
            'properties' => [],
        ];

        $required = [];
        foreach ($params as $param) {
            $paramData = [];

            if ('#' === substr($param['type'], 0, 1)) {
                $paramData['$ref'] = '#/components/schemas/'.$this->shortName(substr($param['type'], 1));
            } elseif ('[]' === substr($param['type'], -2)) {
                $paramData['type'] = 'array';
                $paramData['items'] = [
                    'type' => substr($param['type'], 0, -2),
                ];
            } elseif ('date' === $param['type']) {
                $paramData['type'] = 'string';
                $paramData['format'] = 'date';
            } elseif ('decimal number' === $param['type']) {
                $paramData['type'] = 'number';
                $paramData['format'] = 'double';
            } else {
                $paramData['type'] = $param['type'];
            }

            $result['properties'][$param['name']] = $paramData;

            if ($param['mandatory']) {
                $required[] = $param['name'];
            }
        }

        if ($required) {
            $result['required'] = $required;
        }

        return $result;
    }

    private function gatherData(SymfonyStyle $io): array
    {
        $result = [
            'types' => [],
            'requests' => [],
            'errorhandling' => [],
        ];

        $html = file_get_contents('https://raw.githubusercontent.com/saferpay/jsonapi/gh-pages/index.html');
        $crawler = new Crawler($html);

        // Types
        foreach ($crawler->filter('div#type-dict table') as $typeDom) {
            $typeCrawler = new Crawler($typeDom);

            $result['types'][$typeCrawler->attr('id')] = $this->getParamInfos($typeCrawler->filter('tr'));

            /*
            $io->title($typeCrawler->attr('id'));

            $io->table(
                ['Name', 'Mandatory', 'Type', 'Example', 'Possible Values', 'Description'],
                $this->getParamInfos($typeCrawler->filter('tr'))
            );

            $io->newLine();
            */
        }

        // Requests
        foreach ($crawler->filter('strong:contains(\'Request URL:\')') as $dom) {
            $requestCrawler = new Crawler($dom->parentNode->parentNode->parentNode->parentNode->parentNode);

            $uriInfo = explode(': ', $requestCrawler->filter('div.info p:nth-of-type(2)')->text());

            /*
            $io->title($requestCrawler->filter('a')->attr('name'));

            $io->listing([
                $uriInfo[0],
                $uriInfo[1],
                substr($requestCrawler->filter('div div p')->text(), 0, 50),
            ]);

            // Request
            $io->section('Request');
            $io->table(
                ['Name', 'Mandatory', 'Type', 'Example', 'Possible Values', 'Description'],
                $this->getParamInfos($requestCrawler->filter('div.row:nth-of-type(2) > div.col-md-6 > table tbody tr'))
            );

            // Response
            $io->section('Response');
            $io->table(
                ['Name', 'Mandatory', 'Type', 'Example', 'Possible Values', 'Description'],
                $this->getParamInfos($requestCrawler->filter('div.row:nth-of-type(3) > div.col-md-6 > table tbody tr'))
            );
            */

            $result['requests'][$requestCrawler->filter('a')->attr('name')] = [
                'method' => $uriInfo[0],
                'uri' => str_replace('/Payment/v1', '', $uriInfo[1]),
                'description' => $requestCrawler->filter('div div p')->text(),
                'request' => $this->getParamInfos($requestCrawler->filter('div.row:nth-of-type(2) > div.col-md-6 > table tbody tr')),
                'response' => $this->getParamInfos($requestCrawler->filter('div.row:nth-of-type(3) > div.col-md-6 > table tbody tr')),
            ];
        }

        // Error Handling
        $errorhandlingCrawler = $crawler->filter('section#errorhandling');

        /*
        $io->title('Error Handling');
        $io->section('Error Response');
        $io->table(
            ['Name', 'Mandatory', 'Type', 'Example', 'Possible Values', 'Description'],
            $this->getParamInfos($errorhandlingCrawler->filter('div.row:nth-of-type(2) > div.col-md-6 > table > tbody > tr'))
        );

        $io->section('Error Codes');
        */

        $errorCodes = [];
        foreach ($errorhandlingCrawler->filter('div.row:nth-of-type(1) table > tbody > tr') as $errorCodeDom) {
            $errorCodeCrawler = new Crawler($errorCodeDom);

            $errorCodes[$errorCodeCrawler->filter('td:nth-of-type(1)')->text()] = $errorCodeCrawler->filter('td:nth-of-type(2)')->text();
        }

        /*
        $io->table(
            ['Code', 'Description'],
            $errorCodes
        );
        */

        $result['errorhandling']['response'] = $this->getParamInfos($errorhandlingCrawler->filter('div.row:nth-of-type(2) > div.col-md-6 > table > tbody > tr'));
        $result['errorhandling']['errorcodes'] = $errorCodes;

        return $result;
    }

    private function getParamInfos(Crawler $trCrawler): array
    {
        $result = [];
        foreach ($trCrawler as $paramDom) {
            $crawler = new Crawler($paramDom);

            // Type Infos
            $typeInfos = explode(',', $crawler->filter('td:first-child span')->text());
            $typeInfos = array_map(function ($input) {
                return trim($input);
            }, $typeInfos);

            $mandatory = 'mandatory' === $typeInfos[0];
            $recommended = 'recommended' === $typeInfos[0];
            $type = !($mandatory || $recommended) ? $typeInfos[0] : $typeInfos[1];

            if ('container' === $type) {
                $type = $crawler->filter('td:first-child a')->attr('href');
            }

            // Contant Infos
            $contentInfos = explode("\n", $crawler->filter('td:nth-of-type(2) i')->text());
            $contentInfos = array_map(function ($input) {
                return trim($input);
            }, $contentInfos);

            $example = null;
            $posibleValues = null;
            foreach ($contentInfos as $candidate) {
                if ('Possible values: ' === substr($candidate, 0, 17)) {
                    $posibleValues = explode(', ', substr($candidate, 17));
                    continue;
                }

                if ('Example: ' === substr($candidate, 0, 9)) {
                    $example = substr($candidate, 9);
                    continue;
                }
            }

            $data[] = [
                'name' => $crawler->filter('td:first-child strong:first-child')->text(),
                'mandatory' => $mandatory,
                'type' => $type,
                'example' => $example,
                'possibleValues' => $posibleValues,
                'description' => $crawler->filter('td:nth-of-type(2) div')->text(),
            ];
        }

        return $data;
    }
}
