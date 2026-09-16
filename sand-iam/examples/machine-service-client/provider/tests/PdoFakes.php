<?php
declare(strict_types=1);
final class FakePdo extends PDO
{
    public mixed $body; public ?array $existing = null; public array $created = ['id'=>7]; public bool $auditFails = false; public array $auditBindings = []; public array $auditParams = [];
    public int $commits = 0; public int $rollbacks = 0; public int $processWrites = 0; public int $audits = 0; private bool $transaction = false;
    public function __construct(mixed $body) {$this->body=$body;}
    public function setAttribute(int $attribute, mixed $value): bool {return true;}
    public function beginTransaction(): bool {$this->transaction=true;return true;}
    public function commit(): bool {$this->transaction=false;++$this->commits;return true;}
    public function rollBack(): bool {$this->transaction=false;++$this->rollbacks;return true;}
    public function inTransaction(): bool {return $this->transaction;}
    public function prepare(string $query, array $options = []): PDOStatement|false {return new FakeStatement($this,$query);}
}
final class FakeStatement extends PDOStatement
{
    private array $bound=[]; private ?array $params=null;
    public function __construct(private FakePdo $db,private string $sql) {}
    public function bindValue(string|int $param,mixed $value,int $type=PDO::PARAM_STR):bool {$this->bound[(string)$param]=[$value,$type];return true;}
    public function execute(?array $params=null):bool
    {
        $this->params=$params;
        if(str_contains($this->sql,'provider_b_audit_log')){$this->db->auditBindings=$this->bound;$this->db->auditParams=$params??[];++$this->db->audits;if($this->db->auditFails)throw new PDOException('audit fail');}
        if(str_contains($this->sql,'INSERT INTO provider_b_document_process'))++$this->db->processWrites;
        return true;
    }
    public function fetch(int $mode=PDO::FETCH_DEFAULT,int $orientation=PDO::FETCH_ORI_NEXT,int $offset=0):mixed
    {
        if(str_contains($this->sql,'FROM provider_b_document WHERE'))return['id'=>'doc-1','organization_id'=>20,'body'=>$this->db->body];
        if(str_contains($this->sql,'FROM provider_b_document_process'))return $this->db->existing?:false;
        if(str_contains($this->sql,'RETURNING id'))return $this->db->created?:false;
        return false;
    }
}
