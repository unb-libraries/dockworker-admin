<?php

namespace Dockworker\Robo\Plugin\Commands;

use Dockworker\DockworkerAdminCommands;
use Dockworker\GitHub\GitHubMultipleRepositoryTrait;
use Dockworker\IO\DockworkerIOTrait;
use Dockworker\Markdown\MarkdownRenderTrait;
use Dockworker\StackOverflow\StackOverflowTeamsClientTrait;
use Dockworker\Twig\TwigTrait;

/**
 * Provides commands to write GitHub repository inventory pages to StackExchange Teams.
 */
class DockworkerInventoryPageCommands extends DockworkerAdminCommands
{
    use DockworkerIOTrait;
    use GitHubMultipleRepositoryTrait;
    use MarkdownRenderTrait;
    use StackOverflowTeamsClientTrait;
    use TwigTrait;

    /**
     * Updates the Dockworker site inventory article in StackExchange Teams.
     *
     * @command inventory:sites:update
     *
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Twig\Error\LoaderError
     * @throws \Twig\Error\RuntimeError
     * @throws \Twig\Error\SyntaxError
     */
    public function updateDockworkerSiteInventoryArticle(): void
    {
        $this->initInventoryPageCommands();
        $this->checkPreflightChecks($this->dockworkerIO);
        $stack_client = $this->stackOverflowTeamsClients['unblibsystems'];

        $this->dockworkerIO->title('Updating Site Inventory Article');

        $this->dockworkerIO->section('Repository Discovery');
        $this->setConfirmRepositoryList(
            $this->dockworkerIO,
            ['unb-libraries'],
            [],
            ['dockworker'],
            [],
            [],
            [],
            '',
            true
        );

        $this->dockworkerIO->section('Updating Article');
        $stack_client->updateArticleBody(
            $this->dockworkerIO,
            192,
            $this->renderTopicSiteInventoryPageBody(
                'Dockworker Project Repositories',
                <<< EOT
This is a list of all the Dockworker repositories, grouped by GitHub topic.

## Warning!
**This Page is Automatically Generated by Dockworker. Any changes made manually
to this article will be overwritten.**
EOT
            )
        );
    }

    /**
     * Initializes the inventory page commands.
     *
     * @param string $team_slug
     *  The StackExchange Teams slug to use.
     *
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    protected function initInventoryPageCommands(string $team_slug = 'unblibsystems'): void
    {
        $this->setStackTeamsClient($team_slug);
        $this->setStackTeamsClientPreflightCheck(
            $this->stackOverflowTeamsClients[$team_slug]
        );
        $this->initGitHubClientApplicationRepo(
            'unb-libraries',
            'dockworker-admin'
        );
    }

    /**
     * Renders a topic-grouped repository inventory page body.
     *
     * @param string $title
     *   The title of the page.
     * @param string $description
     *   The description of the page.
     *
     * @return string
     *   The Markdown for the page.
     *
     * @throws \Twig\Error\LoaderError
     * @throws \Twig\Error\RuntimeError
     * @throws \Twig\Error\SyntaxError
     */
    protected function renderTopicSiteInventoryPageBody(
        string $title,
        string $description
    ): string {
        return $this->renderTwig(
            'inventory-page.md.twig',
            [__DIR__ . '/../../../../data/twig/inventory'],
            [
                'title' => $title,
                'description' => $description,
                'repositories' => $this->getTopicGroupedRepositories(),
            ]
        );
    }

    /**
     * Constructs an associative array of GitHub repositories grouped by topic.
     *
     * @return mixed[]
     *   An array of GitHub repository objects, keyed by topic.
     */
    protected function getTopicGroupedRepositories(): array
    {
        $topic_repos = [];
        foreach ($this->githubRepositories as $repository) {
            foreach ($repository['topics'] as $topic) {
                if (!isset($topic_repos[$topic])) {
                    $topic_repos[$topic] = [];
                }
                $topic_repos[$topic][] = $repository;
            }
        }
        return $topic_repos;
    }
}
