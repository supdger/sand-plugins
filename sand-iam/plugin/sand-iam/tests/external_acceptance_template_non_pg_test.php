<?php

declare(strict_types=1);

// behavior-test-gate: static-rule

$root = dirname(__DIR__, 3);
$generator = $root . '/tools/prepare-external-acceptance.php';
$enduranceValidator = $root . '/tools/run-endurance-acceptance.php';
$casdoorValidator = $root . '/tools/validate-casdoor-comparison.php';
$interopValidator = $root . '/tools/validate-protocol-interop.php';
$recoveryValidator = $root . '/tools/validate-backup-recovery.php';
$independentValidator = $root . '/tools/validate-independent-delivery.php';
require_once $root . '/tools/release-bundle-attestation.php';
$seed = tempnam('/private/tmp', 'sand-iam-external-template-');
if ($seed === false || !unlink($seed) || !mkdir($seed, 0700)) throw new RuntimeException('cannot create external template fixture');

/** @return array{0:int,1:string} */
$run = static function (array $arguments): array {
    $command = implode(' ', array_map('escapeshellarg', $arguments));
    $output = [];
    exec($command . ' 2>&1', $output, $status);
    return [$status, implode("\n", $output)];
};
$removeTree = null;
$removeTree = static function (string $path) use (&$removeTree): void {
    if (is_link($path) || is_file($path)) { unlink($path); return; }
    foreach (scandir($path) ?: [] as $entry) if ($entry !== '.' && $entry !== '..') $removeTree($path . '/' . $entry);
    rmdir($path);
};

try {
    $archivePath = $seed . '/sand-iam.zip';
    $zip = new ZipArchive();
    if ($zip->open($archivePath, ZipArchive::CREATE) !== true) throw new RuntimeException('cannot create release ZIP fixture');
    foreach ([
        'README.md' => "# SandIAM\n",
        'update.sql' => "-- immutable lifecycle fixture\n",
        'release-build-contract.json' => "{\"schema\":\"sand-iam.release-build-contract/v1\"}\n",
    ] as $path => $contents) if (!$zip->addFromString($path, $contents)) throw new RuntimeException('cannot add release ZIP fixture entry');
    if (!$zip->close()) throw new RuntimeException('cannot finalize release ZIP fixture');
    $archive = sandIamInspectReleaseZip($archivePath);
    $archiveSha256 = hash_file('sha256', $archivePath);
    $archiveBytes = filesize($archivePath);
    if (!is_string($archiveSha256) || !is_int($archiveBytes)) throw new RuntimeException('cannot inspect release ZIP fixture');
    $buildContract = $archive['files']['release-build-contract.json'] ?? null;
    if (!is_array($buildContract)) throw new RuntimeException('release ZIP fixture has no build contract');
    $manifest = [
        'schema' => 'sand-iam.artifact-manifest/v8', 'kind' => 'release-candidate-unsigned', 'release_state' => 'release/unsigned',
        'archive_authority_parity' => ['passed' => true, 'normal_package_recovery_descriptors' => 'excluded'],
        'reproducibility' => ['bit_identical_zip' => true, 'entry_list_identical' => true],
        'package' => ['app' => 'sand-iam', 'version' => '0.7.1', 'archive' => basename($archivePath), 'sha256' => $archiveSha256, 'bytes' => $archiveBytes, 'entry_count' => $archive['entry_count']],
        'source_revision' => [
            'vcs' => 'git', 'commit' => str_repeat('b', 40), 'tree' => str_repeat('c', 40),
            'subtree' => 'sand-iam/', 'clean' => true,
        ],
        'source_provenance' => [
            'mode' => 'git-blob-only', 'commit' => str_repeat('b', 40), 'tree' => str_repeat('c', 40),
            'stage_matches_git_blobs' => true, 'independent_git_stage_rebuild' => true,
            'build_contract_sha256' => $buildContract['sha256'],
        ],
        'source_snapshot' => ['sha256' => str_repeat('c', 64)],
        'files' => $archive['files'],
    ];
    $manifestPath = $seed . '/manifest.json';
    file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    $endurancePath = $seed . '/endurance.json';
    $casdoorPath = $seed . '/casdoor.json';
    $interopPath = $seed . '/interop.json';
    $recoveryPath = $seed . '/recovery.json';
    $recoveryPlanPath = $seed . '/recovery-plan.json';
    $independentPath = $seed . '/independent-delivery.json';
    $generate = static function (string $kind, string $outputPath, ?string $planOutputPath = null) use ($run, $generator, $manifestPath): void {
        $arguments = [PHP_BINARY, $generator, '--kind=' . $kind, '--artifact-manifest=' . $manifestPath, '--output=' . $outputPath];
        if ($planOutputPath !== null) $arguments[] = '--plan-output=' . $planOutputPath;
        [$status, $output] = $run($arguments);
        if ($status !== 0) throw new RuntimeException('cannot generate ' . $kind . ' acceptance template: ' . $output);
    };
    $generate('endurance', $endurancePath);
    $generate('casdoor', $casdoorPath);
    $generate('interop', $interopPath);
    $generate('recovery', $recoveryPath, $recoveryPlanPath);
    $generate('independent-delivery', $independentPath);

    $existingPath = $seed . '/existing-template.json';
    $existingBytes = "pre-existing template must remain unchanged\n";
    file_put_contents($existingPath, $existingBytes);
    [$existingStatus] = $run([PHP_BINARY, $generator, '--kind=casdoor', '--artifact-manifest=' . $manifestPath, '--output=' . $existingPath]);

    $symlinkTarget = $seed . '/symlink-target.json';
    $symlinkBytes = "symlink target must remain unchanged\n";
    file_put_contents($symlinkTarget, $symlinkBytes);
    $symlinkPath = $seed . '/symlink-template.json';
    if (!symlink($symlinkTarget, $symlinkPath)) throw new RuntimeException('cannot create template symlink fixture');
    [$symlinkStatus] = $run([PHP_BINARY, $generator, '--kind=casdoor', '--artifact-manifest=' . $manifestPath, '--output=' . $symlinkPath]);

    $racePath = $seed . '/template-race.json';
    $raceCommand = implode(' ', array_map('escapeshellarg', [PHP_BINARY, $generator, '--kind=casdoor', '--artifact-manifest=' . $manifestPath, '--output=' . $racePath]));
    $raceProcesses = [];
    for ($index = 0; $index < 6; ++$index) {
        $pipes = [];
        $process = proc_open($raceCommand, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) throw new RuntimeException('cannot start concurrent template fixture');
        $raceProcesses[] = [$process, $pipes];
    }
    $raceStatuses = [];
    foreach ($raceProcesses as [$process, $pipes]) {
        foreach ($pipes as $pipe) if (is_resource($pipe)) stream_get_contents($pipe);
        foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
        $raceStatuses[] = proc_close($process);
    }
    $raceDocument = is_file($racePath) ? json_decode((string) file_get_contents($racePath), true, 512, JSON_THROW_ON_ERROR) : null;
    [$enduranceStatus, $enduranceOutput] = $run([PHP_BINARY, $enduranceValidator, '--plan=' . $endurancePath, '--validate-only']);
    [$casdoorStatus, $casdoorOutput] = $run([PHP_BINARY, $casdoorValidator, '--report=' . $casdoorPath]);
    [$interopStatus, $interopOutput] = $run([PHP_BINARY, $interopValidator, '--report=' . $interopPath]);
    [$recoveryStatus, $recoveryOutput] = $run([PHP_BINARY, $recoveryValidator, '--report=' . $recoveryPath, '--plan=' . $recoveryPlanPath, '--archive=' . $archivePath, '--artifact-manifest=' . $manifestPath]);
    [$independentStatus, $independentOutput] = $run([PHP_BINARY, $independentValidator, '--report=' . $independentPath]);
    $readJson = static function (string $path): array {
        $contents = file_get_contents($path);
        if (!is_string($contents)) throw new RuntimeException('cannot read generated acceptance template');
        $document = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($document)) throw new RuntimeException('generated acceptance template must be an object');
        return $document;
    };
    $endurance = $readJson($endurancePath);
    $casdoor = $readJson($casdoorPath);
    $interop = $readJson($interopPath);
    $recovery = $readJson($recoveryPath);
    $recoveryPlan = $readJson($recoveryPlanPath);
    $independent = $readJson($independentPath);
    $readyEndurance = $endurance;
    $readyEndurance['approved_hosts'] = ['endurance.example.invalid'];
    foreach ($readyEndurance['targets'] as &$target) {
        $target['url'] = str_replace('__REQUIRED_APPROVED_HOST__', 'endurance.example.invalid', $target['url']);
        $target['max_p99_ms'] = 500;
    }
    unset($target);
    foreach ($readyEndurance['thresholds'] as $name => $value) {
        if ($value < 0) $readyEndurance['thresholds'][$name] = str_contains($name, 'slope') ? 1 : 1024;
    }
    file_put_contents($endurancePath, json_encode($readyEndurance, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    [$readyEnduranceStatus, $readyEnduranceOutput] = $run([PHP_BINARY, $enduranceValidator, '--plan=' . $endurancePath, '--validate-only']);
    $ref = static fn(string $type, string $id): array => ['type' => $type, 'id' => $id];
    $scope = static fn(array $org, array $app, ?array $env): array => ['organization_ref' => $org, 'application_ref' => $app, 'environment_ref' => $env];
    $org1=$ref('organizations','org-001'); $org2=$ref('organizations','org-002'); $app1=$ref('applications','app-001'); $app2=$ref('applications','app-002'); $app3=$ref('applications','app-003'); $env1=$ref('environments','env-001'); $env2=$ref('environments','env-002'); $env3=$ref('environments','env-003'); $env4=$ref('environments','env-004');
    $s1=$scope($org1,$app1,$env1); $s2=$scope($org1,$app1,$env2); $s3=$scope($org1,$app2,$env3); $s4=$scope($org2,$app3,$env4);
    $record = static fn(string $id, array $ownedScope, string $status='active', string $version='ver-001'): array => ['id'=>$id,'status'=>$status,'version'=>$version,'scope'=>$ownedScope];
    $orgScope = static fn(array $org): array => ['organization_ref'=>$org,'application_ref'=>null,'environment_ref'=>null]; $appScope = static fn(array $org, array $app): array => ['organization_ref'=>$org,'application_ref'=>$app,'environment_ref'=>null];
    $entities=['organizations'=>[$record('org-001',$orgScope($org1)),$record('org-002',$orgScope($org2))],'applications'=>[$record('app-001',$appScope($org1,$app1)),$record('app-002',$appScope($org1,$app2)),$record('app-003',$appScope($org2,$app3))],'environments'=>[$record('env-001',$s1),$record('env-002',$s2),$record('env-003',$s3),$record('env-004',$s4)]];
    foreach(['users','groups','roles','resources','policies','data_scopes','grants','audit','outbox','sync_cursors','idempotency_keys'] as $type) $entities[$type]=[$record(substr($type,0,3).'-001',$s1)]; $entities['users']=[$record('use-001',$appScope($org1,$app1))];
    $services=[]; foreach(['svc-001'=>$s1,'svc-002'=>$s2,'svc-003'=>$s3,'svc-004'=>$s4,'svc-005'=>$s1,'svc-006'=>$s1,'svc-007'=>$s1,'svc-008'=>$s1] as $id=>$ownedScope) $services[]=$record($id,$ownedScope); $entities['service_identities']=$services;
    $owners=['cred-allow'=>$ref('service_identities','svc-001'),'cred-deny'=>$ref('service_identities','svc-005'),'cred-revoke'=>$ref('service_identities','svc-002'),'cred-tenant'=>$ref('service_identities','svc-001'),'cred-appxx'=>$ref('service_identities','svc-001'),'cred-envxx'=>$ref('service_identities','svc-001'),'cred-audxx'=>$ref('service_identities','svc-005'),'cred-reply'=>$ref('service_identities','svc-006'),'cred-oldxx'=>$ref('service_identities','svc-007'),'cred-newxx'=>$ref('service_identities','svc-007')];
    $entities['credentials']=[]; $entities['key_versions']=[]; foreach($owners as $id=>$owner){$ownedScope=array_values(array_filter($services,static fn(array $item):bool=>$item['id']===$owner['id']))[0]['scope'];$keyId='key-'.substr($id,5);$entities['credentials'][]=['id'=>$id,'status'=>'active','version'=>'ver-001','scope'=>$ownedScope,'owner_ref'=>$owner,'key_version_ref'=>$ref('key_versions',$keyId)];$entities['key_versions'][]=['id'=>$keyId,'status'=>'active','version'=>'ver-001','scope'=>$ownedScope,'credential_ref'=>$ref('credentials',$id)];}
    $readyRecovery=$recovery; $candidate=$readyRecovery['candidate']; $runId=$readyRecovery['run_id']; $environment=['id'=>'env-fixture','collector'=>['id'=>'fixture','version'=>'ver-001'],'source'=>['system_identifier'=>'101','database_oid'=>'201','database_name_hex'=>'736f75726365'],'target'=>['system_identifier'=>'102','database_oid'=>'202','database_name_hex'=>'746172676574']]; $plan=['schema'=>'sand-iam.backup-recovery-plan/v2','run_id'=>$runId,'candidate'=>$candidate,'environment'=>$environment,'collector'=>$environment['collector'],'thresholds'=>['rpo_limit_seconds'=>10,'rto_limit_seconds'=>10,'minimum_consistency_lsn'=>'0/10']]; file_put_contents($recoveryPlanPath,json_encode($plan,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)); file_put_contents($seed.'/raw.dump',"PGDMP template fixture\n"); file_put_contents($seed.'/archive.list',"TABLE DATA public sand_iam_application\n"); file_put_contents($seed.'/envelope.bin',"encrypted template fixture\n");
    $timeline=[];foreach(['source_snapshot','consistency_point','backup_start','backup_end','security_changes_end','restore_start','restore_end','reconcile_start','reconcile_end','probe','cleanup'] as $i=>$stage)$timeline[]=['stage'=>$stage,'at'=>sprintf('2026-01-01T00:00:%02d.000000Z',$i),'monotonic_us'=>1000000+1000000*$i]; $credential=static fn(string $id):array=>$ref('credentials',$id); $delta=static fn(string $id,string $status,string $version):array=>['entity_ref'=>$credential($id),'status'=>$status,'version'=>$version];
    $events=[['event_id'=>'evt-revoke','sequence'=>1,'kind'=>'revocation','entity_ref'=>$credential('cred-revoke'),'before'=>['status'=>'active','version'=>'ver-001'],'after'=>['status'=>'revoked','version'=>'ver-002'],'source_at'=>'2026-01-01T00:00:03.100000Z','source_monotonic_us'=>4100000,'source_lsn'=>'0/11','source_audit_ref'=>'audit-src-001'],['event_id'=>'evt-rotate','sequence'=>2,'kind'=>'credential_rotation','entity_ref'=>$credential('cred-oldxx'),'before'=>['status'=>'active','version'=>'ver-001'],'after'=>['status'=>'revoked','version'=>'ver-002'],'new_credential_ref'=>$credential('cred-newxx'),'new_before'=>['status'=>'active','version'=>'ver-001'],'new_after'=>['status'=>'active','version'=>'ver-002'],'key_version_ref'=>$ref('key_versions','key-newxx'),'source_at'=>'2026-01-01T00:00:03.200000Z','source_monotonic_us'=>4200000,'source_lsn'=>'0/12','source_audit_ref'=>'audit-src-002']]; $applications=[];foreach([['evt-revoke','cred-revoke','revoked'],['evt-rotate','cred-oldxx','revoked']] as $i=>$item)$applications[]=['event_id'=>$item[0],'entity_ref'=>$credential($item[1]),'target_at'=>'2026-01-01T00:00:07.'.($i+1).'00000Z','target_monotonic_us'=>8100000+$i*100000,'target_audit_ref'=>'audit-tgt-00'.($i+1),'resulting_state'=>['status'=>$item[2],'version'=>'ver-002']];
    $probeDefs=[['allow','svc-001',$env1,'svc-001',$env1,'cred-allow',null],['deny','svc-005',$env1,'svc-003',$env3,'cred-deny',null],['revoked','svc-002',$env2,'svc-001',$env1,'cred-revoke','evt-revoke'],['cross_tenant','svc-001',$env1,'svc-004',$env4,'cred-tenant',null],['cross_app','svc-001',$env1,'svc-003',$env3,'cred-appxx',null],['cross_env','svc-001',$env1,'svc-002',$env2,'cred-envxx',null],['wrong_audience','svc-005',$env1,'svc-005',$env1,'cred-audxx',null],['replay_or_expired','svc-006',$env1,'svc-006',$env1,'cred-reply',null],['rotation_old','svc-007',$env1,'svc-007',$env1,'cred-oldxx','evt-rotate'],['rotation_new','svc-007',$env1,'svc-008',$env1,'cred-newxx','evt-rotate']];$probes=[];foreach($probeDefs as $i=>[$type,$actor,$actorEnv,$target,$targetEnv,$cred,$event]){$allow=$type==='allow'||$type==='rotation_new';$probes[]=['type'=>$type,'decision'=>$allow?'allow':'deny','outcome'=>$allow?'applied':'rejected','actor_ref'=>$ref('service_identities',$actor),'target_actor_ref'=>$ref('service_identities',$target),'scope_ref'=>$actorEnv,'target_scope_ref'=>$targetEnv,'credential_ref'=>$credential($cred),'credential_owner_ref'=>$owners[$cred],'credential_version'=>in_array($type,['revoked','rotation_old','rotation_new'],true)?'ver-002':'ver-001','security_event_id'=>$event,'candidate'=>$candidate,'run_id'=>$runId,'request_id'=>'req-'.str_pad((string)$i,3,'0',STR_PAD_LEFT),'source_audit_ref'=>'probe-src-'.str_pad((string)$i,3,'0',STR_PAD_LEFT),'target_audit_ref'=>'probe-tgt-'.str_pad((string)$i,3,'0',STR_PAD_LEFT),'business_side_effect'=>$allow?'applied':'rejected'];}
    $readyRecovery['reviewer']=['id'=>'reviewer-001','independent'=>true,'conflict_statement'=>'independent template test'];$readyRecovery['environment']=$environment;$readyRecovery['plan_sha256']=hash_file('sha256',$recoveryPlanPath);$readyRecovery['timeline']=$timeline;$readyRecovery['timing']=['snapshot_us'=>1000000,'backup_us'=>1000000,'restore_us'=>1000000,'reconcile_us'=>1000000,'probe_us'=>1000000,'cleanup_us'=>1000000,'rpo_us'=>2000000,'rto_us'=>4000000];$readyRecovery['backup']=['format'=>'custom','backup_id'=>'backup-001','consistency_lsn'=>'0/10','raw_dump'=>['path'=>'raw.dump','sha256'=>hash_file('sha256',$seed.'/raw.dump'),'bytes'=>filesize($seed.'/raw.dump')],'archive_list'=>['path'=>'archive.list','sha256'=>hash_file('sha256',$seed.'/archive.list'),'bytes'=>filesize($seed.'/archive.list')],'rpo_seconds'=>2,'rpo_limit_seconds'=>10,'rto_seconds'=>4,'rto_limit_seconds'=>10];$state=['entities'=>$entities];$readyRecovery['state']=['source_at_snapshot'=>$state,'restored'=>$state];$readyRecovery['reconcile']=['source_after_changes'=>['records'=>[$delta('cred-revoke','revoked','ver-002'),$delta('cred-oldxx','revoked','ver-002'),$delta('cred-newxx','active','ver-002')]],'target_after_reconcile'=>['records'=>[$delta('cred-revoke','revoked','ver-002'),$delta('cred-oldxx','revoked','ver-002'),$delta('cred-newxx','active','ver-002')]],'events'=>$events,'applications'=>$applications];$readyRecovery['probes']=['cases'=>$probes];$readyRecovery['queue']=['pending'=>0,'delivered'=>1,'dead_letter'=>0,'idempotency_keys'=>1,'duplicate_side_effects'=>0,'business_audit_refs'=>['biz-001'],'service_audit_refs'=>['svc-audit-001']];$readyRecovery['encryption']=['algorithm'=>'aes-256-gcm','format'=>'fixture','envelope'=>['path'=>'envelope.bin','sha256'=>hash_file('sha256',$seed.'/envelope.bin'),'bytes'=>filesize($seed.'/envelope.bin')],'external_key_id'=>'kms-key-001','key_version'=>'key-version-001','key_fingerprint'=>'fingerprint-001','kms_custody_ref'=>'custody-001','access_audit_ref'=>'access-001','retention_until'=>'retain-001','restore_key_access_audit_ref'=>'restore-access-001'];$readyRecovery['cleanup']=['ordered_steps'=>['close','verify','remove'],'residual_entities'=>0];$readyRecovery['evidence']=[];foreach(['timeline','state','reconcile','probes','queue','encryption','cleanup','backup'] as $type){$payload=['report_section'=>$readyRecovery[$type]];$e=['schema'=>'sand-iam.backup-recovery-evidence/v3','type'=>$type,'run_id'=>$runId,'candidate'=>$candidate,'environment'=>$environment,'collector'=>$environment['collector'],'plan_sha256'=>$readyRecovery['plan_sha256'],'payload'=>$payload,'payload_sha256'=>hash('sha256',sandIamCanonicalJson($payload))];$path=$seed.'/evidence-'.$type.'.json';file_put_contents($path,json_encode($e,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));$readyRecovery['evidence'][]=['type'=>$type,'path'=>basename($path),'sha256'=>hash_file('sha256',$path)];}file_put_contents($recoveryPath,json_encode($readyRecovery,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));[$readyRecoveryStatus,$readyRecoveryOutput]=$run([PHP_BINARY,$recoveryValidator,'--report='.$recoveryPath,'--plan='.$recoveryPlanPath,'--archive='.$archivePath,'--artifact-manifest='.$manifestPath]);
    $manifestHash = hash_file('sha256', $manifestPath);
    $requiredRetentionMetrics = ['security_operation_retention_backlog', 'auth_rate_limit_retention_backlog'];
    $requiredRetentionThresholds = ['max_security_operation_retention_backlog', 'max_auth_rate_limit_retention_backlog'];
    $passed = ($endurance['candidate']['archive_sha256'] ?? null) === $archiveSha256
        && ($casdoor['candidate']['artifact_manifest_sha256'] ?? null) === $manifestHash
        && count($endurance['targets'] ?? []) === 7 && count($casdoor['journeys'] ?? []) === 3
        && array_diff($requiredRetentionMetrics, array_keys($endurance['metrics'] ?? [])) === []
        && array_reduce($requiredRetentionThresholds, static fn (bool $ok, string $key): bool => $ok && ($endurance['thresholds'][$key] ?? null) === 0, true)
        && $readyEnduranceStatus === 0 && str_contains($readyEnduranceOutput, 'duration_seconds=28800')
        && count($interop['cases'] ?? []) === 7
        && ($recovery['schema'] ?? null) === 'sand-iam.backup-recovery/v3' && ($recoveryPlan['schema'] ?? null) === 'sand-iam.backup-recovery-plan/v2' && count($recovery['evidence'] ?? []) === 8 && ($recovery['run_id'] ?? null) !== null
        && count($independent['steps'] ?? []) === 8 && ($independent['participant']['independent'] ?? null) === false
        && count($casdoor['journeys'][0]['runs'] ?? []) === 4
        && $enduranceStatus !== 0 && (str_contains($enduranceOutput, 'approved host is invalid') || str_contains($enduranceOutput, 'URL is invalid'))
        && $casdoorStatus !== 0 && str_contains($casdoorOutput, 'sandiam participant must be identified')
        && $interopStatus !== 0 && str_contains($interopOutput, 'reviewer must be independent')
        && $recoveryStatus !== 0 && str_contains($recoveryOutput, 'reviewer must be independent')
        && $readyRecoveryStatus === 0 && str_contains($readyRecoveryOutput, '"real_g": false')
        && $independentStatus !== 0 && str_contains($independentOutput, 'participant must be independent')
        && $existingStatus !== 0 && file_get_contents($existingPath) === $existingBytes
        && $symlinkStatus !== 0 && is_link($symlinkPath) && file_get_contents($symlinkTarget) === $symlinkBytes
        && count(array_filter($raceStatuses, static fn (int $status): bool => $status === 0)) === 1
        && is_array($raceDocument) && ($raceDocument['schema'] ?? null) === 'sand-iam.casdoor-comparison/v3';
    if (!$passed) throw new RuntimeException('candidate-bound templates were not complete and fail-closed');
} finally {
    $removeTree($seed);
}

echo "SandIAM external acceptance template checks passed\n";
