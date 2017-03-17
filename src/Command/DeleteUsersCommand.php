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

final class DeleteUsersCommand extends Command
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
            ->setName('jira:delete-users')
            ->setDescription('Delete JIRA users')
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
                    new InputOption('matching', 'm', InputOption::VALUE_OPTIONAL, 'Regular expression to filter users'),
                ])
            );;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $offset = 0;
        $limit = 1000;

        do {
            $retrievedUsers = $this->retrieveUsers($input, $offset, $limit);
            $filteredUsers = $this->filterUsers($input, $retrievedUsers);
            $offset = $offset + $limit;
            foreach ($filteredUsers as $user) {
                $this->deleteUser($user, $input, $output);
            }
        } while (count($retrievedUsers) > 0);
    }

    private function deleteUser(array $user, InputInterface $input, OutputInterface $output)
    {
        try {
            if (!$input->getOption('dry-run')) {
                $uri = (new Uri())
                    ->withScheme('https')
                    ->withHost($input->getArgument('host'))
                    ->withPath('/rest/api/2/user')
                    ->withUserInfo(urlencode($input->getOption('username')), $input->getOption('password'))
                    ->withQuery(implode('&', [
                        'key=' . $user['key'],
                    ]));

                $request = (new Request())
                    ->withMethod('DELETE')
                    ->withUri($uri)
                    ->withAddedHeader('content-type', 'application/json')
                    ->withAddedHeader('cache-control', 'no-cache');

                $this->client->send($request);
            }

            $output->writeln(sprintf('User %s deleted', $user['emailAddress']));
        } catch (ClientException $e) {
            $output->writeln(sprintf(
                'Fail to delete User %s. (status code %s)',
                $user['emailAddress'],
                $e->getResponse()->getStatusCode()
            ));
        }
    }

    /**
     * @param InputInterface $input
     * @param int $offset
     * @param int $limit
     * @return array
     */
    private function retrieveUsers(InputInterface $input, int $offset = 0, int $limit = 50): array
    {
        $uri = (new Uri())
            ->withScheme('https')
            ->withHost($input->getArgument('host'))
            ->withPath('/rest/api/2/user/search')
            ->withUserInfo(urlencode($input->getOption('username')), $input->getOption('password'))
            ->withQuery(implode('&', [
                'username=%25',
                'startAt=' . $offset,
                'maxResults=' . $limit,
            ]));

        $request = (new Request())
            ->withMethod('GET')
            ->withUri($uri)
            ->withAddedHeader('content-type', 'application/json')
            ->withAddedHeader('cache-control', 'no-cache');

        $response = $this->client->send($request);

        return json_decode($response->getBody()->getContents(), true);
    }

    private function filterUsers(InputInterface $input, array $retrievedUsers): array
    {
        if (null !== $input->getOption('matching')) {
            $expression = $input->getOption('matching');

            $filteredUsers = array_filter($retrievedUsers, function ($value) use ($expression): bool {
                return preg_match($expression, $value['emailAddress']);
            });

            return $filteredUsers;
        }

        return $retrievedUsers;
    }
}
