<?php

declare(strict_types=1);

namespace Mohammad\ApiVersionPlatform;

/**
 * `avp publish`: puts a local version on the cloud platform with as few decisions as possible.
 *
 * - The cloud project is the local project's name (found by name, created when missing).
 * - Without a label: a changed API document becomes a new local version with the next label (skipping any
 *   name the cloud project already has); an unchanged one publishes the newest local version.
 * - With a label: that local version, or a new one made from the API document when no such label exists.
 */
final class Publisher
{
    public function __construct(
        private VersionService $versions,
        private Storage $storage,
        private Cloud $cloud,
        private string $projectRoot,
    ) {
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
     *   alreadyPublished: bool,
     *   url: string
     * }
     */
    public function publish(?string $label, ?string $note, ?string $projectName = null): array
    {
        $name = trim((string) $projectName) !== '' ? trim((string) $projectName) : $this->projectName();
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
                return ['project' => ['id' => (int) $project['id'], 'name' => $project['name'], 'created' => false], 'version' => $version, 'localCreated' => false, 'alreadyPublished' => true, 'url' => $this->projectUrl((int) $project['id'])];
            }
            throw new CloudException("Version '{$version}' already exists in the cloud project \"{$name}\". Publish under another name: avp publish <label>", 409);
        }

        $created = false;
        if ($project === null) {
            $project = ['id' => $this->cloud->createProject($name), 'name' => $name];
            $created = true;
        }
        $openapi = $local['openapi'];
        if (!is_object($openapi)) throw new \RuntimeException("Local version '{$version}' has no OpenAPI document.");
        $this->cloud->publish((int) $project['id'], $version, ($note !== null && trim($note) !== '') ? trim($note) : ($local['note'] ?? null), $openapi);

        $this->storage->setMeta('cloud.project_id', (string) $project['id']);
        $this->storage->setMeta('cloud.project_name', (string) $project['name']);
        $this->storage->setMeta('cloud.project_url', $this->projectUrl((int) $project['id']));

        return ['project' => ['id' => (int) $project['id'], 'name' => (string) $project['name'], 'created' => $created], 'version' => $version, 'localCreated' => $localCreated, 'alreadyPublished' => false, 'url' => $this->projectUrl((int) $project['id'])];
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

    private function projectUrl(int $id): string
    {
        return rtrim((string) $this->cloud->status()['url'], '/') . '/projects/' . $id;
    }
}
