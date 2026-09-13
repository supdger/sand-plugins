<?php
declare(strict_types=1);
use Sand\Iam\Example\ProviderB\ProviderProtocol;
use Sand\Iam\Sdk\SandIamClient;
require_once dirname(__DIR__) . '/vendor/autoload.php';
$document = $argv[1] ?? ''; ProviderProtocol::documentId($document);
$iamUrl = rtrim((string)getenv('SAND_IAM_BASE_URL'), '/'); $org = trim((string)getenv('SAND_IAM_ORGANIZATION_CODE')); $app = trim((string)getenv('SAND_IAM_APPLICATION_CODE'));
$credential = trim((string)getenv('SAND_IAM_WORKLOAD_CREDENTIAL')); $providerUrl = rtrim((string)getenv('PROVIDER_B_BASE_URL'), '/'); $key = trim((string)getenv('PROVIDER_B_IDEMPOTENCY_KEY'));
ProviderProtocol::idempotencyKey($key);
if ($iamUrl === '' || $org === '' || $app === '' || $credential === '' || $providerUrl === '') throw new RuntimeException('SandIAM、Provider 和凭证必须由部署环境注入');
$requestId = 'provider-b-'.bin2hex(random_bytes(12));
$context = (new SandIamClient($iamUrl, $org, $app))->issueContext($credential, ProviderProtocol::SERVICE_CODE, ProviderProtocol::AUDIENCE, [ProviderProtocol::ACTION], null, $requestId.'-issue');
$result = post($providerUrl.'/provider/v1/documents/'.rawurlencode($document).'/process', ['X-Request-Id'=>$requestId, 'X-Sand-Iam-Context'=>$context['context'], 'Idempotency-Key'=>$key]);
echo json_encode($result + ['request_id'=>$requestId,'context_id'=>$context['context_id']], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
/** @param array<string,string> $headers @return array<string,mixed> */
function post(string $url, array $headers): array
{
    $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME)); $host = strtolower((string)parse_url($url, PHP_URL_HOST));
    if ($scheme !== 'https' && !($scheme === 'http' && in_array($host, ['127.0.0.1','localhost','::1'], true))) throw new RuntimeException('Provider 地址必须使用 HTTPS；仅本机开发允许 HTTP');
    $h = curl_init($url); if ($h === false) throw new RuntimeException('Provider 网络初始化失败');
    $lines = ['Content-Type: application/json','Cache-Control: no-store'];
    foreach ($headers as $name=>$value) { if (preg_match('/^[A-Za-z0-9-]+$/',$name)!==1 || str_contains($value,"\r") || str_contains($value,"\n")) throw new RuntimeException('Provider 请求头无效'); $lines[]="$name: $value"; }
    curl_setopt_array($h,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>'{}',CURLOPT_HTTPHEADER=>$lines,CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>10]);
    $body=curl_exec($h); $status=(int)curl_getinfo($h,CURLINFO_RESPONSE_CODE); curl_close($h);
    if (!is_string($body) || $status<200 || $status>=300) throw new RuntimeException('Provider 请求被拒绝或不可用');
    $decoded=json_decode($body,true,32,JSON_THROW_ON_ERROR); if(!is_array($decoded)||!is_array($decoded['data']??null)) throw new RuntimeException('Provider 返回协议无效');
    return $decoded['data'];
}
