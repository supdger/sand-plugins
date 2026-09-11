<?php

declare(strict_types=1);

// behavior-test-gate: static-rule

/** Ensure authorization consumption, code issuance and both decision audits share one database transaction. */
$service = dirname(__DIR__) . '/app/service/OAuthOidcService.php';
$source = file_get_contents($service);
if (!is_string($source)) {
    fwrite(STDERR, "OAuth service is unreadable\n");
    exit(1);
}
$start = strpos($source, 'public function approveAuthorization(');
$end = strpos($source, 'private function issueAuthorizationCode(', $start === false ? 0 : $start);
if ($start === false || $end === false) {
    fwrite(STDERR, "OAuth approval boundary is missing\n");
    exit(1);
}
$method = substr($source, $start, $end - $start);
$denyAudit = strpos($method, "'oauth.authorize_consent', 'denied'");
$approveAudit = strpos($method, "'oauth.authorize_consent', 'succeeded'");
$codeIssue = strpos($method, '$this->issueAuthorizationCode');
$firstCommit = strpos($method, 'Db::commit()');
if ($denyAudit === false || $approveAudit === false || $firstCommit === false || $denyAudit > $firstCommit) {
    fwrite(STDERR, "OAuth denial audit must occur before the transaction commits\n");
    exit(1);
}
$approveCommit = strpos($method, 'Db::commit()', $approveAudit);
if ($approveCommit === false || $approveAudit > $approveCommit || $codeIssue === false || $codeIssue > $approveCommit) {
    fwrite(STDERR, "OAuth approval audit and authorization-code audit must be transactional\n");
    exit(1);
}
if (str_contains(substr($method, $approveCommit), "'oauth.authorize_consent', 'succeeded'")) {
    fwrite(STDERR, "OAuth approval audit must not be emitted after commit\n");
    exit(1);
}

$bindStart = strpos($source, 'public function bindInteraction(');
$bindEnd = strpos($source, 'public function approveAuthorization(', $bindStart === false ? 0 : $bindStart);
if ($bindStart === false || $bindEnd === false) {
    fwrite(STDERR, "OAuth interaction-binding boundary is missing\n");
    exit(1);
}
$bindMethod = substr($source, $bindStart, $bindEnd - $bindStart);
$bindAudit = strpos($bindMethod, "'oauth.interaction_bind', 'succeeded'");
$bindCommit = strpos($bindMethod, 'Db::commit()');
if ($bindAudit === false || $bindCommit === false || $bindAudit > $bindCommit) {
    fwrite(STDERR, "OAuth interaction-binding audit must occur before the transaction commits\n");
    exit(1);
}
if (str_contains(substr($bindMethod, $bindCommit), "'oauth.interaction_bind', 'succeeded'")) {
    fwrite(STDERR, "OAuth interaction-binding audit must not be emitted after commit\n");
    exit(1);
}
echo "OAuth consent atomicity non-PG contract checks passed\n";
