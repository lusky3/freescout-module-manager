<?php

namespace Modules\ModuleManager\Services;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Modules\ModuleManager\Services\Exceptions\GithubDownloadException;

class GithubRepoFetcher
{
    private ClientInterface $client;

    public function __construct(ClientInterface $client)
    {
        $this->client = $client;
    }

    public function buildZipUrl(string $owner, string $repo, string $ref): string
    {
        $owner = rawurlencode(trim($owner, '/'));
        $repo = rawurlencode(trim($repo, '/'));
        $ref = rawurlencode(trim($ref, '/'));

        return "https://github.com/{$owner}/{$repo}/archive/{$ref}.zip";
    }

    public function download(string $owner, string $repo, string $ref, string $destinationPath): void
    {
        $url = $this->buildZipUrl($owner, $repo, $ref);

        try {
            $response = $this->client->request('GET', $url, [
                'sink' => $destinationPath,
                'timeout' => 30,
                'http_errors' => false,
            ]);
        } catch (GuzzleException $e) {
            throw new GithubDownloadException(
                "Could not download {$owner}/{$repo}@{$ref}: {$e->getMessage()}"
            );
        }

        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            throw new GithubDownloadException(
                "GitHub returned HTTP {$status} for {$owner}/{$repo}@{$ref}. Check the owner, repo, and branch/tag name."
            );
        }
    }
}
