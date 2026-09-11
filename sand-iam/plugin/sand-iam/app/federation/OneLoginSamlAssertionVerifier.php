<?php

declare(strict_types=1);

namespace plugin\SandIam\app\federation;

use plugin\sandadmin\exception\ApiException;

/**
 * Production SAML verification adapter. It is deliberately unavailable until
 * the plugin-local audited dependency is bundled by the installation package.
 */
final class OneLoginSamlAssertionVerifier implements SamlAssertionVerifier
{
    /** @return array{request_id:string,redirect_uri:string} */
    public function start(array $config, string $acs, string $relayState): array
    {
        if (!class_exists('OneLogin\\Saml2\\Auth')) throw new ApiException('SAND_IAM_SAML_VERIFIER_UNAVAILABLE', 503);
        $this->https($acs); $this->https((string) ($config['entity_id'] ?? '')); $this->https((string) ($config['sso_url'] ?? ''));
        $certificate = trim((string) ($config['idp_x509cert'] ?? ''));
        $spCertificate = trim((string) ($config['sp_x509cert'] ?? ''));
        $spPrivateKey = trim((string) ($config['sp_private_key'] ?? ''));
        if ($certificate === '' || $spCertificate === '' || $spPrivateKey === '') throw new ApiException('SAND_IAM_SAML_CONFIGURATION_INVALID', 400);
        $settings = ['strict' => true, 'debug' => false, 'sp' => ['entityId' => (string) ($config['sp_entity_id'] ?? $acs), 'assertionConsumerService' => ['url' => $acs], 'x509cert' => $spCertificate, 'privateKey' => $spPrivateKey], 'idp' => ['entityId' => (string) $config['entity_id'], 'singleSignOnService' => ['url' => (string) $config['sso_url']], 'x509cert' => $certificate], 'security' => ['authnRequestsSigned' => true, 'wantAssertionsSigned' => true, 'wantMessagesSigned' => true, 'wantXMLValidation' => true, 'rejectUnsolicitedResponsesWithInResponseTo' => true, 'destinationStrictlyMatches' => true, 'signatureAlgorithm' => 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256', 'digestAlgorithm' => 'http://www.w3.org/2001/04/xmlenc#sha256']];
        try {
            $auth = new \OneLogin\Saml2\Auth($settings);
            // RelayState is an opaque transaction locator, deliberately
            // separate from the library-generated AuthnRequest ID.
            $redirect = $auth->login($relayState, [], false, false, true);
            $requestId = $auth->getLastRequestID();
            if (!is_string($redirect) || !is_string($requestId) || $redirect === '' || $requestId === '') throw new ApiException('SAND_IAM_SAML_CONFIGURATION_INVALID', 400);
            return ['request_id' => $requestId, 'redirect_uri' => $redirect];
        } catch (ApiException $exception) { throw $exception; } catch (\Throwable) { throw new ApiException('SAND_IAM_SAML_CONFIGURATION_INVALID', 400); }
    }

    public function verify(string $samlResponse, array $config, string $expectedRequestId, string $expectedRecipient): array
    {
        if (!class_exists('OneLogin\\Saml2\\Settings') || !class_exists('OneLogin\\Saml2\\Response')) throw new ApiException('SAND_IAM_SAML_VERIFIER_UNAVAILABLE', 503);
        if (strlen($samlResponse) > 1_048_576) throw new ApiException('SAND_IAM_SAML_ASSERTION_INVALID', 400);
        $this->https((string) ($config['entity_id'] ?? ''));
        $this->https((string) ($config['sso_url'] ?? ''));
        $certificate = trim((string) ($config['idp_x509cert'] ?? ''));
        if ($certificate === '' || $expectedRequestId === '' || $expectedRecipient === '') throw new ApiException('SAND_IAM_SAML_CONFIGURATION_INVALID', 400);
        $settings = [
            'strict' => true,
            'debug' => false,
            'sp' => ['entityId' => (string) ($config['sp_entity_id'] ?? $expectedRecipient), 'assertionConsumerService' => ['url' => $expectedRecipient]],
            'idp' => ['entityId' => (string) $config['entity_id'], 'singleSignOnService' => ['url' => (string) $config['sso_url']], 'x509cert' => $certificate],
            'security' => [
                'wantAssertionsSigned' => true,
                'wantMessagesSigned' => true,
                'wantNameIdEncrypted' => false,
                'wantXMLValidation' => true,
                'rejectUnsolicitedResponsesWithInResponseTo' => true,
                'destinationStrictlyMatches' => true,
                'signatureAlgorithm' => 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256',
                'digestAlgorithm' => 'http://www.w3.org/2001/04/xmlenc#sha256',
            ],
        ];
        try {
            $settingsObject = new \OneLogin\Saml2\Settings($settings);
            // Response receives the exact controller argument; it never reads
            // $_POST and therefore cannot verify a different assertion.
            $response = new \OneLogin\Saml2\Response($settingsObject, $samlResponse);
            if (!$response->isValid($expectedRequestId)) throw new ApiException('SAND_IAM_SAML_ASSERTION_INVALID', 401);
            $this->requireExpectedAcsBinding($response, $expectedRecipient);
            $nameId = $response->getNameId();
            if (!is_string($nameId) || $nameId === '') throw new ApiException('SAND_IAM_SAML_ASSERTION_INVALID', 401);
            $format = (string) $response->getNameIdFormat();
            if (str_contains(strtolower($format), 'transient')) throw new ApiException('SAND_IAM_SAML_ASSERTION_INVALID', 401);
            $attributes = $response->getAttributes();
            // PostgreSQL text rejects NUL bytes. Preserve the protocol tuple
            // unambiguously in a versioned digest; the provider instance is
            // already part of the binding namespace.
            $subject = 'saml:v1:' . rtrim(strtr(base64_encode(hash('sha256', $format . "\0" . $nameId, true)), '+/', '-_'), '=');
            return ['sub' => $subject, 'assertion_id' => (string) $response->getAssertionId(), 'display_name' => (string) ($attributes['displayName'][0] ?? $nameId), 'email' => (string) ($attributes['email'][0] ?? '')];
        } catch (ApiException $exception) {
            throw $exception;
        } catch (\Throwable) {
            throw new ApiException('SAND_IAM_SAML_ASSERTION_INVALID', 401);
        }
    }

    /**
     * OneLogin validates Destination and Recipient against the HTTP request
     * URL. The transaction also has a pre-registered ACS URL, so bind the
     * signed response to that exact value rather than relying on proxy URL
     * reconstruction alone.
     */
    private function requireExpectedAcsBinding(\OneLogin\Saml2\Response $response, string $expectedRecipient): void
    {
        $document = $response->document;
        $root = $document->documentElement;
        if ($root === null || !hash_equals($expectedRecipient, (string) $root->getAttribute('Destination'))) {
            throw new ApiException('SAND_IAM_SAML_ASSERTION_INVALID', 401);
        }
        $xpath = new \DOMXPath($document);
        $xpath->registerNamespace('samlp', 'urn:oasis:names:tc:SAML:2.0:protocol');
        $xpath->registerNamespace('saml', 'urn:oasis:names:tc:SAML:2.0:assertion');
        $confirmations = $xpath->query('/samlp:Response/saml:Assertion/saml:Subject/saml:SubjectConfirmation[@Method="urn:oasis:names:tc:SAML:2.0:cm:bearer"]/saml:SubjectConfirmationData');
        if ($confirmations === false || $confirmations->length === 0) {
            throw new ApiException('SAND_IAM_SAML_ASSERTION_INVALID', 401);
        }
        foreach ($confirmations as $confirmation) {
            if (!hash_equals($expectedRecipient, (string) $confirmation->getAttribute('Recipient'))) {
                throw new ApiException('SAND_IAM_SAML_ASSERTION_INVALID', 401);
            }
        }
    }

    private function https(string $url): void
    {
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || !isset($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment']) || ((int) ($parts['port'] ?? 443)) < 1 || ((int) ($parts['port'] ?? 443)) > 65535) throw new ApiException('SAND_IAM_SAML_CONFIGURATION_INVALID', 400);
    }
}
