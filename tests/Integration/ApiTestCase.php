<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

abstract class ApiTestCase extends WebTestCase
{
    protected KernelBrowser $client;
    private static bool $schemaCreated = false;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->ensureSchema();
        $this->truncateTables();
    }

    private function ensureSchema(): void
    {
        if (self::$schemaCreated) {
            return;
        }

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $tool = new SchemaTool($em);
        $classes = $em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($classes);
        $tool->createSchema($classes);
        self::$schemaCreated = true;
    }

    private function truncateTables(): void
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $connection = $em->getConnection();

        $connection->executeStatement('TRUNCATE TABLE emails RESTART IDENTITY CASCADE');
        try {
            $connection->executeStatement('TRUNCATE TABLE messenger_messages RESTART IDENTITY CASCADE');
        } catch (\Throwable) {
        }
    }

    /**
     * @param array<string, mixed>  $data
     * @param array<string, string> $headers
     */
    protected function postJson(string $uri, array $data, array $headers = []): void
    {
        $serverHeaders = ['CONTENT_TYPE' => 'application/json'];
        foreach ($headers as $name => $value) {
            $serverHeaders['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        $this->client->request(
            'POST',
            $uri,
            [],
            [],
            $serverHeaders,
            json_encode($data, \JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @param array<string, string> $headers
     */
    protected function getJson(string $uri, array $headers = []): void
    {
        $serverHeaders = ['CONTENT_TYPE' => 'application/json'];
        foreach ($headers as $name => $value) {
            $serverHeaders['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        $this->client->request('GET', $uri, [], [], $serverHeaders);
    }

    /** @return array<string, mixed> */
    protected function responseData(): array
    {
        $content = $this->client->getResponse()->getContent();
        if (false === $content) {
            return [];
        }

        /* @var array<string, mixed> */
        return json_decode($content, true, 512, \JSON_THROW_ON_ERROR);
    }
}
