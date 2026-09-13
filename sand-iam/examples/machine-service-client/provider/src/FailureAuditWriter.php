<?php
declare(strict_types=1);
namespace Sand\Iam\Example\ProviderB;
final class FailureAuditWriter
{
    public function __construct(private readonly ProviderConfig $config, private readonly ?\Closure $connect = null) {}
    public function write(?string $documentId, string $context, string $idempotencyKey, string $requestId, string $code, int $status): void
    {
        try {
            $db = $this->connect !== null ? ($this->connect)() : new \PDO($this->config->databaseDsn, $this->config->databaseUser, $this->config->databasePassword, [\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION]);
            $stmt = $db->prepare('INSERT INTO provider_b_audit_log (organization_id,workload_client_id,document_id,action,outcome,request_id,context_id,context_hash,idempotency_key_hash,replayed,error_code,http_status,create_time) VALUES (NULL,NULL,:document,:action,:outcome,:request,NULL,:context_hash,:key_hash,false,:code,:status,CURRENT_TIMESTAMP)');
            $stmt->execute([':document'=>$documentId,':action'=>ProviderProtocol::ACTION,':outcome'=>'failed',':request'=>$requestId,':context_hash'=>$context===''?null:hash('sha256',$context),':key_hash'=>$idempotencyKey===''?null:hash('sha256',$idempotencyKey),':code'=>$code,':status'=>$status]);
        } catch (\Throwable) {
            // A secondary audit failure must never turn a rejection into a process effect.
        }
    }
}
