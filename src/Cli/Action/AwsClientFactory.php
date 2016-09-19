<?php

namespace AcmePhp\Cli\Action;

use Aws\Credentials\CredentialProvider;
use Aws\ElasticLoadBalancing\ElasticLoadBalancingClient;
use Aws\Iam\IamClient;
use Aws\Route53\Route53Client;

class AwsClientFactory
{
    public function getIamClient($region = null)
    {
        return new IamClient($this->getClientArgs(['region' => $region, 'version' => '2010-05-08']));
    }

    public function getElbClient($region = null)
    {
        return new ElasticLoadBalancingClient(
            $this->getClientArgs(['region' => $region, 'version' => '2012-06-01'])
        );
    }

    public function getRoute53Client($region = null)
    {
        return new Route53Client($this->getClientArgs(['region' => $region, 'version' => '2013-04-01']));
    }

    private function getClientArgs(array $args = [])
    {
        if (empty($args['region'])) {
            $args['region'] = getenv('AWS_DEFAULT_REGION');
        }

        if (empty($args['credentials'])) {
            $args['credentials'] = CredentialProvider::env();
        }

        return $args;
    }
}
