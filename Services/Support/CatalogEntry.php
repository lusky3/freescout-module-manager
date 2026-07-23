<?php

namespace Modules\ModuleManager\Services\Support;

class CatalogEntry
{
    public string $owner;
    public string $repo;
    public string $ref;
    public string $name;
    public string $description;
    public ?string $authorName;
    public int $stars;
    public ?string $lastPushedAt;
    public ?string $license;
    public ?string $screenshotUrl;
    public ?string $reviewedAt;
    public ?string $reviewNotes;

    public function __construct(
        string $owner,
        string $repo,
        string $ref,
        string $name,
        string $description,
        ?string $authorName,
        int $stars,
        ?string $lastPushedAt,
        ?string $license,
        ?string $screenshotUrl,
        ?string $reviewedAt,
        ?string $reviewNotes
    ) {
        foreach (compact('owner', 'repo', 'ref', 'name', 'description') as $field => $value) {
            if ($value === '') {
                throw new \InvalidArgumentException("CatalogEntry::\${$field} must not be empty.");
            }
        }

        $this->owner = $owner;
        $this->repo = $repo;
        $this->ref = $ref;
        $this->name = $name;
        $this->description = $description;
        $this->authorName = $authorName;
        $this->stars = $stars;
        $this->lastPushedAt = $lastPushedAt;
        $this->license = $license;
        $this->screenshotUrl = $screenshotUrl;
        $this->reviewedAt = $reviewedAt;
        $this->reviewNotes = $reviewNotes;
    }

    public function url(): string
    {
        return "https://github.com/{$this->owner}/{$this->repo}";
    }

    public static function fromArray(array $data): ?self
    {
        foreach (['owner', 'repo', 'ref', 'name', 'description'] as $field) {
            if (empty($data[$field]) || !is_string($data[$field])) {
                return null;
            }
        }

        try {
            return new self(
                $data['owner'],
                $data['repo'],
                $data['ref'],
                $data['name'],
                $data['description'],
                (isset($data['author_name']) && is_string($data['author_name'])) ? $data['author_name'] : null,
                (isset($data['stars']) && is_int($data['stars'])) ? $data['stars'] : 0,
                (isset($data['last_pushed_at']) && is_string($data['last_pushed_at'])) ? $data['last_pushed_at'] : null,
                (isset($data['license']) && is_string($data['license'])) ? $data['license'] : null,
                (isset($data['screenshot_url']) && is_string($data['screenshot_url'])) ? $data['screenshot_url'] : null,
                (isset($data['reviewed_at']) && is_string($data['reviewed_at'])) ? $data['reviewed_at'] : null,
                (isset($data['review_notes']) && is_string($data['review_notes'])) ? $data['review_notes'] : null
            );
        } catch (\InvalidArgumentException $e) {
            return null;
        }
    }

    public function toArray(): array
    {
        return [
            'owner' => $this->owner,
            'repo' => $this->repo,
            'ref' => $this->ref,
            'name' => $this->name,
            'description' => $this->description,
            'author_name' => $this->authorName,
            'stars' => $this->stars,
            'last_pushed_at' => $this->lastPushedAt,
            'license' => $this->license,
            'screenshot_url' => $this->screenshotUrl,
            'reviewed_at' => $this->reviewedAt,
            'review_notes' => $this->reviewNotes,
        ];
    }

    public function matchesSavedRepo(SavedRepo $savedRepo): bool
    {
        return strcasecmp($this->owner, $savedRepo->owner) === 0
            && strcasecmp($this->repo, $savedRepo->repo) === 0;
    }
}
