<?php

namespace Dockworker\Robo\Plugin\Commands;

use Dockworker\DockworkerAdminCommands;
use Dockworker\Formatter\OutputFormatterTrait;
use Dockworker\GitHub\GitHubMultipleRepositoryTrait;
use Dockworker\IO\DockworkerIOTrait;
use Dockworker\Markdown\MarkdownRenderTrait;
use Symfony\Component\Yaml\Yaml;

/**
 * Provides commands to write GitHub repository inventory pages to StackExchange Teams.
 */
class DockworkerDependencyMappingCommands extends DockworkerAdminCommands
{
    use DockworkerIOTrait;
    use GitHubMultipleRepositoryTrait;
    use MarkdownRenderTrait;
    use OutputFormatterTrait;

    /**
     * Displays a list of GitHub Repositories.
     *
     * @param mixed[] $options
     *   The command options.
     *
     * @option string $formatter
     *   The output formatter to use. One of 'plain', 'jira'.
     * @option string $max-description-length
     *   The maximum length of the description to display.
     * @option string $owner
     *   The owner of the repositories to list. Defaults to 'unb-libraries'.
     * @option string $sort
     *   The sort order of the repositories. One of 'name', 'updated'.
     *
     * @command inventory:github:repositories
     */
    public function listGitHubRepositories(
        array $options = [
            'formatter' => 'plain',
            'max-description-length' => '48',
            'owner' => 'unb-libraries',
            'sort' => 'name',
        ]
    ): void {
        $this->initInventoryCommands($options['owner']);
        $this->checkPreflightChecks($this->dockworkerIO);

        $formatter = $this->setOutputFormatter($options['formatter']);
        $this->dockworkerIO->title('Generating GitHub Repository Inventory');
        $this->dockworkerIO->section('Repository Discovery');
        $this->setConfirmRepositoryList(
            $this->dockworkerIO,
            [$options['owner']],
            [],
            [],
            [],
            [],
            [],
            '',
            true
        );

        $rows = [];
        $headers = [
            'Repository',
            'Description',
            'Last Updated',
            'Status',
            'Comments',
        ];

        // Sort the repositories.
        if ($options['sort'] === 'name') {
            usort($this->githubRepositories, function ($a, $b) {
                return strcasecmp($a['name'], $b['name']);
            });
        } elseif ($options['sort'] === 'updated') {
            usort($this->githubRepositories, function ($a, $b) {
                return strtotime($b['updated_at']) <=> strtotime($a['updated_at']);
            });
        }

        foreach ($this->githubRepositories as $repository) {
            if (empty($repository['description'])) {
                $repository['description'] = ' ';
            }
            $description = strlen($repository['description']) > $options['max-description-length']
                ? substr($repository['description'], 0, $options['max-description-length']) . "..."
                : $repository['description'];

            $rows[] = [
                $formatter->generateLink($repository['html_url'], $repository['name']),
                $description,
                $repository['updated_at'],
                ' ',
                ' ',
            ];
        }
        $this->dockworkerIO->section('Github Repositories');
        $this->dockworkerIO->write(
            $formatter->generateTable($headers, $rows)
        );
    }

    /**
     * Displays a list of dependencies between a owner's repository.
     *
     * @param mixed[] $options
     *   The command options.
     *
     * @option string $formatter
     *   The output formatter to use. Defaults to plain.
     * @option string $owner
     *   The owner of the repositories to list. Defaults to 'unb-libraries'.
     *
     * @command inventory:dependencies
     *
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Twig\Error\LoaderError
     * @throws \Twig\Error\RuntimeError
     * @throws \Twig\Error\SyntaxError
     */
    public function displayDockworkerDependencies(
        array $options = [
            'formatter' => 'plain',
            'owner' => 'unb-libraries',
        ]
    ): void {
        $this->initInventoryCommands($options['owner']);
        $this->checkPreflightChecks($this->dockworkerIO);

        $this->dockworkerIO->title('Updating Site Inventory Article');
        $this->dockworkerIO->section('Repository Discovery');
            $formatter = $this->setOutputFormatter($options['formatter']);
        $this->setConfirmRepositoryList(
            $this->dockworkerIO,
            [$options['owner']],
            [],
            [],
            [],
            [],
            [],
            '',
            true
        );

        $this->dockworkerIO->section('Discovering Dependencies');
        $dependencies = [];
        $images = [];
        $docker_repos = [];
        foreach ($this->githubRepositories as $repository) {
            try {
                $this->dockworkerIO->section($repository['name']);
                $repo_api = $this->gitHubClient->api('repo');
                /**
                  * @disregard P1013 Undefined type - API returns mixed based on arg.
                  * @phpstan-ignore-next-line
                 */
                $branches = $repo_api->branches($repository['owner']['login'], $repository['name']);  //
                // The published image drops any docker- prefix, but the repository
                // itself keeps it. Never overwrite $repository['name'] with this: it
                // is the name every subsequent API call needs.
                $image_repo_name = strpos($repository['name'], 'docker-') === 0
                    ? substr($repository['name'], 7)
                    : $repository['name'];
                foreach ($branches as $branch) {
                    $entity_name = "ghcr.io/{$options['owner']}/" . $image_repo_name . ':' . $branch['name'];
                    $this->dockworkerIO->writeln("Branch: {$branch['name']}");
                    try {
                        $fileContent = $this->downloadRepositoryDockerfile(
                            $repository['owner']['login'],
                            $repository['name'],
                            $branch['name']
                        );
                        $dependency_image = $this->extractDependencyImageName($fileContent);
                        echo "Theoretical Docker Image: $entity_name\n";
                        echo "Base Image: $dependency_image\n";
                        if (!isset($dependencies[$dependency_image])) {
                            $dependencies[$dependency_image] = [
                            'image' => $dependency_image,
                            'repositories' => [],
                            'repository_count' => 0,
                            ];
                        }
                        $dependencies[$dependency_image]['repositories'][] = $entity_name;
                        $dependencies[$dependency_image]['repository_count'] += 1;
                        if (!in_array($dependency_image, $images)) {
                            $images[] = $dependency_image;
                        }
                        if (!in_array($repository['name'], $docker_repos)) {
                            $docker_repos[] = $repository['name'];
                        }
                    } catch (\Exception $e) {
                        echo "Repository: $entity_name\n";
                        echo "Dependency Image: Not Found\n";
                    }
                    sleep(1);
                }
            } catch (\Exception $e) {
                echo "Except: {$repository['name']}\n";
            }
        }
        // Sort the dependencies by repository count descending.
        usort($dependencies, function ($a, $b) {
            return $b['repository_count'] <=> $a['repository_count'];
        });
        file_put_contents('internal_docker_image_dependencies.json', json_encode($dependencies, JSON_PRETTY_PRINT));
        $this->dockworkerIO->section('Images Used by Other Images');
        print_r($dependencies);

        // Sort the used images alphabetically.
        sort($images);
        $this->dockworkerIO->section('Images Used by Dockerfiles');
        file_put_contents('all_dependency_images.json', json_encode($images, JSON_PRETTY_PRINT));
        print_r($images);

        // Sort the repositories alphabetically.
        sort($docker_repos);
        $this->dockworkerIO->section('Repositories with Dockerfiles');
        file_put_contents('docker_repos.json', json_encode($docker_repos, JSON_PRETTY_PRINT));
        print_r($docker_repos);
    }

    /**
     * Downloads a repository's Dockerfile, resolving non-root paths if needed.
     *
     * Tries the conventional root Dockerfile first, so the common case still
     * costs a single API call. Only when that is absent is the repository's
     * build workflow consulted, since it may declare a 'dockerfile' input
     * pointing somewhere else.
     *
     * @param string $owner
     *   The repository owner.
     * @param string $name
     *   The repository name.
     * @param string $branch
     *   The branch to read the Dockerfile from.
     *
     * @return string
     *   The contents of the Dockerfile.
     *
     * @throws \Exception
     *   If no Dockerfile could be located on the branch.
     */
    protected function downloadRepositoryDockerfile(
        string $owner,
        string $name,
        string $branch
    ): string {
        /**
          * @disregard P1013 Undefined type - API returns mixed based on arg.
          * @phpstan-ignore-next-line
         */
        $contents = $this->gitHubClient->api('repo')->contents();
        try {
            return (string) $contents->download($owner, $name, '/Dockerfile', $branch);
        } catch (\Exception $e) {
            $path = $this->resolveRepositoryDockerfilePath($owner, $name, $branch);
            if ($path === '/Dockerfile') {
                // Nothing else to try; let the caller report it as not found.
                throw $e;
            }
            return (string) $contents->download($owner, $name, $path, $branch);
        }
    }

    /**
     * Extracts the image name from a Dockerfile.
     *
     * @param string $fileContent
     *
     * @return string
     */
    protected function extractDependencyImageName(string $fileContent): string
    {
        $lines = explode("\n", $fileContent);
        foreach ($lines as $line) {
            if (strpos($line, 'FROM') === 0) {
                $parts = explode(' ', $line);
                return $parts[1];
            }
        }
        return '';
    }

    /**
     * Initializes the inventory page commands.
     *
     * @param string $owner
     *  The owner of the repositories to init with.
     *
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    protected function initInventoryCommands(string $owner): void
    {
        $this->initGitHubClientApplicationRepo(
            $owner,
            'dockworker-admin'
        );
    }

    /**
     * Resolves where a repository's image is built from on a given branch.
     *
     * The authoritative location is the 'dockerfile' input passed to the
     * dockworker build workflow, so any workflow file declaring that input is
     * treated as the source of truth. Repositories that do not declare it build
     * the conventional root Dockerfile.
     *
     * @param string $owner
     *   The repository owner.
     * @param string $name
     *   The repository name.
     * @param string $branch
     *   The branch to read the workflows from.
     *
     * @return string
     *   The Dockerfile path, defaulting to '/Dockerfile'.
     */
    protected function resolveRepositoryDockerfilePath(
        string $owner,
        string $name,
        string $branch
    ): string {
        $default = '/Dockerfile';
        /**
          * @disregard P1013 Undefined type - API returns mixed based on arg.
          * @phpstan-ignore-next-line
         */
        $contents = $this->gitHubClient->api('repo')->contents();
        try {
            $workflows = $contents->show($owner, $name, '.github/workflows', $branch);
        } catch (\Exception $e) {
            // No workflows directory on this branch.
            return $default;
        }
        if (!is_array($workflows)) {
            return $default;
        }

        foreach ($workflows as $workflow) {
            if (!is_array($workflow) || !isset($workflow['name'], $workflow['path'])) {
                continue;
            }
            if (!preg_match('/\.ya?ml$/', $workflow['name'])) {
                continue;
            }
            try {
                $parsed = Yaml::parse(
                    (string) $contents->download($owner, $name, $workflow['path'], $branch)
                );
            } catch (\Exception $e) {
                // Unreadable or malformed workflow; it cannot tell us anything.
                continue;
            }
            if (!is_array($parsed) || !is_array($parsed['jobs'] ?? null)) {
                continue;
            }
            foreach ($parsed['jobs'] as $job) {
                if (!is_array($job) || empty($job['with']['dockerfile'])) {
                    continue;
                }
                // The workflow input is relative to the repository root, while
                // the contents API expects a single leading slash.
                $path = (string) $job['with']['dockerfile'];
                if (str_starts_with($path, './')) {
                    $path = substr($path, 2);
                }
                return '/' . ltrim($path, '/');
            }
        }

        return $default;
    }
}
