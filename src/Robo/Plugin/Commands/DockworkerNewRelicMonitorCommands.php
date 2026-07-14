<?php

namespace Dockworker\Robo\Plugin\Commands;

use Dockworker\Core\PreFlightCheckTrait;
use Dockworker\IO\DockworkerIO;
use Dockworker\DockworkerAdminCommands;
use Dockworker\IO\DockworkerIOTrait;

/**
 * Provides commands to interact with NewRelic Monitors.
 */
class DockworkerNewRelicMonitorCommands extends DockworkerAdminCommands
{
    use DockworkerIOTrait;
    use PreFlightCheckTrait;

    public const ALERT_CONDITION_NAME = 'Unavailable: %s';
    public const NEWRELIC_ACCOUNT_ID = '1005680';
    public const NEWRELIC_ALERT_POLICY_ID = '5732395';
    public const NEWRELIC_GRAPHQL_ENDPOINT = 'https://api.newrelic.com/graphql';
    public const MONITOR_NAME = 'Availability: %s';

    /**
     * The GraphQL client.
     *
     * @var \GuzzleHttp\Client
     */
    protected $graphQlClient;

    /**
     * Creates a NewRelic monitor for a domain.
     *
     * @param string $domain_name
     *   The domain name to monitor.
     * @param string $text_to_match
     *   The text to match on the domain.
     * @param mixed[] $options
     *   The command options.
     *
     * @option bool $no-error-on-redirect
     *   Do not error on redirect. Default is to error.
     * @option bool $no-error-on-ssl
     *   Do not error on SSL validation. Default is to error.
     *
     * @command newrelic:monitor:create
     * @aliases nr-monitor-create
     * @usage hit.lib.unb.ca
     *
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Twig\Error\LoaderError
     * @throws \Twig\Error\RuntimeError
     * @throws \Twig\Error\SyntaxError
     */
    public function createNewRelicMonitor(
        string $domain_name,
        string $text_to_match,
        array $options = [
          'no-error-on-redirect' => false,
          'no-ssl-validation' => false,
        ]
    ): void {
        $this->initNewRelicMonitorCommands($this->dockworkerIO);
        $this->checkPreflightChecks($this->dockworkerIO);

        if (empty(getenv('DOCKWORKER_NEWRELIC_API_KEY'))) {
            $this->dockworkerIO->error('DOCKWORKER_NEWRELIC_API_KEY is not set.');
            return;
        }

        $guid = $this->getMonitorGuidByDomainName($domain_name);

        if (!empty($guid)) {
            $this->dockworkerIO->warning('A Monitor already exists for ' . $domain_name . '. Skipping.');
            return;
        }

        $this->dockworkerIO->title('Creating NewRelic Monitor');
        $this->dockworkerIO->section('Monitor');
        $this->createNewRelicPingMonitor(
            $domain_name,
            $text_to_match,
            self::NEWRELIC_ACCOUNT_ID,
            !$options['no-error-on-redirect'],
            !$options['no-ssl-validation']
        );

        // Wait for the monitor to be created.
        sleep(10);

        $guid = $this->getMonitorGuidByDomainName($domain_name);
        if (!empty($guid)) {
            $this->dockworkerIO->success('Monitor Created. ID: ' . $guid);
            $this->dockworkerIO->section('Alert Condition');
            $this->createAlertConditionForMonitor(
                $domain_name,
                $guid,
                self::NEWRELIC_ACCOUNT_ID,
                self::NEWRELIC_ALERT_POLICY_ID
            );
            $this->dockworkerIO->success('Alert Condition Created');
        }
    }

    /**
     * Creates an alert condition for a monitor.
     *
     * @param string $domain_name
     *   The domain name to create the alert condition for.
     * @param string $monitor_guid
     *   The monitor GUID to create the alert condition for.
     * @param string $owner
     *   The owner of the monitor.
     * @param string $policy_id
     *   The numeric policy ID (Not GUID) to attach the alert condition to.
     */
    protected function createAlertConditionForMonitor(
        string $domain_name,
        string $monitor_guid,
        string $owner,
        string $policy_id
    ): void {
        $alert_condition_name = $this->getAlertConditionName($domain_name);
        $query = <<<GRAPHQL
mutation {
  alertsNrqlConditionStaticCreate(
    accountId: $owner
    policyId: $policy_id
    condition: {
      enabled: true
      name: "$alert_condition_name"
      description: null
      titleTemplate: "{{conditionName}}"
      nrql: {
        query: "SELECT filter(count(*), WHERE result = 'FAILED') AS 'Failures' FROM SyntheticCheck WHERE entityGuid IN ('$monitor_guid') AND NOT isMuted"
      }
      expiration: null
      runbookUrl: null
      signal: {
        aggregationWindow: 300
        fillOption: LAST_VALUE
        aggregationDelay: null
        aggregationMethod: EVENT_TIMER
        aggregationTimer: 60
        fillValue: null
        slideBy: null
        evaluationDelay: null
      }
      terms: [
        {
          operator: ABOVE
          threshold: 0
          priority: CRITICAL
          thresholdDuration: 600
          thresholdOccurrences: ALL
        }
        {
          operator: ABOVE
          threshold: 0
          priority: WARNING
          thresholdDuration: 300
          thresholdOccurrences: ALL
        }
      ]
      violationTimeLimitSeconds: 259200
    }
  ) {
    id
  }
}
GRAPHQL;
        $response = $this->queryNerdGraphApi($query);
    }

    /**
     * Checks if a monitor exists for a domain.
     *
     * @param string $domain_name
     *   The domain name to check.
     *
     * @return bool
     *   TRUE if the monitor exists, FALSE otherwise.
     */
    protected function monitorExists(
        string $domain_name
    ): bool {
        $guid = $this->getMonitorGuidByDomainName($domain_name);
        return !empty($guid);
    }

    /**
     * Initializes the newrelic monitor commands.
     *
     * @param \Dockworker\IO\DockworkerIO $io
     *   The IO to use for input and output.
     *
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    protected function initNewRelicMonitorCommands(
        DockworkerIO $io
    ): void {
        $this->graphQlClient = new \GuzzleHttp\Client();
    }

    /**
     * Creates a NewRelic ping monitor.
     *
     * @param string $domain_name
     *   The domain name to monitor.
     * @param string $text_to_match
     *   The text to match on the domain.
     * @param string $owner
     *   The owner of the monitor.
     * @param bool $redirect_is_error
     *   Whether to error on redirect.
     * @param bool $ssl_invalid_is_error
     *   Whether to error on SSL validation failure.
     */
    protected function createNewRelicPingMonitor(
        string $domain_name,
        string $text_to_match,
        string $owner,
        bool $redirect_is_error = true,
        bool $ssl_invalid_is_error = true,
    ): void {
        $monitor_name = $this->getMonitorName($domain_name);
        $redirect_value = $redirect_is_error ? 'true' : 'false';
        $ssl_value = $ssl_invalid_is_error ? 'true' : 'false';
        $query = <<<GRAPHQL
mutation {
    syntheticsCreateSimpleMonitor (
      accountId: $owner,
      monitor: {
        locations: {
          public: ["CA_CENTRAL_1", "US_EAST_1", "AWS_US_WEST_2"]
          },
        name: "$monitor_name",
        period: EVERY_5_MINUTES,
        status: ENABLED,
        uri: "https://$domain_name",
        advancedOptions: {
          redirectIsFailure: $redirect_value,
          responseValidationText: "$text_to_match",
          shouldBypassHeadRequest: false,
          useTlsValidation: $ssl_value
        },
        apdexTarget: 7.0
      }
  ) {
      errors {
        description
        type
      }
    }
  }
GRAPHQL;
        $response = $this->queryNerdGraphApi($query);
    }

    /**
     * Gets a monitor's GUID by name.
     *
     * @param string $domain_name
     *   The domain name to get the monitor GUID for.
     *
     * @return string
     *   The monitor GUID.
     */
    protected function getMonitorGuidByDomainName(
        string $domain_name
    ): string {
        $monitor_name = $this->getMonitorName($domain_name);
        $query = <<<GRAPHQL
{
  actor {
    entitySearch(
      query: "(name = '$monitor_name')"
    ) {
      results {
        entities {
          ... on SyntheticMonitorEntityOutline {
            guid
            name
            monitorId
          }
        }
      }
    }
  }
}
GRAPHQL;
        $response = $this->queryNerdGraphApi($query);
        if (empty($response->actor->entitySearch->results->entities)) {
            return '';
        }
        return $response->actor->entitySearch->results->entities[0]->guid;
    }

    /**
     * Queries the NewRelic GraphQL API.
     *
     * Requires the DOCKWORKER_NEWRELIC_API_KEY environment variable.
     *
     * @param string $query
     *   The query to run.
     *
     * @return object
     *   The response object.
     */
    protected function queryNerdGraphApi(
        string $query
    ): object {
        $response = $this->graphQlClient->request('POST', self::NEWRELIC_GRAPHQL_ENDPOINT, [
        'headers' => [
            'Content-Type' => 'application/json',
            'API-Key' => getenv('DOCKWORKER_NEWRELIC_API_KEY')
        ],
        'json' => [
            'query' => $query
        ]
        ]);

        $json = $response->getBody()->getContents();
        $body = json_decode($json);
        if (!empty($body->errors)) {
            $this->dockworkerIO->error('Error: ' . print_r($body->errors, true));
            return (object) [];
        }
        $data = $body->data;
        return $data;
    }

    /**
     * Builds a standardized monitor name.
     *
     * @param string $domain_name
     *   The domain name to build the monitor name for.
     *
     * @return string
     *   The monitor name.
     */
    protected function getMonitorName(
        string $domain_name
    ): string {
        return sprintf(self::MONITOR_NAME, $domain_name);
    }

    /**
     * Builds a standardized alert condition name.
     *
     * @param string $domain_name
     *   The domain name to build the alert condition name for.
     *
     * @return string
     *   The alert condition name.
     */
    protected function getAlertConditionName(
        string $domain_name
    ): string {
        return sprintf(self::ALERT_CONDITION_NAME, $domain_name);
    }
}
