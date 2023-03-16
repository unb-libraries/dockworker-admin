<?php

namespace Dockworker\Robo\Plugin\Commands;

use Dockworker\DockworkerAdminCommands;
use Dockworker\GitHub\GitHubMultipleRepositoryTrait;
use Dockworker\IO\DockworkerIO;
use Dockworker\IO\DockworkerIOTrait;
use Dockworker\Markdown\MarkdownRenderTrait;
use Dockworker\StackExchange\StackExchangeTeamClientTrait;
use Dockworker\Twig\TwigTrait;

/**
 * Provides methods to write GitHub repo inventory pages to Stack Overflow.
 */
class DockworkerInventoryPageCommands extends DockworkerAdminCommands
{
    use DockworkerIOTrait;
    use GitHubMultipleRepositoryTrait;
    use MarkdownRenderTrait;
    use StackExchangeTeamClientTrait;
    use TwigTrait;

    /**
     * Updates the Dockworker site inventory article.
     *
     * @command inventory:sites:update
     *
     * @throws \Twig\Error\LoaderError
     * @throws \Twig\Error\RuntimeError
     * @throws \Twig\Error\SyntaxError
     */
    public function updateDockworkerSiteInventoryArticle(): void
    {
        $this->initInventoryPageCommands();
        $this->checkPreflightChecks($this->dockworkerIO);
        $this->writeInventoryPageFromGithubRepos(
            $this->dockworkerIO,
            '192',
            ['unb-libraries'],
            [],
            ['dockworker'],
            [],
            [],
            [],
            'Write Inventory Pages',
            true
        );
    }

    /**
     * Writes an inventory page from GitHub selectors to a Stack article.
     *
     * @param \Dockworker\IO\DockworkerIO $io
     *   The IO to use for input and output.
     * @param string $article_id
     *   The ID of the Stack Overflow article to update.
     * @param array $organizations
     *   The GitHub organizations to search for repositories.
     * @param array $include_names
     *   The names of repositories to include.
     * @param array $include_topics
     *   The topics of repositories to include.
     * @param array $include_callbacks
     *   The callbacks to use to determine if a repository should be included.
     * @param array $omit_names
     *   The names of repositories to omit.
     * @param array $omit_topics
     *   The topics of repositories to omit.
     * @param string $operation_description
     *   The description of the operation to perform.
     * @param bool $no_confirm
     *   TRUE to skip confirmation steps.
     *
     * @throws \Twig\Error\LoaderError
     * @throws \Twig\Error\RuntimeError
     * @throws \Twig\Error\SyntaxError
     */
    protected function writeInventoryPageFromGithubRepos(
        DockworkerIO $io,
        string $article_id,
        array $organizations,
        array $include_names = [],
        array $include_topics = [],
        array $include_callbacks = [],
        array $omit_names = [],
        array $omit_topics = [],
        string $operation_description = 'operation',
        bool $no_confirm = false
    ): void {
        $this->setConfirmRepositoryList(
            $io,
            $organizations,
            $include_names,
            $include_topics,
            $include_callbacks,
            $omit_names,
            $omit_topics,
            $operation_description,
            $no_confirm
        );
        $topic_repos = [];
        foreach ($this->githubRepositories as $repository) {
            foreach ($repository['topics'] as $topic) {
                if (!isset($topic_repos[$topic])) {
                    $topic_repos[$topic] = [];
                }
                $topic_repos[$topic][] = $repository;
            }
        }
        $markdown = $this->generateTopicSiteInventoryPage(
            'Dockworker',
            'This is a list of all the Dockworker repositories, grouped by tag.',
            $topic_repos
        );
    }

    /**
     * Generates the Markdown for a topic-organized site inventory page.
     *
     * @param string $title
     *   The title of the page.
     * @param string $description
     *   The description of the page.
     * @param array $repositories
     *   The repositories to include in the page.
     *
     * @return string
     *   The Markdown for the page.
     *
     * @throws \Twig\Error\LoaderError
     * @throws \Twig\Error\RuntimeError
     * @throws \Twig\Error\SyntaxError
     */
    protected function generateTopicSiteInventoryPage(
        string $title,
        string $description,
        array $repositories
    ): string {
        $markdown = $this->renderTwig(
            'inventory-page.md.twig',
            [__DIR__ . '/../../../../data/twig/inventory'],
            [
            'title' => $title,
            'description' => $description,
            'site_list' => $this->generateSiteList($repositories),
            ]
        );
        return $markdown;
    }

    /**
     * Generates a repository lists for a topic site inventory page.
     *
     * @param array $repositories
     *   The repositories to include in the list.
     *
     * @return string
     *   The generated markdown for the lists.
     */
    protected function generateSiteList(
        array $repositories
    ): string {
        $markdown = '';
        foreach ($repositories as $repository_tag => $repository_list) {
            $markdown .= "## $repository_tag\n";
            $markdown .= $this->renderMarkdownList(
                $repository_list
            );
            $markdown .= "\n";
        }
        return $markdown;
    }

    /**
     * Renders a list of repositories as a Markdown list.
     *
     * @param $repositories
     *   The repositories to render.
     *
     * @return string
     *   The rendered Markdown list.
     */
    protected function renderMarkdownList($repositories): string
    {
        $markdown = "\n";
        foreach ($repositories as $repository) {
            $markdown .= "* [{$repository['name']}]({$repository['html_url']}): {$repository['description']}\n";
        }
        $markdown .= "\n";
        return $markdown;
    }

    /**
     * Initializes the inventory page commands.
     */
    protected function initInventoryPageCommands(): void
    {
        $this->setStackTeamsClient('unblibsystems');
        $this->initGitHubClientApplicationRepo(
            'unb-libraries',
            'dockworker-admin'
        );
    }

    protected static function getRepositoryTableHeaders(): array
    {
        return [
            'ID',
            'Name',
            'Description',
            'URL',
            'Last Updated',
            'Last Pushed',
        ];
    }

    /**
     * Gets the repository detail rows for a repository list table.
     *
     * @param array $repositories
     *   The repositories to get the details for.
     *
     * @return array
     *   The rows for the repository table.
     */
    protected static function getRepositoryTableRows(array $repositories): array
    {
        $rows = [];
        $row_id = 1;
        foreach ($repositories as $repository) {
            $row = self::getRepositoryTableRow($repository);
            array_unshift($row, $row_id);
            $rows[] = $row;
            $row_id++;
        }
        return $rows;
    }

    /**
     * Gets a repository detail row for a repository list.
     *
     * @param array $repository
     *   The repository to get the details for.
     *
     * @return array
     *   The row for the list table.
     */
    protected static function getRepositoryTableRow(array $repository): array
    {
        return [
            $repository['name'],
            $repository['description'],
            $repository['html_url'],
            $repository['updated_at'],
            $repository['pushed_at'],
        ];
    }
}
