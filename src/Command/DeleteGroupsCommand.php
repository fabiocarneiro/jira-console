<?php

namespace FabioCarneiro\Jira\Command;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ClientException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Zend\Diactoros\Request;
use Zend\Diactoros\Uri;

final class DeleteGroupsCommand extends Command
{
    /**
     * @var Client
     */
    private $client;

    public function __construct(ClientInterface $client, $name = null)
    {
        $this->client = $client;

        parent::__construct($name);
    }

    protected function configure()
    {
        $this
            ->setName('jira:delete-groups')
            ->setDescription('Delete Jira groups')
            ->setDefinition(
                new InputDefinition([
                    new InputOption('dry-run', null, InputOption::VALUE_NONE, 'Run the command in test mode'),
                    new InputArgument('host', InputArgument::REQUIRED, 'JIRA host (ex: example.atlassian.net)'),
                    new InputOption(
                        'username',
                        'u',
                        InputOption::VALUE_REQUIRED,
                        'JIRA username (needs JIRA Rest API permission)'
                    ),
                    new InputOption('password', 'p', InputOption::VALUE_REQUIRED, 'JIRA password'),
                    new InputOption('matching', 'm', InputOption::VALUE_OPTIONAL,
                        'Regular expression to filter groups'),
                ])
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $offset = 0;
        $limit = 1000;

        do {
            $retrievedGroups = $this->retrieveGroups($input, $limit);
            $filteredGroups = $this->filterGroups($input, $retrievedGroups);
            $offset = $offset + $limit;
            foreach ($filteredGroups as $user) {
                $this->deleteGroup($user, $input, $output);
            }
        } while (count($filteredGroups) > 0 && !$input->getOption('dry-run'));
    }

    private function deleteGroup(array $group, InputInterface $input, OutputInterface $output)
    {
        try {
            if (!$input->getOption('dry-run')) {
                $uri = (new Uri())
                    ->withScheme('https')
                    ->withHost($input->getArgument('host'))
                    ->withPath('/rest/api/2/group')
                    ->withUserInfo(urlencode($input->getOption('username')), $input->getOption('password'))
                    ->withQuery(implode('&', [
                        'groupname=' . $group['name'],
                    ]));

                $request = (new Request())
                    ->withMethod('DELETE')
                    ->withUri($uri)
                    ->withAddedHeader('content-type', 'application/json')
                    ->withAddedHeader('cache-control', 'no-cache');

                $this->client->send($request);
            }

            $output->writeln(sprintf('Group %s deleted', $group['name']));
        } catch (ClientException $e) {
            $output->writeln(sprintf(
                'Fail to delete Group %s. (status code %s)',
                $group['name'],
                $e->getResponse()->getStatusCode()
            ));
        }
    }

    /**
     * @param InputInterface $input
     * @param int $limit
     * @return array
     */
    private function retrieveGroups(InputInterface $input, int $limit = 50): array
    {
        $uri = (new Uri())
            ->withScheme('https')
            ->withHost($input->getArgument('host'))
            ->withPath('/rest/api/2/groups/picker')
            ->withUserInfo(urlencode($input->getOption('username')), $input->getOption('password'))
            ->withQuery(implode('&', [
                'maxResults=' . $limit,
            ]));

        $request = (new Request())
            ->withMethod('GET')
            ->withUri($uri)
            ->withAddedHeader('content-type', 'application/json')
            ->withAddedHeader('cache-control', 'no-cache');

        $response = $this->client->send($request);

        $decodedBody = json_decode($response->getBody()->getContents(), true);

        return $decodedBody['groups'];
    }

    private function filterGroups(InputInterface $input, array $retrievedUsers): array
    {
        if (null !== $input->getOption('matching')) {
            $expression = $input->getOption('matching');

            $filteredUsers = array_filter($retrievedUsers, function ($value) use ($expression): bool {
                return preg_match($expression, $value['name']);
            });

            return $filteredUsers;
        }

        return $retrievedUsers;
    }
}
