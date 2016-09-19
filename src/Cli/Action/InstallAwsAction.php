<?php

/*
 * This file is part of the Acme PHP project.
 *
 * (c) Titouan Galopin <galopintitouan@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace AcmePhp\Cli\Action;

use AcmePhp\Ssl\CertificateResponse;
use Aws\Iam\Exception\IamException;
use Webmozart\Assert\Assert;

/**
 * Action to install certificate in an AWS ELB
 *
 * @author Jérémy Derussé <jeremy@derusse.com>
 */
class InstallAwsAction implements ActionInterface
{
    /**
     * @var AwsClientFactory
     */
    private $awsClientFactory;

    /**
     * @param AwsClientFactory $awsClientFactory
     */
    public function __construct(AwsClientFactory $awsClientFactory)
    {
        $this->awsClientFactory = $awsClientFactory;
    }

    /**
     * {@inheritdoc}
     */
    public function getName()
    {
        return 'install_aws';
    }

    /**
     * {@inheritdoc}
     */
    public function handle($config, CertificateResponse $response)
    {
        Assert::keyExists(
            $config,
            'load_balancer_name',
            'Configuration key "%s" is required in action "'.$this->getName().'"'
        );
        Assert::keyExists(
            $config,
            'region',
            'Configuration key "%s" is required in action "'.$this->getName().'"'
        );

        $loadBalancerName = $config['load_balancer_name'];
        $region = $config['region'];

        $elbClient = $this->awsClientFactory->getElbClient($region);
        $iamClient = $this->awsClientFactory->getIamClient($region);

        $loadBalancerPort = empty($config['load_balancer_port']) ? 443 : $config['load_balancer_port'];
        $certificatePrefix = empty($config['certificate_prefix']) ? 'acmephp_' : $config['certificate_prefix'];
        $certificateName = $certificatePrefix.date('Ymd-His');

        // cleanup old certificates
        $certificates = $iamClient->listServerCertificates()['ServerCertificateMetadataList'];
        foreach ($certificates as $certificate) {
            if (0 === strpos($certificate['ServerCertificateName'], $certificatePrefix)
                && $certificateName !== $certificate['ServerCertificateName']
            ) {
                try {
                    $iamClient->deleteServerCertificate(
                        ['ServerCertificateName' => $certificate['ServerCertificateName']]
                    );
                } catch (IamException $e) {
                    if ($e->getAwsErrorCode() !== 'DeleteConflict') {
                        throw $e;
                    }
                }
            }
        }

        // upload new Certificate
        $issuerChain = [];
        $issuerCertificate = $response->getCertificate()->getIssuerCertificate();
        while (null !== $issuerCertificate) {
            $issuerChain[] = $issuerCertificate->getPEM();
            $issuerCertificate = $issuerCertificate->getIssuerCertificate();
        }
        $chainPem = implode("\n", $issuerChain);

        $response = $iamClient->uploadServerCertificate(
            [
                'ServerCertificateName' => $certificateName,
                'CertificateBody' => $response->getCertificate()->getPEM(),
                'PrivateKey' => $response->getCertificateRequest()->getKeyPair()->getPrivateKey()->getPEM(),
                'CertificateChain' => $chainPem,
            ]
        );

        $certificateArn = $response['ServerCertificateMetadata']['Arn'];

        // install Certificate
        $this->retryCall(
            function () use ($elbClient, $loadBalancerName, $loadBalancerPort, $certificateArn) {
                $elbClient->setLoadBalancerListenerSSLCertificate(
                    [
                        'LoadBalancerName' => $loadBalancerName,
                        'LoadBalancerPort' => $loadBalancerPort,
                        'SSLCertificateId' => $certificateArn,
                    ]
                );
            }
        );

        // cleanup old certificates
        $certificates = $iamClient->listServerCertificates()['ServerCertificateMetadataList'];
        foreach ($certificates as $certificate) {
            if (0 === strpos($certificate['ServerCertificateName'], $certificatePrefix)
                && $certificateName !== $certificate['ServerCertificateName']
            ) {
                try {
                    $this->retryCall(
                        // Try several time to delete certificate given AWS takes time to uninstall previous one
                        function () use ($iamClient, $certificate) {
                            $iamClient->deleteServerCertificate(
                                ['ServerCertificateName' => $certificate['ServerCertificateName']]
                            );
                        }, 5
                    );
                } catch (IamException $e) {
                    if ($e->getAwsErrorCode() !== 'DeleteConflict') {
                        throw $e;
                    }
                }
            }
        }
    }

    private function retryCall($callback, $retryCount = 10, $retrySleep = 1)
    {
        $lastException = null;
        for ($i = 0; $i < $retryCount; $i++) {
            try {
                $callback();

                return;
            } catch (\Exception $e) {
                sleep($retrySleep);
                $lastException = $e;
            }
        }

        throw $lastException;
    }
}
