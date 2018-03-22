<?php

/*
 * This file is part of the Acme PHP Client project.
 *
 * (c) Titouan Galopin <galopintitouan@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\AcmePhp\Cli\Repository;

use AcmePhp\Cli\Repository\Repository;

class RepositoryWithBackupTest extends AbstractRepositoryTest
{
    protected function createRepository()
    {
        return new Repository($this->serializer, $this->master, $this->backup, true);
    }

    public function testStoreAccountKeyPair()
    {
        parent::testStoreAccountKeyPair();

        $this->assertSame("public\n", $this->backup->read('private/_account/public.pem'));
        $this->assertSame("private\n", $this->backup->read('private/_account/private.pem'));
    }

    public function testStoreDomainKeyPair()
    {
        parent::testStoreDomainKeyPair();

        $this->assertSame("public\n", $this->backup->read('private/example.com/public.pem'));
        $this->assertSame("private\n", $this->backup->read('private/example.com/private.pem'));
    }

    public function testStoreDomainDistinguishedName()
    {
        parent::testStoreDomainDistinguishedName();

        $this->assertJson($this->backup->read('private/example.com/distinguished_name.json'));
    }

    public function testStoreDomainCertificate()
    {
        parent::testStoreDomainCertificate();

        $this->assertSame(self::$certPem."\n", $this->backup->read('certs/example.com/cert.pem'));
        $this->assertSame(self::$issuerCertPem."\n", $this->backup->read('certs/example.com/chain.pem'));
        $this->assertSame(self::$certPem."\n".self::$issuerCertPem."\n", $this->backup->read('certs/example.com/fullchain.pem'));
        $this->assertSame(self::$certPem."\n".self::$issuerCertPem."\nprivate\n", $this->backup->read('certs/example.com/combined.pem'));
    }
}
