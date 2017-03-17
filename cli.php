<?php

require __DIR__ . '/vendor/autoload.php';

use FabioCarneiro\Jira\Command\DeleteGroupsCommand;
use FabioCarneiro\Jira\Command\DeleteUsersCommand;
use GuzzleHttp\Client;
use Symfony\Component\Console\Application;

$application = new Application();

$client = new Client();

$application->add(new DeleteUsersCommand($client));
$application->add(new DeleteGroupsCommand($client));

$application->run();
