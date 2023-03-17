<?php

namespace Dockworker\Robo\Plugin\Commands;

use Dockworker\DockworkerAdminCommands;
use Dockworker\GitHub\GitHubMultipleRepositoryTrait;
use Dockworker\IO\DockworkerIOTrait;
use Dockworker\Markdown\MarkdownRenderTrait;
use Dockworker\StackExchange\StackExchangeTeamClientTrait;
use Dockworker\Twig\TwigTrait;

/**
 * Provides methods to write GitHub repository inventory pages to Stack Overflow.
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
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Twig\Error\LoaderError
     * @throws \Twig\Error\RuntimeError
     * @throws \Twig\Error\SyntaxError
     */
    public function updateDockworkerSiteInventoryArticle(): void
    {
        $this->initInventoryPageCommands();
        $this->checkPreflightChecks($this->dockworkerIO);
        $stack_client = $this->stackExchangeTeamClients['unblibsystems'];

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
          '192',
          $this->renderTopicSiteInventoryPageBody(
            'Dockworker',
            'This is a list of all the Dockworker repositories, grouped by topic.',
            $this->getTopicGroupedRepositories()
          )
        );
    }

    /**
     * Constructs a list of GitHub repositories grouped by their topics.
     *
     * @return array
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

    /**
     * Renders a topic-grouped repository inventory page body.
     *
     * @TODO The iteration and output formatting in the called functions should
     *    be shifted to twig. These functions should be format-agnostic.
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
    protected function renderTopicSiteInventoryPageBody(
        string $title,
        string $description,
        array $repositories
    ): string {
        return $this->renderTwig(
            'inventory-page.md.twig',
            [__DIR__ . '/../../../../data/twig/inventory'],
            [
                'title' => $title,
                'description' => $description,
                'repo_list' => $this->renderTopicRepositoryList($repositories),
            ]
        );
    }

    /**
     * Renders a repository list for a topic-grouped inventory page.
     *
     * @param array $repositories
     *   The repositories to include in the list.
     *
     * @return string
     *   The generated markdown for the lists.
     */
    protected function renderTopicRepositoryList(
        array $repositories
    ): string {
        $markdown = '';
        foreach ($repositories as $repository_topic => $repository_list) {
            $markdown .= "## $repository_topic\n";
            $markdown .= $this->renderRepositoryList(
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
    protected function renderRepositoryList($repositories): string
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
     *
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    protected function initInventoryPageCommands(): void
    {
        $this->setStackTeamsClient('unblibsystems');
        $this->initGitHubClientApplicationRepo(
            'unb-libraries',
            'dockworker-admin'
        );
    }
}
