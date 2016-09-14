<?php

/*
 * This file is part of the ACME PHP library.
 *
 * (c) Titouan Galopin <galopintitouan@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace AcmePhp\Core\Challenger;

use AcmePhp\Core\Exception\Protocol\ChallengeFailedException;
use AcmePhp\Core\Http\Base64SafeEncoder;
use AcmePhp\Core\Protocol\AuthorizationChallenge;
use Aws\Route53\Route53Client;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * ACME DNS challenger with automate configuration of a AWS route53
 *
 * @author Jérémy Derussé <jeremy@derusse.com>
 */
class Route53Challenger extends DnsChallenger
{
    /**
     * @var Route53Client
     */
    private $client;

    /**
     * @param Route53Client     $client
     * @param Base64SafeEncoder $encoder
     * @param OutputInterface   $output
     */
    public function __construct(
        Route53Client $client,
        Base64SafeEncoder $encoder = null,
        OutputInterface $output = null
    ) {
        parent::__construct($encoder, $output);

        $this->client = $client;
    }

    /**
     * @inheritdoc
     */
    public function initialize(AuthorizationChallenge $authorizationChallenge)
    {
        $entryName = $this->getEntryName($authorizationChallenge);
        $entryValue = $this->getEntryValue($authorizationChallenge);

        $zone = $this->getZone($authorizationChallenge->getDomain());

        $this->client->changeResourceRecordSets(
            [
                'ChangeBatch' => [
                    'Changes' => [
                        [
                            'Action' => 'UPSERT',
                            'ResourceRecordSet' => [
                                'Name' => $entryName,
                                'ResourceRecords' => [
                                    [
                                        'Value' => sprintf('"%s"', $entryValue),
                                    ],
                                ],
                                'TTL' => 5,
                                'Type' => 'TXT',
                            ],
                        ],
                    ],
                ],
                'HostedZoneId' => $zone['Id'],
            ]
        );


        $this->output->writeln(
            sprintf(
                <<<'EOF'
<info>The authorization token was successfully fetched!</info>

<info>A new TXT record had been added to the DNS Zone, but you have to wait it propagation</info>

    1. Check in your terminal that the following command returns the following response
       
         $ host -t TXT %s
         %s descriptive text "%s"
EOF
                ,
                $entryName,
                $entryName,
                $entryValue
            )
        );
    }

    /**
     * @inheritdoc
     */
    public function cleanup(AuthorizationChallenge $authorizationChallenge)
    {
        $entryName = $this->getEntryName($authorizationChallenge);

        $zone = $this->getZone($authorizationChallenge->getDomain());
        $recordSets = $this->client->listResourceRecordSets(
            [
                'HostedZoneId' => $zone['Id'],
                'StartRecordName' => $entryName,
                'StartRecordType' => 'TXT',
            ]
        );

        $recordSets = array_filter(
            $recordSets['ResourceRecordSets'],
            function ($recordSet) use ($entryName) {
                return ($recordSet['Name'] === $entryName && $recordSet['Type'] === 'TXT');
            }
        );

        if (!$recordSets) {
            return;
        }

        $this->client->changeResourceRecordSets(
            [
                'ChangeBatch' => [
                    'Changes' => array_map(
                        function ($recordSet) {
                            return [
                                'Action' => 'DELETE',
                                'ResourceRecordSet' => $recordSet,
                            ];
                        },
                        $recordSets
                    ),
                ],
                'HostedZoneId' => $zone['Id'],
            ]
        );
    }

    private function getZone($domain)
    {
        $domainParts = explode('.', $domain);
        $domains = array_map(
            function ($index) use ($domainParts) {
                return implode('.', array_slice($domainParts, count($domainParts) - $index));
            },
            range(1, count($domainParts))
        );

        $zones = $this->client->listHostedZones()['HostedZones'];
        foreach ($domains as $domain) {
            foreach ($zones as $zone) {
                if ($zone['Name'] === $domain.'.') {
                    return $zone;
                }
            }
        }

        throw new ChallengeFailedException(sprintf('Unable to find a zone for the domain "%s"', $domain));
    }
}
