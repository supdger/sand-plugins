<?php
declare(strict_types=1);
// behavior-test-gate: static-rule
// Read-only R08 authority-contract consistency gate; it never starts a host, DB, service, or browser.

$root = dirname(__DIR__);
$freezePath = $root . '/docs/development/sand-iam-t09-t12-terminal-spec-freeze.md';
$routePath = $root . '/plugin/sand-iam/config/route.php';
$initializationControllerPath = $root . '/plugin/sand-iam/app/admin/controller/InitializationController.php';
$freeze = file_get_contents($freezePath);
$routes = file_get_contents($routePath);
$initializationController = file_get_contents($initializationControllerPath);
if (!is_string($freeze) || !is_string($routes) || !is_string($initializationController)) { fwrite(STDERR, "cannot read R08 authority sources\n"); exit(1); }

/** @return list<string> */
function markdownCells(string $line): array
{
    $line = trim($line);
    if (!str_starts_with($line, '|') || !str_ends_with($line, '|')) return [];
    $line = substr($line, 1, -1);
    $cells = []; $cell = ''; $codeFenceLength = 0; $escaped = false;
    for ($i = 0, $length = strlen($line); $i < $length; $i++) {
        $char = $line[$i];
        if ($char === '`' && !$escaped) {
            $runLength = 1;
            while ($i + $runLength < $length && $line[$i + $runLength] === '`') $runLength++;
            if ($codeFenceLength === 0) $codeFenceLength = $runLength;
            elseif ($codeFenceLength === $runLength) $codeFenceLength = 0;
            $cell .= str_repeat('`', $runLength);
            $i += $runLength - 1;
            $escaped = false;
            continue;
        }
        if ($char === '|' && $codeFenceLength === 0 && !$escaped) {
            $cells[] = trim(str_replace('\\|', '|', $cell));
            $cell = '';
            continue;
        }
        $cell .= $char;
        $escaped = $char === '\\' && !$escaped;
        if ($char !== '\\') $escaped = false;
    }
    if ($codeFenceLength !== 0) return [];
    $cells[] = trim(str_replace('\\|', '|', $cell));
    return $cells;
}

/** @param list<string> $cells */
function isMarkdownSeparator(array $cells): bool
{
    if ($cells === []) return false;
    foreach ($cells as $cell) if (preg_match('/^:?-{3,}:?$/', str_replace(' ', '', $cell)) !== 1) return false;
    return true;
}

/** @return array{header:list<string>,rows:list<list<string>>} */
function markdownTableAfterHeading(string $text, string $heading): array
{
    $lines = preg_split('/\R/u', $text) ?: [];
    $headingIndex = array_search($heading, array_map('trim', $lines), true);
    if ($headingIndex === false) return ['header' => [], 'rows' => []];
    $headingLevel = strspn($heading, '#');
    $sectionEnd = count($lines);
    for ($i = $headingIndex + 1; $i < count($lines); $i++) {
        if (preg_match('/^(#{1,' . $headingLevel . '})\s/u', trim($lines[$i])) === 1) { $sectionEnd = $i; break; }
    }
    for ($i = $headingIndex + 1; $i + 1 < $sectionEnd; $i++) {
        $header = markdownCells($lines[$i]);
        $separator = markdownCells($lines[$i + 1]);
        if ($header === [] || count($separator) !== count($header) || !isMarkdownSeparator($separator)) continue;
        $rows = [];
        for ($rowIndex = $i + 2; $rowIndex < $sectionEnd; $rowIndex++) {
            if (!str_starts_with(trim($lines[$rowIndex]), '|')) break;
            $cells = markdownCells($lines[$rowIndex]);
            if ($cells === []) break;
            $rows[] = $cells;
        }
        return ['header' => $header, 'rows' => $rows];
    }
    return ['header' => [], 'rows' => []];
}

/** @return list<string> */
function codeTokens(string $value): array
{
    preg_match_all('/(?<![\\\\`])(`+)(?!`)(.*?)\1(?!`)/s', $value, $matches);
    return $matches[2] ?? [];
}

/** @return list<string> */
function expandEndpoint(string $value): array
{
    $value = preg_replace('/^(?:GET|POST|PATCH|PUT|DELETE)\s+/', '', trim($value)) ?? '';
    if ($value === '') return [];
    $parts = explode('|', $value); $first = array_shift($parts);
    if (!is_string($first) || $first === '') return [];
    $expanded = [$first]; $base = str_contains($first, '/') ? substr($first, 0, strrpos($first, '/') + 1) : '';
    foreach ($parts as $part) $expanded[] = $base . $part;
    return $expanded;
}

$results = [];
$assert = static function (string $label, bool $ok) use (&$results): void { $results[$label] = $ok; echo '[' . ($ok ? 'PASS' : 'FAIL') . "] {$label}\n"; };

$matrix = markdownTableAfterHeading($freeze, '## 2. 原子差异矩阵');
$columnCount = count($matrix['header']);
$rows = array_values(array_filter($matrix['rows'], static fn (array $row): bool => isset($row[0]) && preg_match('/^R08-T(?:09|10|11|12)-\d{2}$/', $row[0]) === 1));
$validRows = array_values(array_filter($rows, static fn (array $row): bool => count($row) === $columnCount));
$assert("matrix header has {$columnCount}/10 columns; " . count($validRows) . '/12 R08 rows parse', $columnCount === 10 && count($validRows) === 12);

$ids = array_column($validRows, 0); $expectedIds = [];
foreach (['T09', 'T10', 'T11', 'T12'] as $ticket) for ($i = 1; $i <= 3; $i++) $expectedIds[] = "R08-{$ticket}-0{$i}";
sort($ids); sort($expectedIds);
$assert('matrix has 12/12 unique continuous atomic IDs', count($ids) === 12 && count(array_unique($ids)) === 12 && $ids === $expectedIds);

$tickets = array_count_values(array_column($validRows, 1));
$assert('matrix ticket column distributes T09/T10/T11/T12 as 3/3/3/3', $tickets === ['T09' => 3, 'T10' => 3, 'T11' => 3, 'T12' => 3]);

$contracts = ['T09' => 'sand-iam-application-experience-message-provider-v0.1.md', 'T10' => 'sand-iam-user-lifecycle-group-sync-v0.1.md', 'T11' => 'sand-iam-protocol-interop-v0.1.md', 'T12' => 'sand-iam-developer-security-operations-v0.1.md'];
$contractRows = []; $contractFiles = 0;
foreach ($contracts as $ticket => $name) {
    $text = is_file(dirname($freezePath) . '/' . $name) ? file_get_contents(dirname($freezePath) . '/' . $name) : false;
    if (!is_string($text)) continue;
    $table = markdownTableAfterHeading($text, '## 验收字段与错误码对账');
    if (count($table['header']) !== 3) continue;
    foreach ($table['rows'] as $row) if (count($row) === 3 && preg_match('/^R08-' . $ticket . '-\d{2}$/', $row[0]) === 1) $contractRows[$row[0]] = $row;
    $contractFiles++;
}
$assert("four contract field/error tables resolve ({$contractFiles}/4 files; " . count($contractRows) . '/12 rows)', $contractFiles === 4 && count($contractRows) === 12);

$fieldErrorOk = true; $fieldErrorPairs = 0;
foreach ($validRows as $row) {
    $matrixTokens = codeTokens($row[3]); $contract = $contractRows[$row[0]] ?? [];
    $contractTokens = $contract === [] ? [] : array_merge(codeTokens($contract[1]), codeTokens($contract[2]));
    $hasField = false; $hasError = false;
    foreach ($matrixTokens as $token) {
        if (!in_array($token, $contractTokens, true)) $fieldErrorOk = false;
        $hasField = $hasField || !str_starts_with($token, 'SAND_IAM_');
        $hasError = $hasError || str_starts_with($token, 'SAND_IAM_');
    }
    if (!$hasField || !$hasError) $fieldErrorOk = false;
    if ($hasField && $hasError) $fieldErrorPairs++;
}
$assert("matrix fields/errors match parsed contract rows ({$fieldErrorPairs}/12 pairs)", $fieldErrorOk && $fieldErrorPairs === 12);

$adminBase = '/app/sand-iam/admin'; $knownRoutes = [];
preg_match_all("/Route::(?:get|post|patch|put|delete)\('([^']+)'/", $routes, $routeMatches);
foreach ($routeMatches[1] ?? [] as $path) { $knownRoutes[$path] = true; if (!str_starts_with($path, '/api/') && !str_starts_with($path, '/app/')) $knownRoutes[$adminBase . $path] = true; }
preg_match_all("/Route::group\('([^']+)'/", $routes, $groupMatches);
foreach ($groupMatches[1] ?? [] as $path) $knownRoutes[$path] = true;
preg_match_all("/^\s*'([a-z][a-z0-9-]+)'\s*=>/m", $routes, $segmentMatches);
foreach ($segmentMatches[1] ?? [] as $segment) foreach (['index', 'read', 'save', 'update', 'disable'] as $action) $knownRoutes["{$adminBase}/{$segment}/{$action}"] = true;

$apiOk = true; $currentApiCount = 0; $missingCurrentApis = [];
foreach ($validRows as $row) {
    [$current] = explode('[planned]', $row[5], 2);
    foreach (codeTokens($current) as $token) foreach (expandEndpoint($token) as $endpoint) {
        $currentApiCount++;
        if (str_ends_with($endpoint, '/*')) {
            $prefix = rtrim($endpoint, '/*'); if (!str_starts_with($prefix, '/')) $prefix = $adminBase . '/' . $prefix;
            $found = false; foreach (array_keys($knownRoutes) as $known) if ($known === $prefix || str_starts_with($known, $prefix . '/')) $found = true;
            if (!$found) { $apiOk = false; $missingCurrentApis[] = $endpoint; }
            continue;
        }
        if (!str_starts_with($endpoint, '/')) $endpoint = $adminBase . '/' . $endpoint;
        if (!isset($knownRoutes[$endpoint])) { $apiOk = false; $missingCurrentApis[] = $endpoint; }
    }
}
$missingCurrentApis = array_values(array_unique($missingCurrentApis));
$apiDetail = $missingCurrentApis === [] ? '' : '; missing: ' . implode(', ', $missingCurrentApis);
$assert("all {$currentApiCount} current API references resolve in route authority{$apiDetail}", $apiOk && $currentApiCount > 0);

$initializationRow = null;
foreach ($validRows as $row) if ($row[0] === 'R08-T12-03') { $initializationRow = $row; break; }
$draftApi = dirname($freezePath) . '/sand-iam-initialization-draft-api-v0.1.md';
$implementedDraftOk = is_array($initializationRow)
    && !str_contains($initializationRow[5], '[planned]')
    && str_contains($initializationRow[5], '`initialization/index|read|export|preview|apply|rollback|save|update|disable`')
    && str_contains($initializationRow[5], '`GET /app/sand-iam/admin/initialization/draft-index|draft-read`')
    && is_file($draftApi)
    && str_contains($freeze, '只表示规格冻结，绝不构成运行验收')
    && str_contains($routes, "Route::post('/app/sand-iam/admin/initialization/save', [InitializationController::class, 'save'])->middleware([InitializationSensitiveMiddleware::class, CheckLogin::class, CheckAuth::class]);")
    && str_contains($routes, "Route::post('/app/sand-iam/admin/initialization/update', [InitializationController::class, 'update'])->middleware([InitializationSensitiveMiddleware::class, CheckLogin::class, CheckAuth::class]);")
    && str_contains($routes, "Route::post('/app/sand-iam/admin/initialization/disable', [InitializationController::class, 'disable'])->middleware([InitializationSensitiveMiddleware::class, CheckLogin::class, CheckAuth::class]);")
    && str_contains($routes, "Route::get('/initialization/draft-index', [InitializationController::class, 'draftIndex']);")
    && str_contains($routes, "Route::get('/initialization/draft-read', [InitializationController::class, 'draftRead']);")
    && str_contains($initializationController, "#[Permission('SandIAM 初始化草稿保存', 'sand_iam:initialization:save')]")
    && str_contains($initializationController, "#[Permission('SandIAM 初始化草稿更新', 'sand_iam:initialization:update')]")
    && str_contains($initializationController, "#[Permission('SandIAM 初始化草稿停用', 'sand_iam:initialization:disable')]")
    && str_contains($initializationController, 'public function save(Request $request): Response')
    && str_contains($initializationController, 'public function update(Request $request): Response')
    && str_contains($initializationController, 'public function disable(Request $request): Response')
    && str_contains($initializationController, 'public function index(Request $request): Response')
    && str_contains($initializationController, 'public function read(Request $request): Response')
    && str_contains($initializationController, 'InitializationRun::order')
    && str_contains($initializationController, '$this->run((int) $request->get(\'id\', 0), $request)')
    && str_contains($initializationController, 'public function draftIndex(Request $request): Response')
    && str_contains($initializationController, 'public function draftRead(Request $request): Response');
$assert('3/3 implemented initialization writes, explicit draft compatibility routes, and unchanged run endpoints match the frozen contract', $implementedDraftOk);

$external = markdownTableAfterHeading($freeze, '## 3. 外部系统的真实验收门槛'); $externalRows = [];
foreach ($external['rows'] as $row) if (count($row) === 4 && preg_match('/^R08-T(?:09|10|11|12)-\d{2}$/', $row[0]) === 1) $externalRows[$row[0]] = $row;
$externalOk = count($externalRows) === 12; $externalChecks = 0; $externalFailures = [];
foreach ($validRows as $row) {
    $gate = $externalRows[$row[0]] ?? [];
    if ($gate === []) { $externalOk = false; continue; }
    [, $system, $actionEvidence, $mock] = $gate;
    $declaresNa = str_starts_with($system, 'N/A：');
    $na = preg_match('/^N\/A：\S.{5,}$/u', trim($system)) === 1;
    $realSystem = !$declaresNa && mb_strlen(trim($system)) >= 6;
    $hasAction = preg_match('/(?:启用|停用|发送|挑战|登录|读取|拒绝|撤销|恢复|清理|升级|同步|分页|映射|注册|登出|认证|消费|发现|诊断|投递|判定|告警|归档|预检|应用|回滚)/u', $actionEvidence) === 1;
    $hasEvidence = preg_match('/(?:记录|报文|响应|cookie|审计|夹具)/iu', $actionEvidence) === 1;
    $actionEvidenceOk = $hasAction && $hasEvidence;
    $mockOk = $na ? str_contains($mock, '不适用') && str_contains($mock, '不能') : str_contains(strtolower($mock), 'mock') && str_contains($mock, '不能');
    if (($declaresNa && !$na) || (!$declaresNa && !$realSystem) || !$actionEvidenceOk || !$mockOk) { $externalOk = false; $externalFailures[] = $row[0]; }
    if (($na || $realSystem) && $actionEvidenceOk && $mockOk) $externalChecks++;
}
$externalDetail = $externalFailures === [] ? '' : '; failing: ' . implode(', ', $externalFailures);
$assert("external gate rows name system/N-A, action evidence and mock limit ({$externalChecks}/12){$externalDetail}", $externalOk && $externalChecks === 12);

$oldRouteOk = true;
foreach (['/account', '/authorize', '/context', '/scim/v2'] as $old) $oldRouteOk = $oldRouteOk && str_contains($freeze, "`{$old}`");
$assert('4/4 retired route drafts remain explicitly prohibited', $oldRouteOk);

$pass = count(array_filter($results)); $total = count($results);
echo "R08 terminal specification freeze: {$pass}/{$total} checks passed\n";
exit($pass === $total ? 0 : 1);
