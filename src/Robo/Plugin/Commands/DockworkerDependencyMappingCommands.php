<?php

namespace Dockworker\Robo\Plugin\Commands;

use Dockworker\DockworkerAdminCommands;
use Dockworker\Formatter\OutputFormatterTrait;
use Dockworker\GitHub\GitHubMultipleRepositoryTrait;
use Dockworker\IO\DockworkerIOTrait;
use Dockworker\Markdown\MarkdownRenderTrait;

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
     *   The output formatter to use. Defaults to plain.
     * @option string $max-description-length
     *   The maximum length of the description to display.
     * @option string $owner
     *   The owner of the repositories to list. Defaults to 'unb-libraries'.
     *
     * @command inventory:github:repositories
     */
    public function listGitHubRepositories(
        array $options = [
            'formatter' => 'plain',
            'max-description-length' => '48',
            'owner' => 'unb-libraries',
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
            'Status',
            'Comments',
        ];

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
                foreach ($branches as $branch) {
                    // If the repository name begins with docker-, strip it.
                    if (strpos($repository['name'], 'docker-') === 0) {
                        $repository['name'] = substr($repository['name'], 7);
                    }
                    $entity_name = "ghcr.io/{$options['owner']}/" . $repository['name'] . ':' . $branch['name'];
                    $this->dockworkerIO->writeln("Branch: {$branch['name']}");
                    try {
                        /**
                          * @disregard P1013 Undefined type - API returns mixed based on arg.
                          * @phpstan-ignore-next-line
                         */
                        $fileContent = $repo_api->contents()->download(
                            $repository['owner']['login'],
                            $repository['name'],
                            '/Dockerfile',
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
}
