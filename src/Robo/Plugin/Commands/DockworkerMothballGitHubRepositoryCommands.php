<?php

namespace Dockworker\Robo\Plugin\Commands;

use Dockworker\IO\DockworkerIO;
use Dockworker\DockworkerAdminCommands;
use Dockworker\Git\GitLocalCloneTrait;
use Dockworker\GitHub\GitHubMultipleRepositoryTrait;
use Dockworker\IO\DockworkerIOTrait;
use Dockworker\Mothball\MothballTrait;

/**
 * Provides commands to mothball GitHub repositories.
 */
class DockworkerMothballGitHubRepositoryCommands extends DockworkerAdminCommands
{
    use DockworkerIOTrait;
    use GitHubMultipleRepositoryTrait;
    use GitLocalCloneTrait;
    use MothballTrait;

    /**
     * Mothballs a GitHub repository in preparation for removing it from GitHub.
     *
     * @param string $repository_name
     *   The repository name to mothball.
     * 
     * @option string $owner
     *   The owner of the repository to mothball.
     * 
     * @command github:repository:mothball
     * @aliases mothball-repo
     * @usage hit.lib.unb.ca
     *
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Twig\Error\LoaderError
     * @throws \Twig\Error\RuntimeError
     * @throws \Twig\Error\SyntaxError
     */
    public function mothballGitHubRepository(
        string $repository_name,
        array $options = [
            'owner' => 'unb-libraries',
        ]
    ): void
    {
        $this->initGitHubMothballCommands($this->dockworkerIO, $repository_name, $options['owner']);
        $this->checkPreflightChecks($this->dockworkerIO);

        $this->dockworkerIO->title('Mothballing GitHub Repository');

        $this->dockworkerIO->section('Repository Discovery');
        $this->setConfirmRepositoryList(
            $this->dockworkerIO,
            [$options['owner']],
            [$repository_name],
            [],
            [],
            [],
            [],
            '',
            true
        );
        if (empty($this->githubRepositories)) {
            $this->dockworkerIO->error("Repository {$options['owner']}/$repository_name not found.");
            exit(1);
        }
        $this->dockworkerIO->section('Mothballing Repository');   
        $repo = $this->githubRepositories[0];

        $this->dockworkerIO->writeln("Mothballing {$repo['full_name']} to local...");
        $notes = $this->ask("Enter additional notes for mothball metadata. Press ENTER to continue without adding notes.");
        $metadata = [
            'archived_on' => date('Y-m-d H:i:s'),
            'archived_by' => $this->userName,
            'timestamp' => time(),
            'notes' => $notes,
            'repository' => $repo,
        ];
        $archive_path = $this->archiveGitHubRepository(
            $options['owner'],
            $repository_name,
            $repo['default_branch'],
            $metadata
        );

        $remote_folder = $this->dockworkerIO->ask("Path on $this->mothballHost to mothball the respository to", "$this->mothballPath/GitHub/$repository_name");
        $remote_folder = rtrim($remote_folder, '/');
        $remote_folder_uri = "$this->mothballHost:$remote_folder";
        

        $this->dockworkerIO->writeln("Archiving to $remote_folder_uri...");
        passthru("rsync -avhz $archive_path/ $remote_folder_uri");

        $this->dockworkerIO->section('Mothball Complete!');
        $this->dockworkerIO->block("The process is complete! The mothball should now be available at $remote_folder_uri.");
        $this->dockworkerIO->say("Please verify the archive is complete, then you may Archive/Delete the GitHub repository via the web interface.");
    }

    /**
     * Initializes the mothball commands.
     *
     * @param string $repository_name
     *   The repository name to mothball.
     * @param string $repository_owner
     *   The owner of the repository to mothball.
     *
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    protected function initGitHubMothballCommands(DockworkerIO $io, string $repository_name, string $repository_owner): void
    {
        $this->initMothballConfig();
        $this->initGitHubClientApplicationRepo(
            $repository_owner,
            $repository_name
        );
        $this->initRsyncCommand($io);
    }
}
