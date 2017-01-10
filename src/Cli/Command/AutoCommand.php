<?php

/*
 * This file is part of the Acme PHP project.
 *
 * (c) Titouan Galopin <galopintitouan@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace AcmePhp\Cli\Command;

use AcmePhp\Cli\Configuration\DomainConfiguration;
use AcmePhp\Core\Challenge\SolverInterface;
use AcmePhp\Core\Challenge\ValidatorInterface;
use AcmePhp\Core\Exception\Protocol\ChallengeNotSupportedException;
use AcmePhp\Core\Exception\Server\MalformedServerException;
use AcmePhp\Core\Protocol\AuthorizationChallenge;
use AcmePhp\Ssl\CertificateRequest;
use AcmePhp\Ssl\CertificateResponse;
use AcmePhp\Ssl\DistinguishedName;
use AcmePhp\Ssl\KeyPair;
use AcmePhp\Ssl\ParsedCertificate;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Yaml\Yaml;
use Webmozart\PathUtil\Path;

/**
 * @author Jérémy Derussé <jeremy@derusse.com>
 */
class AutoCommand extends AbstractCommand
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setName('auto')
            ->setDefinition(
                [
                    new InputArgument('config', InputArgument::REQUIRED, 'path to the config file'),
                    new InputOption(
                        'force',
                        'f',
                        InputOption::VALUE_NONE,
                        'Whether to force renewal or not (by default, renewal will be done only if the certificate expire in less than a week)'
                    ),
                ]
            )
            ->setDescription('Automaticaly chalenge domain and request certificates configured in the given file')
            ->setHelp(
                <<<'EOF'
                The <info>%command.name%</info> request and check domain, the request to the ACME server a SSL certificate for a
given domain.

This certificate will be stored in the Acme PHP storage directory.

You need to be the proved owner of the domain you ask a certificate for. To prove your ownership
of the domain, please use commands <info>authorize</info> and <info>check</info> before this one.
EOF
            );
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $config = $this->getConfig(Path::makeAbsolute($input->getArgument('config'), getcwd()));

        if (isset($config['aws'])) {
            putenv('AWS_ACCESS_KEY_ID='.$config['aws']['access_key_id']);
            putenv('AWS_SECRET_ACCESS_KEY='.$config['aws']['secret_access_key']);
            putenv('AWS_DEFAULT_REGION='.$config['aws']['default_region']);
        }

        $this->register($config['contact_email']);
        foreach ($config['domains'] as $domain => $domainConfig) {
            $this->challengeDomains($domain, $config['solver'], $domainConfig);
            $response = $this->requestCertificate($domain, $domainConfig, $input->getOption('force'));
            $this->installCertificate($response, $domainConfig['install']);
        }
    }

    private function register($email)
    {
        $this->output->writeln(
            sprintf(
                '<comment>Registering contact %s ...</comment>',
                $email
            )
        );

        $repository = $this->getRepository();
        if (!$repository->hasAccountKeyPair()) {
            $this->output->writeln('<info>No account key pair was found, generating one...</info>');

            /** @var KeyPair $accountKeyPair */
            $accountKeyPair = $this->getContainer()->get('ssl.key_pair_generator')->generateKeyPair();

            $repository->storeAccountKeyPair($accountKeyPair);
        }

        $client = $this->getClient();
        $this->output->writeln('<info>Registering on the ACME server...</info>');
        try {
            $client->registerAccount(null, $email);
            $this->output->writeln('<info>Account registered successfully!</info>');
        } catch (MalformedServerException $e) {
            $this->output->writeln('<info>Account already registered!</info>');
        }
    }

    private function installCertificate(CertificateResponse $response, array $actions)
    {
        $this->output->writeln(
            sprintf(
                '<comment>Installing certificate for domain %s ...</comment>',
                $response->getCertificateRequest()->getDistinguishedName()->getCommonName()
            )
        );

        foreach ($actions as $actionConfig) {
            $handler = $this->getContainer()->get('action.'.$actionConfig['action']);
            $handler->handle($actionConfig['args'], $response);

            $this->output->writeln(
                sprintf(
                    '<info>Certificate installed with the action %s.</info>',
                    $actionConfig['action']
                )
            );
        }
    }

    private function isUpToDate($domain, $domainConfig)
    {
        $repository = $this->getRepository();

        if (!$repository->hasDomainDistinguishedName($domain)) {
            return false;
        }

        $distinguishedName = $repository->loadDomainDistinguishedName($domain);
        $wantedCertificates = array_values(array_unique(array_merge([$domain], $domainConfig['subject_alternative_names'])));
        $requestedCertificates = array_values(array_unique(array_merge([$distinguishedName->getCommonName()], $distinguishedName->getSubjectAlternativeNames())));
        if ($wantedCertificates != $requestedCertificates) {
            return false;
        }

        if (!$repository->hasDomainCertificate($domain)) {
            return false;
        }

        $certificate = $repository->loadDomainCertificate($domain);
        /** @var ParsedCertificate $parsedCertificate */
        $parsedCertificate = $this->getContainer()->get('ssl.certificate_parser')->parse($certificate);
        if ($parsedCertificate->getValidTo() < new \DateTime('85 days')) {
            return false;
        }

        return true;
    }

    private function requestCertificate($domain, $domainConfig, $force)
    {
        $this->output->writeln(sprintf('<comment>Requesting certificate for domain %s ...</comment>', $domain));

        $repository = $this->getRepository();
        if (!$force && $this->isUpToDate($domain, $domainConfig)) {
            $certificate = $repository->loadDomainCertificate($domain);
            /** @var ParsedCertificate $parsedCertificate */
            $parsedCertificate = $this->getContainer()->get('ssl.certificate_parser')->parse($certificate);

            $this->output->writeln(sprintf(
                '<info>Current certificate is valid until %s, renewal is not necessary. Use --force to force renewal.</info>',
                $parsedCertificate->getValidTo()->format(\DateTime::ISO8601)
            ));

            return new CertificateResponse(
                new CertificateRequest(
                    $repository->loadDomainDistinguishedName($domain),
                    $repository->loadDomainKeyPair($domain)
                ),
                $certificate
            );
        }

        $client = $this->getClient();
        $distinguishedName = new DistinguishedName(
            $domain,
            $domainConfig['country'],
            $domainConfig['state'],
            $domainConfig['locality'],
            $domainConfig['organization_name'],
            $domainConfig['organization_unit_name'],
            $domainConfig['email_address'],
            $domainConfig['subject_alternative_names']
        );

        if ($repository->hasDomainKeyPair($domain)) {
            $domainKeyPair = $repository->loadDomainKeyPair($domain);
        } else {
            $domainKeyPair = $this->getContainer()->get('ssl.key_pair_generator')->generateKeyPair();
            $repository->storeDomainKeyPair($domain, $domainKeyPair);
        }

        $repository->storeDomainDistinguishedName($domain, $distinguishedName);

        $csr = new CertificateRequest($distinguishedName, $domainKeyPair);
        $response = $client->requestCertificate($domain, $csr);

        $this->output->writeln('<info>Certificate requested successfully!</info>');

        $repository->storeCertificateResponse($response);

        return $response;
    }

    private function challengeDomains($domain, $solverName, $domainConfig)
    {
        $repository = $this->getRepository();
        $client = $this->getClient();

        $challengeDomains = array_unique(array_merge([$domain], $domainConfig['subject_alternative_names']));

        if (!$this->getContainer()->has('challenge_solver.'.$solverName)) {
            throw new \UnexpectedValueException(sprintf('The solver "%s" does not exists', $solverName));
        }

        /** @var SolverInterface $solver */
        $solver = $this->getContainer()->get('challenge_solver.'.$solverName);
        /** @var ValidatorInterface $validator */
        $validator = $this->getContainer()->get('challenge_validator');

        $challengePerDomain = [];
        $this->output->writeln('<comment>Requesting authorization tokens...</comment>');
        foreach ($challengeDomains as $challengeDomain) {
            $this->output->writeln(sprintf('<info>Requesting an authorization token for domain %s ...</info>', $challengeDomain));

            $authorizationChallenges = $client->requestAuthorization($challengeDomain);
            $authorizationChallenge = null;
            /** @var AuthorizationChallenge $candidate */
            foreach ($authorizationChallenges as $candidate) {
                if ($candidate->isValid()) {
                    $authorizationChallenge = $candidate;
                    break;
                }
            }
            if (null === $authorizationChallenge) {
                foreach ($authorizationChallenges as $candidate) {
                    if ($solver->supports($candidate)) {
                        $authorizationChallenge = $candidate;
                        break;
                    }
                }
            }

            if (null === $authorizationChallenge) {
                throw new ChallengeNotSupportedException();
            }

            $challengePerDomain[$challengeDomain] = $authorizationChallenge;

            $this->output->writeln('<info>The authorization token was successfully fetched!</info>');
            $solver->solve($authorizationChallenge);
        }

        $startTestTime = time();
        $this->output->writeln('<comment>Testing the challenges...</comment>');
        foreach ($challengePerDomain as $challengeDomain => $authorizationChallenge) {
            if ($authorizationChallenge->isValid()) {
                continue;
            }
            if (time() - $startTestTime > 30) {
                $this->output->writeln('<info>Spent to long to test everything. It should be ok now...</info>');
                break;
            }

            $this->output->writeln(sprintf('<info>Testing the challenge for domain %s ...</info>', $challengeDomain));
            if (!$validator->isValid($authorizationChallenge)) {
                $this->output->writeln(sprintf('<info>Can not valid challenge for domain %s ...</info>', $challengeDomain));
            }
        }

        $this->output->writeln('<comment>Requesting authorization checks...</comment>');
        foreach ($challengePerDomain as $challengeDomain => $authorizationChallenge) {
            if ($authorizationChallenge->isValid()) {
                $this->output->writeln(sprintf('<info>Authorization already validated for domain %s ...</info>', $challengeDomain));
                continue;
            }

            $this->output->writeln(sprintf('<info>Requesting authorization check for domain %s ...</info>', $challengeDomain));
            $client->challengeAuthorization($authorizationChallenge);
            $solver->cleanup($authorizationChallenge);
        }
    }

    private function getConfig($configFile)
    {
        return $this->resolveConfig(
            $this->loadConfig($configFile)
        );
    }

    private function loadConfig($configFile)
    {
        if (!file_exists($configFile)) {
            throw new IOException('Configuration file '.$configFile.' does not exists.');
        }

        if (!is_readable($configFile)) {
            throw new IOException('Configuration file '.$configFile.' is not readable.');
        }

        return Yaml::parse(file_get_contents($configFile));
    }

    private function resolveConfig($config)
    {
        $processor = new Processor();

        return $processor->processConfiguration(new DomainConfiguration(), ['acmephp' => $config]);
    }
}
