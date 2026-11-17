<?php

declare(strict_types=1);

namespace Mohammad\ApiVersionPlatform;

/**
 * Puts a local version on the cloud platform with as few decisions as possible (`avp publish` and the Cloud tab).
 *
 * - The cloud project is the local project's name (found by name, created when missing).
 * - `publish()` without a label: a changed API document becomes a new local version with the next label (skipping any
 *   name the cloud project already has); an unchanged one publishes the newest local version.
 * - `publish()` with a label: that local version, or a new one made from the API document when no such label exists.
 * - `publishLocal()`: exactly the local version the caller picked (the Cloud tab).
 */
final class Publisher
{
    private VersionService $versions;
    private Storage $storage;
    private Cloud $cloud;
    private string $projectRoot;

    public function __construct(VersionService $versions, Storage $storage, Cloud $cloud, string $projectRoot)
    {
        $this->versions = $versions;
        $this->storage = $storage;
        $this->cloud = $cloud;
        $this->projectRoot = $projectRoot;
    }

    /** The name the project has locally (editable in the UI), else its folder name. */
    public function projectName(): string
    {
        return $this->storage->meta('project.name') ?? basename($this->projectRoot);
    }

    /**
     * @return array{
     *   project: array{id: int, name: string, created: bool},
     *   version: string,
     *   localCreated: bool,
     *   alreadyPublished: bool
     * }
     */
    public function publish(?string $label, ?string $note, ?string $projectName = null): array
    {
        $name = $this->targetName($projectName);
        $label = $label !== null && trim($label) !== '' ? VersionService::label($label) : null;

        $project = $this->cloud->findProject($name);
        $remoteLabels = $project === null ? [] : array_map(static fn (array $v): string => strtolower($v['version']), $this->cloud->versions($project['id']));

        $localCreated = false;
        if ($label !== null) {
            $local = $this->versions->get($label);
            if ($local === null) {
                $this->versions->create($label, (string) $note, true);
                $local = $this->versions->get($label);
                $localCreated = true;
            }
        } else {
            $status = $this->versions->status();
            if (in_array($status['state'], ['modified', 'uninitialized'], true)) {
                $local = $this->versions->get($this->versions->create($this->freeLabel($remoteLabels), (string) $note)['label']);
                $localCreated = true;
            } else {
                $newest = $this->versions->newestId();
                if ($newest === null) {
                    throw new \RuntimeException($status['message'] ?? 'There is nothing to publish: no local version and no usable API document.');
                }
                $local = $this->versions->byId($newest);
            }
        }
        if ($local === null) throw new \RuntimeException('Could not read the local version to publish.');
        $version = (string) $local['label'];

        if (in_array(strtolower($version), $remoteLabels, true)) {
            if ($label === null && !$localCreated) {
                return ['project' => ['id' => (int) $project['id'], 'name' => $project['name'], 'created' => false], 'version' => $version, 'localCreated' => false, 'alreadyPublished' => true];
            }
            throw new CloudException("Version '{$version}' already exists in the cloud project \"{$name}\". Publish under another name: avp publish <label>", 409);
        }

        $result = $this->send($project, $name, $local, $version, $note);
        return $result + ['localCreated' => $localCreated, 'alreadyPublished' => false];
    }

    /**
     * Publishes the local version with this id (what the Cloud tab does).
     *
     * @param string|null $label name for the cloud version; defaults to the local label
     * @return array{project: array{id: int, name: string, created: bool}, version: string}
     */
    public function publishLocal(int $versionId, ?string $label, ?string $note, ?string $projectName = null): array
    {
        $local = $this->versions->byId($versionId);
        if ($local === null) throw new \OutOfBoundsException('Version not found');
        $name = $this->targetName($projectName);
        $version = $label !== null && trim($label) !== '' ? VersionService::label($label) : (string) $local['label'];

        $project = $this->cloud->findProject($name);
        if ($project !== null) {
            foreach ($this->cloud->versions((int) $project['id']) as $remote) {
                if (strtolower($remote['version']) === strtolower($version)) {
                    throw new CloudException("Version '{$version}' already exists in the cloud project \"{$name}\". Give the cloud version another name.", 409);
                }
            }
        }
        return $this->send($project, $name, $local, $version, $note);
    }

    private function targetName(?string $projectName): string
    {
        return trim((string) $projectName) !== '' ? trim((string) $projectName) : $this->projectName();
    }

    /**
     * Creates the cloud project when it does not exist yet, publishes, and remembers the project.
     *
     * @param array<string, mixed>|null $project
     * @param array<string, mixed> $local
     * @return array{project: array{id: int, name: string, created: bool}, version: string}
     */
    private function send(?array $project, string $name, array $local, string $version, ?string $note): array
    {
        $created = false;
        if ($project === null) {
            $project = ['id' => $this->cloud->createProject($name), 'name' => $name];
            $created = true;
        }
        $openapi = $local['openapi'] ?? null;
        if (!is_object($openapi)) throw new \RuntimeException("Local version '" . ($local['label'] ?? '') . "' has no OpenAPI document.");
        $this->cloud->publish((int) $project['id'], $version, ($note !== null && trim($note) !== '') ? trim($note) : ($local['note'] ?? null), $openapi);

        $this->storage->setMeta('cloud.project_id', (string) $project['id']);
        $this->storage->setMeta('cloud.project_name', (string) $project['name']);
        return ['project' => ['id' => (int) $project['id'], 'name' => (string) $project['name'], 'created' => $created], 'version' => $version];
    }

    /** The next local label that the cloud project does not already use. @param list<string> $remoteLabels lowercase */
    private function freeLabel(array $remoteLabels): string
    {
        $labels = array_map(static fn (array $row): string => (string) $row['label'], array_reverse($this->versions->all()));
        $candidate = $this->versions->nextLabel();
        for ($i = 0; $i < 100 && in_array(strtolower($candidate), $remoteLabels, true); $i++) {
            $labels[] = $candidate;
            $candidate = VersionNumber::suggestNext($labels) ?? $candidate . '-next';
        }
        return $candidate;
    }
}
