<?php

declare(strict_types=1);

// behavior-test-gate: static-rule

namespace plugin\sandadmin\exception {
    if (!class_exists(ApiException::class)) {
        final class ApiException extends \RuntimeException
        {
        }
    }
}

namespace {
    use plugin\SandIam\app\federation\OneLoginSamlAssertionVerifier;
    use plugin\sandadmin\exception\ApiException;
    use RobRichards\XMLSecLibs\XMLSecurityDSig;
    use RobRichards\XMLSecLibs\XMLSecurityKey;

    $package = dirname(__DIR__);
    $autoload = $package . '/vendor/autoload.php';
    if (!is_file($autoload) || !class_exists('DOMDocument') || !function_exists('openssl_pkey_new')) {
        fwrite(STDERR, "SAML protocol test requires plugin-local Composer dependencies, ext-dom and ext-openssl\n");
        exit(2);
    }
    require $autoload;
    require $package . '/app/federation/SamlAssertionVerifier.php';
    require $package . '/app/federation/OneLoginSamlAssertionVerifier.php';

    function t04ProtocolFail(string $message): never
    {
        fwrite(STDERR, "IAM-T04 SAML protocol test failed: {$message}\n");
        exit(1);
    }

    function t04ProtocolAssert(bool $condition, string $message): void
    {
        if (!$condition) t04ProtocolFail($message);
    }

    function t04ProtocolReject(callable $callback, string $message): void
    {
        try {
            $callback();
        } catch (ApiException $exception) {
            t04ProtocolAssert($exception->getMessage() === 'SAND_IAM_SAML_ASSERTION_INVALID', "{$message}: {$exception->getMessage()}");
            return;
        }
        t04ProtocolFail("{$message}: response was accepted");
    }

    function t04ProtocolTime(int $time): string
    {
        return gmdate('Y-m-d\\TH:i:s\\Z', $time);
    }

    function t04ProtocolXml(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /** @return array{private_key:string,certificate:string} */
    function t04ProtocolKeyPair(): array
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        if ($key === false) t04ProtocolFail('RSA key generation failed');
        $csr = openssl_csr_new(['commonName' => 'iam-t04-idp.example.test'], $key, ['digest_alg' => 'sha256']);
        $certificate = $csr === false ? false : openssl_csr_sign($csr, null, $key, 1, ['digest_alg' => 'sha256']);
        if ($certificate === false || !openssl_pkey_export($key, $privateKey) || !openssl_x509_export($certificate, $x509)) {
            t04ProtocolFail('self-signed certificate generation failed');
        }
        return ['private_key' => $privateKey, 'certificate' => $x509];
    }

    function t04ProtocolSign(\DOMElement $element, \DOMNode $before, string $privateKey, string $certificate): void
    {
        $key = new XMLSecurityKey(XMLSecurityKey::RSA_SHA256, ['type' => 'private']);
        $key->loadKey($privateKey, false);
        $signature = new XMLSecurityDSig();
        $signature->setCanonicalMethod(XMLSecurityDSig::EXC_C14N);
        $signature->addReference($element, XMLSecurityDSig::SHA256, ['http://www.w3.org/2000/09/xmldsig#enveloped-signature', XMLSecurityDSig::EXC_C14N], ['id_name' => 'ID', 'overwrite' => false]);
        $signature->sign($key);
        $signature->add509Cert($certificate, true, false);
        $signature->insertSignature($element, $before);
    }

    /** @param array<string,string|bool> $changes */
    function t04ProtocolResponse(array $changes, string $privateKey, string $certificate): string
    {
        $now = time();
        $values = array_merge([
            'response_id' => '_t04-response',
            'assertion_id' => '_t04-assertion',
            'issuer' => 'https://idp.example.test/metadata',
            'request_id' => '_t04-request',
            'destination' => 'https://sp.example.test/saml/acs',
            'recipient' => 'https://sp.example.test/saml/acs',
            'audience' => 'https://sp.example.test/entity',
            'not_before' => t04ProtocolTime($now - 60),
            'not_on_or_after' => t04ProtocolTime($now + 300),
            'subject_not_on_or_after' => t04ProtocolTime($now + 300),
            'name_id' => 'alice@example.test',
            'wrap' => false,
        ], $changes);
        $xml = sprintf(
            '<samlp:Response xmlns:samlp="urn:oasis:names:tc:SAML:2.0:protocol" xmlns:saml="urn:oasis:names:tc:SAML:2.0:assertion" ID="%s" Version="2.0" IssueInstant="%s" Destination="%s" InResponseTo="%s"><saml:Issuer>%s</saml:Issuer><samlp:Status><samlp:StatusCode Value="urn:oasis:names:tc:SAML:2.0:status:Success"/></samlp:Status><saml:Assertion ID="%s" Version="2.0" IssueInstant="%s"><saml:Issuer>%s</saml:Issuer><saml:Subject><saml:NameID Format="urn:oasis:names:tc:SAML:2.0:nameid-format:persistent">%s</saml:NameID><saml:SubjectConfirmation Method="urn:oasis:names:tc:SAML:2.0:cm:bearer"><saml:SubjectConfirmationData InResponseTo="%s" Recipient="%s" NotOnOrAfter="%s"/></saml:SubjectConfirmation></saml:Subject><saml:Conditions NotBefore="%s" NotOnOrAfter="%s"><saml:AudienceRestriction><saml:Audience>%s</saml:Audience></saml:AudienceRestriction></saml:Conditions><saml:AuthnStatement AuthnInstant="%s" SessionIndex="_t04-session"><saml:AuthnContext><saml:AuthnContextClassRef>urn:oasis:names:tc:SAML:2.0:ac:classes:PasswordProtectedTransport</saml:AuthnContextClassRef></saml:AuthnContext></saml:AuthnStatement><saml:AttributeStatement><saml:Attribute Name="email"><saml:AttributeValue>%s</saml:AttributeValue></saml:Attribute></saml:AttributeStatement></saml:Assertion></samlp:Response>',
            ...array_map('t04ProtocolXml', [(string) $values['response_id'], t04ProtocolTime($now), (string) $values['destination'], (string) $values['request_id'], (string) $values['issuer'], (string) $values['assertion_id'], t04ProtocolTime($now), (string) $values['issuer'], (string) $values['name_id'], (string) $values['request_id'], (string) $values['recipient'], (string) $values['subject_not_on_or_after'], (string) $values['not_before'], (string) $values['not_on_or_after'], (string) $values['audience'], t04ProtocolTime($now), (string) $values['name_id']])
        );
        $document = new \DOMDocument();
        $document->preserveWhiteSpace = false;
        if (!$document->loadXML($xml, LIBXML_NONET)) t04ProtocolFail('fixture XML could not be parsed');
        $xpath = new \DOMXPath($document);
        $xpath->registerNamespace('samlp', 'urn:oasis:names:tc:SAML:2.0:protocol');
        $xpath->registerNamespace('saml', 'urn:oasis:names:tc:SAML:2.0:assertion');
        $assertion = $xpath->query('/samlp:Response/saml:Assertion')->item(0);
        $subject = $xpath->query('/samlp:Response/saml:Assertion/saml:Subject')->item(0);
        $response = $document->documentElement;
        $status = $xpath->query('/samlp:Response/samlp:Status')->item(0);
        if (!$assertion instanceof \DOMElement || !$subject instanceof \DOMNode || !$response instanceof \DOMElement || !$status instanceof \DOMNode) t04ProtocolFail('fixture XML shape changed');
        t04ProtocolSign($assertion, $subject, $privateKey, $certificate);
        t04ProtocolSign($response, $status, $privateKey, $certificate);
        if ($values['wrap'] === true) {
            $wrapped = $assertion->cloneNode(true);
            if (!$wrapped instanceof \DOMElement) t04ProtocolFail('wrapping fixture could not clone assertion');
            $nameId = $xpath->query('.//saml:NameID', $wrapped)->item(0);
            if ($nameId !== null) $nameId->nodeValue = 'attacker@example.test';
            $response->appendChild($wrapped);
        }
        return base64_encode((string) $document->saveXML());
    }

    $_SERVER['HTTPS'] = 'on';
    $_SERVER['HTTP_HOST'] = 'sp.example.test';
    $_SERVER['SERVER_PORT'] = 443;
    $_SERVER['SCRIPT_NAME'] = '/saml/acs';
    $_SERVER['REQUEST_URI'] = '/saml/acs';
    $_SERVER['QUERY_STRING'] = '';

    $keyPair = t04ProtocolKeyPair();
    $config = ['entity_id' => 'https://idp.example.test/metadata', 'sso_url' => 'https://idp.example.test/sso', 'idp_x509cert' => $keyPair['certificate'], 'sp_entity_id' => 'https://sp.example.test/entity'];
    $verifier = new OneLoginSamlAssertionVerifier();
    $valid = t04ProtocolResponse([], $keyPair['private_key'], $keyPair['certificate']);
    $claims = $verifier->verify($valid, $config, '_t04-request', 'https://sp.example.test/saml/acs');
    t04ProtocolAssert($claims['assertion_id'] === '_t04-assertion' && str_starts_with((string) $claims['sub'], 'saml:v1:'), 'valid signed response did not yield a stable assertion identifier and subject');

    $wrongKeyPair = t04ProtocolKeyPair();
    t04ProtocolReject(static fn () => $verifier->verify($valid, [...$config, 'idp_x509cert' => $wrongKeyPair['certificate']], '_t04-request', 'https://sp.example.test/saml/acs'), 'wrong IdP certificate');
    t04ProtocolReject(static fn () => $verifier->verify($valid, [...$config, 'entity_id' => 'https://other-idp.example.test/metadata'], '_t04-request', 'https://sp.example.test/saml/acs'), 'wrong issuer');
    t04ProtocolReject(static fn () => $verifier->verify(t04ProtocolResponse(['audience' => 'https://other-sp.example.test/entity'], $keyPair['private_key'], $keyPair['certificate']), $config, '_t04-request', 'https://sp.example.test/saml/acs'), 'wrong audience');
    t04ProtocolReject(static fn () => $verifier->verify(t04ProtocolResponse(['recipient' => 'https://sp.example.test/other'], $keyPair['private_key'], $keyPair['certificate']), $config, '_t04-request', 'https://sp.example.test/saml/acs'), 'wrong recipient');
    t04ProtocolReject(static fn () => $verifier->verify(t04ProtocolResponse(['destination' => 'https://sp.example.test/other'], $keyPair['private_key'], $keyPair['certificate']), $config, '_t04-request', 'https://sp.example.test/saml/acs'), 'wrong destination');
    t04ProtocolReject(static fn () => $verifier->verify(t04ProtocolResponse(['request_id' => '_other-request'], $keyPair['private_key'], $keyPair['certificate']), $config, '_t04-request', 'https://sp.example.test/saml/acs'), 'wrong InResponseTo');
    t04ProtocolReject(static fn () => $verifier->verify(t04ProtocolResponse(['not_before' => t04ProtocolTime(time() + 600)], $keyPair['private_key'], $keyPair['certificate']), $config, '_t04-request', 'https://sp.example.test/saml/acs'), 'future NotBefore');
    t04ProtocolReject(static fn () => $verifier->verify(t04ProtocolResponse(['not_on_or_after' => t04ProtocolTime(time() - 600), 'subject_not_on_or_after' => t04ProtocolTime(time() - 600)], $keyPair['private_key'], $keyPair['certificate']), $config, '_t04-request', 'https://sp.example.test/saml/acs'), 'expired NotOnOrAfter');
    t04ProtocolReject(static fn () => $verifier->verify(t04ProtocolResponse(['wrap' => true], $keyPair['private_key'], $keyPair['certificate']), $config, '_t04-request', 'https://sp.example.test/saml/acs'), 'duplicate assertion ID XML wrapping');

    $start = $verifier->start([...$config, 'sp_x509cert' => $keyPair['certificate'], 'sp_private_key' => $keyPair['private_key']], 'https://sp.example.test/saml/acs', 'fs_' . str_repeat('a', 48));
    $query = [];
    parse_str((string) parse_url($start['redirect_uri'], PHP_URL_QUERY), $query);
    t04ProtocolAssert(isset($query['SAMLRequest'], $query['RelayState'], $query['SigAlg'], $query['Signature']), 'AuthnRequest is not Redirect-binding signed');
    t04ProtocolAssert($query['RelayState'] === 'fs_' . str_repeat('a', 48) && $start['request_id'] !== $query['RelayState'], 'RelayState and AuthnRequest ID are not separate');

    $service = file_get_contents($package . '/app/service/FederationService.php');
    t04ProtocolAssert($service !== false && str_contains($service, 'SAND_IAM_SAML_ASSERTION_REPLAYED') && str_contains($service, "saml-assertion:' . \$assertionId") && str_contains($service, "'assertion_hash' => \$this->hash"), 'service no longer rejects persisted SAML assertion replays');
    echo "SAML protocol non-PG test passed\n";
}
