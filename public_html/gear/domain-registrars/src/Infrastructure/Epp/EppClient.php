<?php

declare(strict_types=1);

namespace App\Infrastructure\Epp;

use DOMDocument;
use DOMElement;
use DOMXPath;

final class EppClient
{
    /** @var resource|null */
    private $stream = null;

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $username,
        private readonly string $password,
        private readonly string $clientId,
        private readonly ?string $certificatePath,
        private readonly ?string $certificatePassphrase,
        private readonly ?string $caFile,
        private readonly bool $verifyPeer,
        private readonly int $timeoutSeconds,
        private readonly array $objectUris,
        private readonly array $extensionUris = [],
    ) {
    }

    /**
     * @return array{ok: bool, greeting: array<string, mixed>}
     */
    public function hello(): array
    {
        $this->connect();
        $greeting = $this->readDocument();
        $this->sendDocument($this->buildHelloDocument());
        $response = $this->readDocument();
        $this->disconnect();

        return [
            'ok' => true,
            'greeting' => $this->parseGreeting($greeting),
            'response' => $this->documentToXml($response),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function domainInfo(string $domainName): array
    {
        return $this->withAuthenticatedSession(function () use ($domainName): array {
            $response = $this->sendAuthenticatedCommand($this->buildDomainInfoDocument($domainName));

            return $this->parseDomainInfoResponse($response);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function checkDomain(string $domainName): array
    {
        return $this->withAuthenticatedSession(function () use ($domainName): array {
            $response = $this->sendAuthenticatedCommand($this->buildDomainCheckDocument($domainName));

            return $this->parseDomainCheckResponse($response);
        });
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function createDomain(array $payload): array
    {
        return $this->withAuthenticatedSession(function () use ($payload): array {
            $response = $this->sendAuthenticatedCommand($this->buildDomainCreateDocument($payload));

            return $this->parseGenericResponse($response);
        });
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function renewDomain(string $domainName, int $periodYears, array $options = []): array
    {
        return $this->withAuthenticatedSession(function () use ($domainName, $periodYears, $options): array {
            $response = $this->sendAuthenticatedCommand(
                $this->buildDomainRenewDocument($domainName, $periodYears, $options),
            );

            return $this->parseGenericResponse($response);
        });
    }

    /**
     * @param list<array{hostname: string, ipv4?: string|null, ipv6?: string|null}> $nameservers
     * @return array<string, mixed>
     */
    public function updateNameservers(string $domainName, array $nameservers): array
    {
        return $this->withAuthenticatedSession(function () use ($domainName, $nameservers): array {
            $current = $this->domainInfoInSession($domainName);
            $response = $this->sendAuthenticatedCommand(
                $this->buildDomainUpdateNameserversDocument($domainName, $current['nameservers'], $nameservers),
            );

            return $this->parseGenericResponse($response);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function domainInfoInSession(string $domainName): array
    {
        $response = $this->sendAuthenticatedCommand($this->buildDomainInfoDocument($domainName));

        return $this->parseDomainInfoResponse($response);
    }

    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    private function withAuthenticatedSession(callable $callback)
    {
        $this->connect();
        $this->readDocument();
        $this->login();

        try {
            return $callback();
        } finally {
            $this->logout();
            $this->disconnect();
        }
    }

    private function connect(): void
    {
        if (is_resource($this->stream)) {
            return;
        }

        $contextOptions = [
            'ssl' => [
                'verify_peer' => $this->verifyPeer,
                'verify_peer_name' => $this->verifyPeer,
                'allow_self_signed' => ! $this->verifyPeer,
                'SNI_enabled' => true,
                'peer_name' => $this->host,
            ],
        ];

        if ($this->certificatePath !== null) {
            $contextOptions['ssl']['local_cert'] = $this->certificatePath;
        }

        if ($this->certificatePassphrase !== null) {
            $contextOptions['ssl']['passphrase'] = $this->certificatePassphrase;
        }

        if ($this->caFile !== null) {
            $contextOptions['ssl']['cafile'] = $this->caFile;
        }

        $context = stream_context_create($contextOptions);
        $address = sprintf('tls://%s:%d', $this->host, $this->port);
        $errorCode = 0;
        $errorMessage = '';

        // #region debug-point A:connect-inputs
        error_log('[DEBUG][coza-epp-connect][A] ' . json_encode([
            'address' => $address,
            'verify_peer' => $this->verifyPeer,
            'timeout_seconds' => $this->timeoutSeconds,
            'cert_path' => $this->certificatePath,
            'cert_exists' => $this->certificatePath !== null ? is_file($this->certificatePath) : null,
            'cert_readable' => $this->certificatePath !== null ? is_readable($this->certificatePath) : null,
            'cert_perms' => $this->certificatePath !== null && is_file($this->certificatePath) ? substr(sprintf('%o', fileperms($this->certificatePath)), -4) : null,
            'ca_file' => $this->caFile,
            'ca_exists' => $this->caFile !== null ? is_file($this->caFile) : null,
            'ca_readable' => $this->caFile !== null ? is_readable($this->caFile) : null,
            'user' => function_exists('get_current_user') ? get_current_user() : null,
            'uid' => function_exists('getmyuid') ? getmyuid() : null,
            'euid' => function_exists('posix_geteuid') ? @posix_geteuid() : null,
        ], JSON_UNESCAPED_SLASHES));
        // #endregion

        $stream = @stream_socket_client(
            $address,
            $errorCode,
            $errorMessage,
            $this->timeoutSeconds,
            STREAM_CLIENT_CONNECT,
            $context,
        );

        if (! is_resource($stream)) {
            // #region debug-point B:connect-failure
            $opensslErrors = [];
            while (($opensslError = openssl_error_string()) !== false) {
                $opensslErrors[] = $opensslError;
            }
            $lastError = error_get_last();
            error_log('[DEBUG][coza-epp-connect][B] ' . json_encode([
                'address' => $address,
                'error_code' => $errorCode,
                'error_message' => $errorMessage,
                'last_error' => is_array($lastError) ? $lastError : null,
                'openssl_errors' => $opensslErrors,
            ], JSON_UNESCAPED_SLASHES));
            // #endregion
            throw new EppException(sprintf('Unable to connect to %s: %s', $address, $errorMessage ?: 'unknown error'));
        }

        stream_set_timeout($stream, $this->timeoutSeconds);
        $this->stream = $stream;

        // #region debug-point C:connect-success
        error_log('[DEBUG][coza-epp-connect][C] ' . json_encode([
            'address' => $address,
            'meta' => stream_get_meta_data($stream),
        ], JSON_UNESCAPED_SLASHES));
        // #endregion
    }

    private function disconnect(): void
    {
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }

        $this->stream = null;
    }

    private function login(): void
    {
        $response = $this->sendAuthenticatedCommand($this->buildLoginDocument(), false);
        $result = $this->parseGenericResponse($response);

        if (! $result['ok']) {
            throw new EppException('EPP login failed: ' . $result['message']);
        }
    }

    private function logout(): void
    {
        if (! is_resource($this->stream)) {
            return;
        }

        $this->sendDocument($this->buildLogoutDocument());
        $this->readDocument();
    }

    private function sendAuthenticatedCommand(DOMDocument $document, bool $prependLogin = true): DOMDocument
    {
        unset($prependLogin);

        $this->sendDocument($document);

        return $this->readDocument();
    }

    private function sendDocument(DOMDocument $document): void
    {
        if (! is_resource($this->stream)) {
            throw new EppException('EPP socket is not connected.');
        }

        $xml = $document->saveXML();
        if ($xml === false) {
            throw new EppException('Unable to serialize EPP XML.');
        }

        $frame = pack('N', strlen($xml) + 4) . $xml;
        $written = 0;
        $length = strlen($frame);

        while ($written < $length) {
            $result = fwrite($this->stream, substr($frame, $written));

            if ($result === false || $result === 0) {
                throw new EppException('Failed to write EPP frame.');
            }

            $written += $result;
        }
    }

    private function readDocument(): DOMDocument
    {
        if (! is_resource($this->stream)) {
            throw new EppException('EPP socket is not connected.');
        }

        $header = $this->readBytes(4);
        $length = unpack('Nlength', $header);

        if (! is_array($length) || ! isset($length['length'])) {
            throw new EppException('Invalid EPP frame header.');
        }

        $payloadLength = $length['length'] - 4;
        $xml = $this->readBytes($payloadLength);

        $document = new DOMDocument('1.0', 'UTF-8');
        $document->preserveWhiteSpace = false;

        if (! $document->loadXML($xml)) {
            throw new EppException('Unable to parse EPP XML payload.');
        }

        return $document;
    }

    private function readBytes(int $length): string
    {
        $buffer = '';

        while (strlen($buffer) < $length) {
            $chunk = fread($this->stream, $length - strlen($buffer));

            if ($chunk === false || $chunk === '') {
                throw new EppException('Failed to read EPP frame from socket.');
            }

            $buffer .= $chunk;
        }

        return $buffer;
    }

    private function buildHelloDocument(): DOMDocument
    {
        $document = $this->newBaseDocument();
        $document->documentElement?->appendChild($document->createElement('hello'));

        return $document;
    }

    private function buildLoginDocument(): DOMDocument
    {
        $document = $this->newBaseDocument();
        $command = $document->createElement('command');
        $login = $document->createElement('login');
        $login->appendChild($document->createElement('clID', $this->clientId));
        $login->appendChild($document->createElement('pw', $this->password));

        $options = $document->createElement('options');
        $options->appendChild($document->createElement('version', '1.0'));
        $options->appendChild($document->createElement('lang', 'en'));
        $login->appendChild($options);

        $services = $document->createElement('svcs');
        foreach ($this->objectUris as $objectUri) {
            $services->appendChild($document->createElement('objURI', $objectUri));
        }

        if ($this->extensionUris !== []) {
            $serviceExtension = $document->createElement('svcExtension');
            foreach ($this->extensionUris as $extensionUri) {
                $serviceExtension->appendChild($document->createElement('extURI', $extensionUri));
            }
            $services->appendChild($serviceExtension);
        }

        $login->appendChild($services);
        $command->appendChild($login);
        $command->appendChild($this->createClientTransactionId($document));
        $document->documentElement?->appendChild($command);

        return $document;
    }

    private function buildLogoutDocument(): DOMDocument
    {
        $document = $this->newBaseDocument();
        $command = $document->createElement('command');
        $command->appendChild($document->createElement('logout'));
        $command->appendChild($this->createClientTransactionId($document));
        $document->documentElement?->appendChild($command);

        return $document;
    }

    private function buildDomainInfoDocument(string $domainName): DOMDocument
    {
        $document = $this->newBaseDocument();
        $command = $document->createElement('command');
        $info = $document->createElement('info');
        $domainInfo = $document->createElementNS('urn:ietf:params:xml:ns:domain-1.0', 'domain:info');
        $domainInfo->appendChild($document->createElementNS('urn:ietf:params:xml:ns:domain-1.0', 'domain:name', $domainName));
        $info->appendChild($domainInfo);
        $command->appendChild($info);
        $command->appendChild($this->createClientTransactionId($document));
        $document->documentElement?->appendChild($command);

        return $document;
    }

    private function buildDomainCheckDocument(string $domainName): DOMDocument
    {
        $document = $this->newBaseDocument();
        $command = $document->createElement('command');
        $check = $document->createElement('check');
        $domainCheck = $document->createElementNS('urn:ietf:params:xml:ns:domain-1.0', 'domain:check');
        $domainCheck->appendChild($document->createElementNS('urn:ietf:params:xml:ns:domain-1.0', 'domain:name', $domainName));
        $check->appendChild($domainCheck);
        $command->appendChild($check);
        $command->appendChild($this->createClientTransactionId($document));
        $document->documentElement?->appendChild($command);

        return $document;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function buildDomainCreateDocument(array $payload): DOMDocument
    {
        $domainName = (string) ($payload['domain_name'] ?? '');
        $registrant = (string) ($payload['registrant'] ?? '');
        $periodYears = (int) ($payload['period_years'] ?? 1);
        $authInfo = (string) ($payload['auth_info'] ?? '');
        $nameservers = $payload['nameservers'] ?? [];
        $contacts = $payload['contacts'] ?? [];

        if ($domainName === '' || $registrant === '' || $authInfo === '') {
            throw new EppException('Domain create requires domain_name, registrant, and auth_info.');
        }

        $document = $this->newBaseDocument();
        $command = $document->createElement('command');
        $create = $document->createElement('create');
        $domainCreate = $document->createElementNS('urn:ietf:params:xml:ns:domain-1.0', 'domain:create');
        $domainCreate->appendChild($document->createElementNS('urn:ietf:params:xml:ns:domain-1.0', 'domain:name', $domainName));

        $period = $document->createElementNS('urn:ietf:params:xml:ns:domain-1.0', 'domain:period', (string) $periodYears);
        $period->setAttribute('unit', 'y');
        $domainCreate->appendChild($period);

        if (is_array($nameservers) && $nameservers !== []) {
            $ns = $document->createElementNS('urn:ietf:params:xml:ns:domain-1.0', 'domain:ns');
            foreach ($nameservers as $nameserver) {
                $hostname = is_array($nameserver) ? (string) ($nameserver['hostname'] ?? '') : (string) $nameserver;
                if ($hostname === '') {
                    continue;
                }
                $ns->appendChild($document->createElementNS('urn:ietf:params:xml:ns:domain-1.0', 'domain:hostObj', $hostname));
            }
            $domainCreate->appendChild($ns);
        }

        $domainCreate->appendChild($document->createElementNS('urn:ietf:params:xml:ns:domain-1.0', 'domain:registrant', $registrant));

        foreach ($contacts as $type => $contactId) {
            if (! is_string($type) || ! is_scalar($contactId)) {
                continue;
            }

            $contact = $document->createElementNS('urn:ietf:params:xml:ns:domain-1.0', 'domain:contact', (string) $contactId);
            $contact->setAttribute('type', $type);
            $domainCreate->appendChild($contact);
        }

        $authInfoElement = $document->createElementNS('urn:ietf:params:xml:ns:domain-1.0', 'domain:authInfo');
        $authInfoElement->appendChild($document->createElementNS('urn:ietf:params:xml:ns:domain-1.0', 'domain:pw', $authInfo));
        $domainCreate->appendChild($authInfoElement);

        $create->appendChild($domainCreate);
        $command->appendChild($create);
        $command->appendChild($this->createClientTransactionId($document));
        $document->documentElement?->appendChild($command);

        return $document;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function buildDomainRenewDocument(string $domainName, int $periodYears, array $options): DOMDocument
    {
        $currentExpiryDate = (string) ($options['current_expiry_date'] ?? '');
        if ($currentExpiryDate === '') {
            throw new EppException('Domain renew requires current_expiry_date.');
        }

        $document = $this->newBaseDocument();
        $command = $document->createElement('command');
        $renew = $document->createElement('renew');
        $domainRenew = $document->createElementNS('urn:ietf:params:xml:ns:domain-1.0', 'domain:renew');
        $domainRenew->appendChild($document->createElementNS('urn:ietf:params:xml:ns:domain-1.0', 'domain:name', $domainName));
        $domainRenew->appendChild($document->createElementNS('urn:ietf:params:xml:ns:domain-1.0', 'domain:curExpDate', $currentExpiryDate));
        $period = $document->createElementNS('urn:ietf:params:xml:ns:domain-1.0', 'domain:period', (string) $periodYears);
        $period->setAttribute('unit', 'y');
        $domainRenew->appendChild($period);
        $renew->appendChild($domainRenew);
        $command->appendChild($renew);
        $command->appendChild($this->createClientTransactionId($document));
        $document->documentElement?->appendChild($command);

        return $document;
    }

    /**
     * @param list<string> $currentNameservers
     * @param list<array{hostname: string, ipv4?: string|null, ipv6?: string|null}> $targetNameservers
     */
    private function buildDomainUpdateNameserversDocument(
        string $domainName,
        array $currentNameservers,
        array $targetNameservers,
    ): DOMDocument {
        $targetHostnames = array_values(array_filter(array_map(
            static fn (array $item): string => (string) ($item['hostname'] ?? ''),
            $targetNameservers,
        )));

        $toAdd = array_values(array_diff($targetHostnames, $currentNameservers));
        $toRemove = array_values(array_diff($currentNameservers, $targetHostnames));

        $document = $this->newBaseDocument();
        $command = $document->createElement('command');
        $update = $document->createElement('update');
        $domainUpdate = $document->createElementNS('urn:ietf:params:xml:ns:domain-1.0', 'domain:update');
        $domainUpdate->appendChild($document->createElementNS('urn:ietf:params:xml:ns:domain-1.0', 'domain:name', $domainName));

        if ($toAdd !== []) {
            $add = $document->createElementNS('urn:ietf:params:xml:ns:domain-1.0', 'domain:add');
            $ns = $document->createElementNS('urn:ietf:params:xml:ns:domain-1.0', 'domain:ns');
            foreach ($toAdd as $hostname) {
                $ns->appendChild($document->createElementNS('urn:ietf:params:xml:ns:domain-1.0', 'domain:hostObj', $hostname));
            }
            $add->appendChild($ns);
            $domainUpdate->appendChild($add);
        }

        if ($toRemove !== []) {
            $remove = $document->createElementNS('urn:ietf:params:xml:ns:domain-1.0', 'domain:rem');
            $ns = $document->createElementNS('urn:ietf:params:xml:ns:domain-1.0', 'domain:ns');
            foreach ($toRemove as $hostname) {
                $ns->appendChild($document->createElementNS('urn:ietf:params:xml:ns:domain-1.0', 'domain:hostObj', $hostname));
            }
            $remove->appendChild($ns);
            $domainUpdate->appendChild($remove);
        }

        $update->appendChild($domainUpdate);
        $command->appendChild($update);
        $command->appendChild($this->createClientTransactionId($document));
        $document->documentElement?->appendChild($command);

        return $document;
    }

    private function createClientTransactionId(DOMDocument $document): DOMElement
    {
        return $document->createElement('clTRID', sprintf('app-%s', bin2hex(random_bytes(8))));
    }

    private function newBaseDocument(): DOMDocument
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $document->formatOutput = false;
        $root = $document->createElementNS('urn:ietf:params:xml:ns:epp-1.0', 'epp');
        $document->appendChild($root);

        return $document;
    }

    /**
     * @return array<string, mixed>
     */
    private function parseGreeting(DOMDocument $document): array
    {
        $xpath = $this->xpath($document);

        return [
            'server_id' => $this->xpathValue($xpath, '/epp:epp/epp:greeting/epp:svID'),
            'server_date' => $this->xpathValue($xpath, '/epp:epp/epp:greeting/epp:svDate'),
        ];
    }

    /**
     * @return array{ok: bool, code: int, message: string, raw_xml: string}
     */
    private function parseGenericResponse(DOMDocument $document): array
    {
        $xpath = $this->xpath($document);
        $result = $xpath->query('/epp:epp/epp:response/epp:result')->item(0);
        $code = $result instanceof DOMElement ? (int) $result->getAttribute('code') : 0;
        $message = $this->xpathValue($xpath, '/epp:epp/epp:response/epp:result/epp:msg') ?? 'Unknown EPP response';

        return [
            'ok' => $code >= 1000 && $code < 2000,
            'code' => $code,
            'message' => $message,
            'raw_xml' => $this->documentToXml($document),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function parseDomainInfoResponse(DOMDocument $document): array
    {
        $generic = $this->parseGenericResponse($document);
        if (! $generic['ok']) {
            return $generic;
        }

        $xpath = $this->xpath($document);
        $nameserverNodes = $xpath->query('/epp:epp/epp:response/epp:resData/domain:infData/domain:ns/domain:hostObj');
        $statusNodes = $xpath->query('/epp:epp/epp:response/epp:resData/domain:infData/domain:status');

        $nameservers = [];
        if ($nameserverNodes !== false) {
            foreach ($nameserverNodes as $node) {
                $nameservers[] = trim($node->textContent);
            }
        }

        $statuses = [];
        if ($statusNodes !== false) {
            foreach ($statusNodes as $node) {
                if (! $node instanceof DOMElement) {
                    continue;
                }
                $statuses[] = [
                    'code' => $node->getAttribute('s'),
                    'label' => $node->getAttribute('lang') ?: null,
                ];
            }
        }

        return $generic + [
            'domain_name' => $this->xpathValue($xpath, '/epp:epp/epp:response/epp:resData/domain:infData/domain:name'),
            'roid' => $this->xpathValue($xpath, '/epp:epp/epp:response/epp:resData/domain:infData/domain:roid'),
            'registrant' => $this->xpathValue($xpath, '/epp:epp/epp:response/epp:resData/domain:infData/domain:registrant'),
            'client_id' => $this->xpathValue($xpath, '/epp:epp/epp:response/epp:resData/domain:infData/domain:clID'),
            'created_at' => $this->xpathValue($xpath, '/epp:epp/epp:response/epp:resData/domain:infData/domain:crDate'),
            'expires_at' => $this->xpathValue($xpath, '/epp:epp/epp:response/epp:resData/domain:infData/domain:exDate'),
            'nameservers' => $nameservers,
            'statuses' => $statuses,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function parseDomainCheckResponse(DOMDocument $document): array
    {
        $generic = $this->parseGenericResponse($document);
        if (! $generic['ok']) {
            return $generic;
        }

        $xpath = $this->xpath($document);
        $checkNode = $xpath->query('/epp:epp/epp:response/epp:resData/domain:chkData/domain:cd/domain:name')->item(0);

        $available = false;
        $domainName = null;
        if ($checkNode instanceof DOMElement) {
            $available = $checkNode->getAttribute('avail') === '1';
            $domainName = trim($checkNode->textContent) ?: null;
        }

        return $generic + [
            'domain_name' => $domainName,
            'available' => $available,
            'reason' => $this->xpathValue($xpath, '/epp:epp/epp:response/epp:resData/domain:chkData/domain:cd/domain:reason'),
        ];
    }

    private function xpath(DOMDocument $document): DOMXPath
    {
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('epp', 'urn:ietf:params:xml:ns:epp-1.0');
        $xpath->registerNamespace('domain', 'urn:ietf:params:xml:ns:domain-1.0');

        return $xpath;
    }

    private function xpathValue(DOMXPath $xpath, string $expression): ?string
    {
        $value = $xpath->evaluate(sprintf('string(%s)', $expression));
        $value = is_string($value) ? trim($value) : '';

        return $value === '' ? null : $value;
    }

    private function documentToXml(DOMDocument $document): string
    {
        return $document->saveXML() ?: '';
    }
}
