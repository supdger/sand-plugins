<?php
declare(strict_types=1);
namespace Sand\Iam\Example\ProviderB;
final class PdoProcessStore implements ProcessStore
{
    public function __construct(private readonly \PDO $db)
    {
        $this->db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(\PDO::ATTR_EMULATE_PREPARES, false);
    }
    /** @param array<string,mixed> $claims @return array<string,mixed> */
    public function process(array $claims, string $documentId, string $key, string $requestId): array
    {
        $this->db->beginTransaction();
        try {
            $doc = $this->one('SELECT id,organization_id,body FROM provider_b_document WHERE id=:id FOR UPDATE', [':id' => $documentId]);
            if ($doc === null) throw new ProviderException('PROVIDER_B_DOCUMENT_NOT_FOUND', 404);
            $org = (int) $claims['organization_id'];
            if ((int) $doc['organization_id'] !== $org) throw new ProviderException('PROVIDER_B_DOCUMENT_SCOPE_FORBIDDEN', 403);
            $body = $this->body($doc['body'] ?? null);
            $hash = hash('sha256', $body); $bytes = strlen($body); $client = (int) $claims['workload_client_id'];
            $existing = $this->operation($client, $key);
            if ($existing !== null) return $this->finishReplay($existing, $org, $client, $documentId, $key, $requestId, (string) $claims['context_id'], $hash, $bytes);
            $insert = $this->db->prepare('INSERT INTO provider_b_document_process (organization_id,document_id,workload_client_id,idempotency_key,content_sha256,content_bytes,context_id,processed_time) VALUES (:org,:document,:client,:key,:hash,:bytes,:context,CURRENT_TIMESTAMP) ON CONFLICT (workload_client_id,idempotency_key) DO NOTHING RETURNING id');
            $insert->execute([':org'=>$org, ':document'=>$documentId, ':client'=>$client, ':key'=>$key, ':hash'=>$hash, ':bytes'=>$bytes, ':context'=>(string)$claims['context_id']]);
            $created = $insert->fetch(\PDO::FETCH_ASSOC);
            if (!is_array($created)) {
                $existing = $this->operation($client, $key);
                if ($existing === null) throw new ProviderException('PROVIDER_B_IDEMPOTENCY_CONFLICT', 409);
                return $this->finishReplay($existing, $org, $client, $documentId, $key, $requestId, (string) $claims['context_id'], $hash, $bytes);
            }
            $result = ['process_id'=>(int)$created['id'], 'document_id'=>$documentId, 'content_sha256'=>$hash, 'content_bytes'=>$bytes, 'replayed'=>false];
            $this->audit($org, $client, $documentId, $key, $requestId, (string)$claims['context_id'], false);
            $this->db->commit(); return $result;
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            if ($e instanceof ProviderException) throw $e;
            throw new ProviderException('PROVIDER_B_PERSISTENCE_FAILED', 503);
        }
    }
    /** @return array<string,mixed>|null */
    private function one(string $sql, array $args): ?array
    {
        $stmt = $this->db->prepare($sql); $stmt->execute($args); $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }
    /** @return array<string,mixed>|null */
    private function operation(int $client, string $key): ?array
    {
        return $this->one('SELECT id,document_id,content_sha256,content_bytes FROM provider_b_document_process WHERE workload_client_id=:client AND idempotency_key=:key FOR UPDATE', [':client'=>$client, ':key'=>$key]);
    }
    /** @param array<string,mixed> $old @return array<string,mixed> */
    private function finishReplay(array $old, int $org, int $client, string $document, string $key, string $request, string $context, string $hash, int $bytes): array
    {
        if (!hash_equals($document, (string)$old['document_id']) || !hash_equals($hash, (string)$old['content_sha256']) || $bytes !== (int)$old['content_bytes']) throw new ProviderException('PROVIDER_B_IDEMPOTENCY_KEY_REUSED', 409);
        $this->audit($org, $client, $document, $key, $request, $context, true); $this->db->commit();
        return ['process_id'=>(int)$old['id'], 'document_id'=>$document, 'content_sha256'=>$hash, 'content_bytes'=>$bytes, 'replayed'=>true];
    }
    private function audit(int $org, int $client, string $doc, string $key, string $request, string $context, bool $replayed): void
    {
        $stmt = $this->db->prepare('INSERT INTO provider_b_audit_log (organization_id,workload_client_id,document_id,action,outcome,request_id,context_id,idempotency_key_hash,replayed,create_time) VALUES (:org,:client,:doc,:action,:outcome,:request,:context,:key_hash,:replayed,CURRENT_TIMESTAMP)');
        foreach ([':org'=>$org, ':client'=>$client, ':doc'=>$doc, ':action'=>ProviderProtocol::ACTION, ':outcome'=>'succeeded', ':request'=>$request, ':context'=>$context, ':key_hash'=>hash('sha256',$key)] as $name=>$value) $stmt->bindValue($name, $value);
        $stmt->bindValue(':replayed', $replayed, \PDO::PARAM_BOOL);
        $stmt->execute();
    }
    private function body(mixed $value): string
    {
        if (is_string($value)) return $value;
        if (is_resource($value) && get_resource_type($value) === 'stream') {
            $meta = stream_get_meta_data($value);
            if (!is_array($meta) || !is_string($meta['mode'] ?? null) || preg_match('/[r+]/', $meta['mode']) !== 1) {
                throw new ProviderException('PROVIDER_B_DOCUMENT_INVALID', 409);
            }
            try {
                $body = @stream_get_contents($value);
                if (is_string($body)) return $body;
            } catch (\Throwable) {
                // Treat a broken stream implementation exactly like an unreadable body.
            }
        }
        throw new ProviderException('PROVIDER_B_DOCUMENT_INVALID', 409);
    }
}
