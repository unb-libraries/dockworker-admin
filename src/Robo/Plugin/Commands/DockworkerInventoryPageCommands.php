<?php

namespace Dockworker\Robo\Plugin\Commands;

use Dockworker\DockworkerAdminCommands;
use Dockworker\GitHub\GitHubMultipleRepositoryTrait;
use Dockworker\IO\DockworkerIO;
use Dockworker\IO\DockworkerIOTrait;
use Dockworker\StackExchange\StackExchangeTeamClientTrait;

/**
 * Provides methods to write GitHub repo inventory pages to Stack Overflow.
 */
class DockworkerInventoryPageCommands extends DockworkerAdminCommands
{
    use StackExchangeTeamClientTrait;
    use GitHubMultipleRepositoryTrait;
    use DockworkerIOTrait;

    /**
     * Updates the Dockworker site inventory article.
     *
     * @command inventory:sites:update
     */
    public function updateDockworkerSiteInventoryArticle() {
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
            true,
            [],
        );
    }

  /**
   * Writes an inventory page from GitHub repositories.
   *
   * @param \Dockworker\IO\DockworkerIO $io
   * @param string $article_id
   * @param array $organizations
   * @param array $include_names
   * @param array $include_topics
   * @param array $include_callbacks
   * @param array $omit_names
   * @param array $omit_topics
   * @param string $operation_description
   * @param bool $no_confirm
   *
   * @return void
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
        bool $no_confirm = FALSE
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
    }

    /**
     * Initializes the inventory page commands.
     */
    protected function initInventoryPageCommands() {
        $this->setStackTeamsClient('unblibsystems');
        $this->initGitHubClientApplicationRepo(
            'unb-libraries',
            'dockworker-admin'
        );
    }
}
