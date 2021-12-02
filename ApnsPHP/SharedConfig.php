<?php

/**
 * @file
 * SharedConfig class definition.
 *
 * LICENSE
 *
 * This source file is subject to the new BSD license that is bundled
 * with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://code.google.com/p/apns-php/wiki/License
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to aldo.armiento@gmail.com so we can send you a copy immediately.
 *
 * @author (C) 2010 Aldo Armiento (aldo.armiento@gmail.com)
 * @version $Id$
 */

/**
 * @mainpage
 *
 * @li ApnsPHP on GitHub: https://github.com/immobiliare/ApnsPHP
 */

namespace ApnsPHP;

use DateTimeImmutable;
use ApnsPHP\Log\EmbeddedLogger;
use Lcobucci\JWT\Signer\Key\InMemory;
use Psr\Log\LoggerInterface;
use Lcobucci\JWT\Signer\Ecdsa\Sha256;
use Lcobucci\JWT\Configuration;

/**
 * Abstract class: this is the superclass for all Apple Push Notification Service
 * classes.
 *
 * This class is responsible for the connection to the Apple Push Notification Service
 * and Feedback.
 *
 * @see http://tinyurl.com/ApplePushNotificationService
 */
abstract class SharedConfig
{
    /** @var int Production environment. */
    public const ENVIRONMENT_PRODUCTION = 0;

    /** @var int Sandbox environment. */
    public const ENVIRONMENT_SANDBOX = 1;

    /**
     * @var int Binary Provider API.
     * @deprecated
     */
    public const PROTOCOL_BINARY = 0;

    /** @var int APNs Provider API. */
    public const PROTOCOL_HTTP   = 1;

    /** @var int Device token length. */
    public const DEVICE_BINARY_SIZE = 32;

    /** @var int Default write interval in micro seconds. */
    public const WRITE_INTERVAL = 10000;

    /** @var int Default connect retry interval in micro seconds. */
    public const CONNECT_RETRY_INTERVAL = 1000000;

    /** @var int Default socket select timeout in micro seconds. */
    public const SOCKET_SELECT_TIMEOUT = 1000000;

    /**
     * @var string[] Container for service URLs environments.
     * @deprecated
     */
    protected $serviceURLs = [];

    /** @var string[] Container for HTTP/2 service URLs environments. */
    protected $HTTPServiceURLs = [];

    /** @var int Active environment. */
    protected $environment;

    /** @var int Active protocol. */
    protected $protocol;

    /** @var int Connect timeout in seconds. */
    protected $connectTimeout;

    /** @var int Connect retry times. */
    protected $connectRetryTimes = 3;

    /** @var string Provider certificate file with key (Bundled PEM). */
    protected $providerCertFile;

    /** @var string Provider certificate passphrase. */
    protected $providerCertPassphrase;

    /** @var string|null Provider Authentication token. */
    protected $providerToken;

    /** @var string|null Apple Team Identifier. */
    protected $providerTeamId;

    /** @var string|null Apple Key Identifier. */
    protected $providerKeyId;

    /** @var string Root certification authority file. */
    protected $rootCertAuthorityFile;

    /** @var int Write interval in micro seconds. */
    protected $writeInterval;

    /** @var int Connect retry interval in micro seconds. */
    protected $connectRetryInterval;

    /** @var int Socket select timeout in micro seconds. */
    protected $socketSelectTimeout;

    /** @var \Psr\Log\LoggerInterface Logger. */
    protected $logger;

    /** @var \CurlHandle|resource|false SSL Socket. */
    protected $hSocket;

    /**
     * @param int $environment Environment.
     * @param string $providerCertificateFile Provider certificate file
     *         with key (Bundled PEM).
     * @param int $protocol Protocol.
     */
    public function __construct($environment, $providerCertificateFile, $protocol = self::PROTOCOL_BINARY)
    {
        if ($environment != self::ENVIRONMENT_PRODUCTION && $environment != self::ENVIRONMENT_SANDBOX) {
            throw new Exception(
                "Invalid environment '{$environment}'"
            );
        }
        $this->environment = $environment;

        if (!is_readable($providerCertificateFile)) {
            throw new Exception(
                "Unable to read certificate file '{$providerCertificateFile}'"
            );
        }
        $this->providerCertFile = $providerCertificateFile;

        if ($protocol != self::PROTOCOL_BINARY && $protocol != self::PROTOCOL_HTTP) {
            throw new Exception(
                "Invalid protocol '{$protocol}'"
            );
        }
        $this->protocol = $protocol;

        $this->connectTimeout = ini_get("default_socket_timeout");
        $this->writeInterval = self::WRITE_INTERVAL;
        $this->connectRetryInterval = self::CONNECT_RETRY_INTERVAL;
        $this->socketSelectTimeout = self::SOCKET_SELECT_TIMEOUT;
    }

    /**
     * Set the Logger instance to use for logging purpose.
     *
     * The default logger is EmbeddedLogger, an instance
     * of LoggerInterface that simply print to standard
     * output log messages.
     *
     * To set a custom logger you have to implement LoggerInterface
     * and use setLogger, otherwise standard logger will be used.
     *
     * @param LoggerInterface $logger Logger instance.
     * @see \Psr\Log\LoggerInterface
     * @see EmbeddedLogger
     *
     */
    public function setLogger(LoggerInterface $logger)
    {
        if (!is_object($logger)) {
            throw new Exception(
                "The logger should be an instance of 'Psr\Log\LoggerInterface'"
            );
        }
        if (!($logger instanceof LoggerInterface)) {
            throw new Exception(
                "Unable to use an instance of '" . get_class($logger) . "' as logger: " .
                "a logger must implements 'Psr\Log\LoggerInterface'."
            );
        }
        $this->logger = $logger;
    }

    /**
     * Get the Logger instance.
     *
     * @return @type \Psr\Log\LoggerInterface Current Logger instance.
     */
    public function getLogger()
    {
        return $this->logger;
    }

    /**
     * Set the Provider Certificate passphrase.
     *
     * @param string $providerCertPassphrase Provider Certificate passphrase.
     */
    public function setProviderCertificatePassphrase($providerCertPassphrase)
    {
        $this->providerCertPassphrase = $providerCertPassphrase;
    }

    /**
     * Set the Team Identifier.
     *
     * @param string $teamId Apple Team Identifier.
     */
    public function setTeamId($teamId)
    {
        $this->providerTeamId = $teamId;
    }

    /**
     * Set the Key Identifier.
     *
     * @param string $keyId Apple Key Identifier.
     */
    public function setKeyId($keyId)
    {
        $this->providerKeyId = $keyId;
    }

    /**
     * Set the Root Certification Authority file.
     *
     * Setting the Root Certification Authority file automatically set peer verification
     * on connect.
     *
     * @see http://tinyurl.com/GeneralProviderRequirements
     * @see http://www.entrust.net/
     * @see https://www.entrust.net/downloads/root_index.cfm
     *
     * @param string $rootCertificationAuthorityFile Root Certification
     *         Authority file.
     */
    public function setRootCertificationAuthority($rootCertificationAuthorityFile)
    {
        if (!is_readable($rootCertificationAuthorityFile)) {
            throw new Exception(
                "Unable to read Certificate Authority file '{$rootCertificationAuthorityFile}'"
            );
        }
        $this->rootCertAuthorityFile = $rootCertificationAuthorityFile;
    }

    /**
     * Get the Root Certification Authority file path.
     *
     * @return string Current Root Certification Authority file path.
     */
    public function getCertificateAuthority()
    {
        return $this->rootCertAuthorityFile;
    }

    /**
     * Set the write interval.
     *
     * After each socket write operation we are sleeping for this
     * time interval. To speed up the sending operations, use Zero
     * as parameter but some messages may be lost.
     *
     * @param int $writeInterval Write interval in micro seconds.
     */
    public function setWriteInterval($writeInterval)
    {
        $this->writeInterval = (int)$writeInterval;
    }

    /**
     * Get the write interval.
     *
     * @return int Write interval in micro seconds.
     */
    public function getWriteInterval()
    {
        return $this->writeInterval;
    }

    /**
     * Set the connection timeout.
     *
     * The default connection timeout is the PHP internal value "default_socket_timeout".
     * @see http://php.net/manual/en/filesystem.configuration.php
     *
     * @param int $timeout Connection timeout in seconds.
     */
    public function setConnectTimeout($timeout)
    {
        $this->connectTimeout = (int)$timeout;
    }

    /**
     * Get the connection timeout.
     *
     * @return int Connection timeout in seconds.
     */
    public function getConnectTimeout()
    {
        return $this->connectTimeout;
    }

    /**
     * Set the connect retry times value.
     *
     * If the client is unable to connect to the server retries at least for this
     * value. The default connect retry times is 3.
     *
     * @param int $retryTimes Connect retry times.
     */
    public function setConnectRetryTimes($retryTimes)
    {
        $this->connectRetryTimes = (int)$retryTimes;
    }

    /**
     * Get the connect retry time value.
     *
     * @return int Connect retry times.
     */
    public function getConnectRetryTimes()
    {
        return $this->connectRetryTimes;
    }

    /**
     * Set the connect retry interval.
     *
     * If the client is unable to connect to the server retries at least for ConnectRetryTimes
     * and waits for this value between each attempts.
     *
     * @param int $retryInterval Connect retry interval in micro seconds.
     *@see setConnectRetryTimes
     *
     */
    public function setConnectRetryInterval($retryInterval)
    {
        $this->connectRetryInterval = (int)$retryInterval;
    }

    /**
     * Get the connect retry interval.
     *
     * @return int Connect retry interval in micro seconds.
     */
    public function getConnectRetryInterval()
    {
        return $this->connectRetryInterval;
    }

    /**
     * Set the TCP socket select timeout.
     *
     * After writing to socket waits for at least this value for read stream to
     * change status.
     *
     * In Apple Push Notification protocol there isn't a real-time
     * feedback about the correctness of notifications pushed to the server; so after
     * each write to server waits at least SocketSelectTimeout. If, during this
     * time, the read stream change its status and socket received an end-of-file
     * from the server the notification pushed to server was broken, the server
     * has closed the connection and the client needs to reconnect.
     *
     * @see http://php.net/stream_select
     *
     * @param int $selectTimeout Socket select timeout in micro seconds.
     */
    public function setSocketSelectTimeout($selectTimeout)
    {
        $this->socketSelectTimeout = (int)$selectTimeout;
    }

    /**
     * Get the TCP socket select timeout.
     *
     * @return int Socket select timeout in micro seconds.
     */
    public function getSocketSelectTimeout()
    {
        return $this->socketSelectTimeout;
    }

    /**
     * Connects to Apple Push Notification service server.
     *
     * Retries ConnectRetryTimes if unable to connect and waits setConnectRetryInterval
     * between each attempts.
     *
     * @see setConnectRetryInterval
     * @see setConnectRetryTimes
     */
    public function connect()
    {
        $connected = false;
        $retry = 0;
        while (!$connected) {
            try {
                $connected = $this->protocol === self::PROTOCOL_HTTP ?
                    $this->httpInit() : $this->binaryConnect($this->serviceURLs[$this->environment]);
            } catch (Exception $e) {
                $this->logger()->error($e->getMessage());
                if ($retry >= $this->connectRetryTimes) {
                    throw $e;
                } else {
                    $this->logger()->info(
                        "Retry to connect (" . ($retry + 1) .
                        "/{$this->connectRetryTimes})..."
                    );
                    usleep($this->connectRetryInterval);
                }
            }
            $retry++;
        }
    }

    /**
     * Disconnects from Apple Push Notifications service server.
     *
     * @return boolean True if successful disconnected.
     */
    public function disconnect()
    {
        if ($this->hSocket !== false || is_resource($this->hSocket)) {
            $this->logger()->info('Disconnected.');
            if ($this->protocol === self::PROTOCOL_HTTP) {
                curl_close($this->hSocket);
                unset($this->hSocket); // curl_close($handle) has not effect with PHP 8
                return true;
            } else {
                return fclose($this->hSocket);
            }
        }

        return false;
    }

    /**
     * Initializes cURL, the HTTP/2 backend used to connect to Apple Push Notification
     * service server via HTTP/2 API protocol.
     *
     * @return boolean True if successful initialized.
     */
    protected function httpInit()
    {
        $this->logger()->info("Trying to initialize HTTP/2 backend...");

        $this->hSocket = curl_init();
        if (!$this->hSocket) {
            throw new Exception("Unable to initialize HTTP/2 backend.");
        }

        if (!defined('CURL_HTTP_VERSION_2_0')) {
            define('CURL_HTTP_VERSION_2_0', 3);
        }
        $curlOpts = [
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_2_0,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERAGENT => 'ApnsPHP',
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_VERBOSE => false
        ];

        if (strpos($this->providerCertFile, '.pem') !== false) {
            $this->logger()->info("Initializing HTTP/2 backend with certificate.");
            $curlOpts[CURLOPT_SSLCERT] = $this->providerCertFile;
            $curlOpts[CURLOPT_SSLCERTPASSWD] = empty($this->providerCertPassphrase) ?
                null : $this->providerCertPassphrase;
        }

        if (strpos($this->providerCertFile, '.p8') !== false) {
            $this->logger()->info("Initializing HTTP/2 backend with key.");
            $this->providerToken = $this->getJsonWebToken();
        }

        if (!curl_setopt_array($this->hSocket, $curlOpts)) {
            throw new Exception("Unable to initialize HTTP/2 backend.");
        }

        $this->logger()->info("Initialized HTTP/2 backend.");

        return true;
    }

    /**
     * @return string
     */
    protected function getJsonWebToken()
    {
        $key = InMemory::file($this->providerCertFile);

        return Configuration::forUnsecuredSigner()->builder()
            ->issuedBy($this->providerTeamId)
            ->issuedAt(new DateTimeImmutable())
            ->withHeader('kid', $this->providerKeyId)
            ->getToken(Sha256::create(), $key)
            ->toString();
    }

    /**
     * Connects to Apple Push Notification service server via binary protocol.
     *
     * @return boolean True if successful connected.
     * @deprecated
     */
    protected function binaryConnect($URL)
    {
        $this->logger()->info("Trying {$URL}...");
        $URL = $this->serviceURLs[$this->environment];

        $this->logger()->info("Trying {$URL}...");

        /**
         * @see http://php.net/manual/en/context.ssl.php
         */
        $streamContext = stream_context_create(['ssl' => [
            'verify_peer' => isset($this->rootCertAuthorityFile),
            'cafile' => $this->rootCertAuthorityFile,
            'local_cert' => $this->providerCertFile
        ]]);

        if (!empty($this->providerCertPassphrase)) {
            stream_context_set_option(
                $streamContext,
                'ssl',
                'passphrase',
                $this->providerCertPassphrase
            );
        }

        $this->hSocket = @stream_socket_client(
            $URL,
            $errorCode,
            $errorMessage,
            $this->connectTimeout,
            STREAM_CLIENT_CONNECT,
            $streamContext
        );

        if (!$this->hSocket) {
            throw new Exception(
                "Unable to connect to '{$URL}': {$errorMessage} ({$errorCode})"
            );
        }

        stream_set_blocking($this->hSocket, 0);
        stream_set_write_buffer($this->hSocket, 0);

        $this->logger()->info("Connected to {$URL}.");

        return true;
    }

    /**
     * Return the Logger (with lazy loading)
     *
     * @return LoggerInterface
     */
    protected function logger()
    {
        if (!isset($this->logger)) {
            $this->logger = new EmbeddedLogger();
        }

        return $this->logger;
    }
}
