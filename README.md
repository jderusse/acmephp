Acme PHP
========

[![Join the chat at https://gitter.im/acmephp/acmephp](https://badges.gitter.im/acmephp/acmephp.svg)](https://gitter.im/acmephp/acmephp?utm_source=badge&utm_medium=badge&utm_campaign=pr-badge&utm_content=badge)

[![Build Status](https://img.shields.io/travis/acmephp/acmephp/master.svg?style=flat-square)](https://travis-ci.org/acmephp/acmephp)
[![Quality Score](https://img.shields.io/scrutinizer/g/acmephp/acmephp.svg?style=flat-square)](https://scrutinizer-ci.com/g/acmephp/acmephp)
[![StyleCI](https://styleci.io/repos/59910490/shield)](https://styleci.io/repos/59910490)
[![Packagist Version](https://img.shields.io/packagist/v/acmephp/acmephp.svg?style=flat-square)](https://packagist.org/packages/acmephp/acmephp)
[![Software License](https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square)](LICENSE)

> **Note:** This project is in beta but follow a strict BC policy, even in beta (see
> [the Backward Compatibility policy of Acme PHP](#backward-compatibility-policy) for more informations).

Acme PHP is a simple yet very extensible CLI client for Let's Encrypt that will help
you get and renew free HTTPS certificates.

Acme PHP is also an initiative to bring a robust, stable and powerful implementation
of the ACME protocol in PHP. Using the Acme PHP library and core components, you will be
able to deeply integrate the management of your certificates directly in your application
(for instance, renew your certificates from your web interface).

## Why should I use Acme PHP when I have an official client?

Acme PHP provides several major improvements over the default clients:
-   Acme PHP comes by nature as a single binary file: a single download and you are ready to start working ;
-   Acme PHP is based on a configuration file (`~/.acmephp/acmephp.conf`) instead command line arguments.
    Thus, the configuration is much more expressive and the same setup is used at every renewal ;
-   Acme PHP can monitor your CRONs and can send you alerts in many differents places:
    E-mail, Slack, HipChat, Flowdock, Fleep (thanks to [Monolog](https://github.com/Seldaek/monolog)!)
-   Acme PHP is very extensible it to create the certificate files structure you need for your webserver.
    It brings several default formatters to create classical file structures
    (nginx, nginx-proxy, haproxy, etc.) but you can very easily create our own if you need to ;
-   Acme PHP follows a strict BC policy preventing errors in your scripts or CRON even if you update it (see
    [the Backward Compatibility policy of Acme PHP](#backward-compatibility-policy) for more informations) ;

## Documentation

Read the official [Acme PHP documentation](https://acmephp.github.io).

## Backward Compatibility policy

Acme PHP follows a strict BC policy by sticking carefully to [semantic versioning](http://semver.org). This means 
your scripts, your CRON tasks and your code will keep working properly even when you update Acme PHP (either the CLI
tool or the library), as long as you keep the same major version (1.X.X, 2.X.X, etc.).

In addition of semantic versioning of stable versions for the CLI and the library, Acme PHP also follows
certain rules **for the CLI only**:
-   an alpha release can break BC with previous alpha releases of the same version
    (1.1.0-alpha2 can break BC with features introduced by 1.1.0-alpha1 but can't break BC with 1.0.0 features).
-   a beta release cannot break BC with previous beta releases
    (1.1.0-beta4 have to be BC with 1.1.0-beta3, 1.1.0-beta2, 1.1.0-beta1 and 1.0.0). New features can be added in beta
    as long as they don't break BC.

## Launch the Test suite

The Acme PHP test suite uses the Docker Boulder image to create an ACME server.
To launch the test suite, you need to setup the proper Docker environment for the suite.
Useful scripts are available under the `tests` directory: in the Acme PHP root directory,
execute the following:

```
# Create the Docker environment required for the suite
sudo tests/setup.sh

# Run the tests
tests/run.sh

# Clean the docker environment
tests/teardown.sh
```

**Note**: you may have boulder errors sometimes in tests. Simply ignore them and rerun the suite,
they are due to an issue in the container DNS.

**Warning**: as the acmephp/testing-ca Docker image needs to be mapped to the host network,
you may have ports conflicts. See [https://github.com/acmephp/testing-ca](https://github.com/acmephp/testing-ca)
for more informations.



## Auto command

The auto command is an all in one command who works with a `domain` 
config file like 


```
contact_email: contact@company

default_distinguished_name:
  country: FR
  locality: PARIS
  organization_name: MyCompany

solver: route53

domains:
  example.com:
    subject_alternative_names:
      - www.example.com
      - www1.example.com
      - www2.example.com
      - www3.example.com
      - www4.example.com
    install:
      - action: install_aws
        args:
          region: eu-west-1
          certificate_prefix: acme_php_
          load_balancer_name: letsencrypt
          load_balancer_port: 443

aws:
  access_key_id: YOUR_ACCESS_KEY
  secret_access_key: YOUR_SECRET_KEY
  default_region: eu-west-1
```


## Using container

A public docker container  is available

```
docker run --rm -v /var/lib/letsencrypt/data:/root/.acmephp -v /var/lib/letsencrypt/config.yml:/etc/acme.yml jderusse/acmephp auto /etc/acme.yml
```
