<?php

declare(strict_types=1);

namespace plugin\SandIamC05Business\app\controller;

use plugin\SandIam\app\service\HumanAuthService;
use RuntimeException;
use support\Db;
use Webman\Http\Request;
use Webman\Http\Response;

final class AcceptanceCleanupController
{
    private const CONFIRMATION = 'I_CONFIRM_DELETE_ONLY_C05_BUSINESS_AUDIT';

    public function cleanup(Request $request): Response
    {
        $scope = $this->scope($request, $request->post());
        $result = Db::transaction(function () use ($scope): array {
            $rows = $this->rows($scope, true);
            $expected = count($scope['audit_ids']);
            if (count($rows) !== $expected) {
                throw new RuntimeException('C05 business audit cleanup requires the exact recorded call set');
            }
            $deleted = Db::table('standalone_business_audit')
                ->whereIn('id', $scope['audit_ids'])
                ->where('action', 'c05.route.inspect')
                ->where('work_item_id', $scope['work_item_id'])
                ->delete();
            if ((int) $deleted !== $expected) {
                throw new RuntimeException('C05 business audit cleanup did not delete the exact fixture set');
            }
            return ['deleted' => $expected, 'residual' => $this->count($scope)];
        });
        return json($result)->withHeader('Cache-Control', 'no-store');
    }

    public function status(Request $request): Response
    {
        $input = $request->get();
        $scope = $this->scope($request, is_array($input) ? $input : []);
        return json(['residual' => $this->count($scope)])->withHeader('Cache-Control', 'no-store');
    }

    /** @param array<string,mixed> $input @return array{audit_ids:list<int>,request_ids:list<string>,work_item_id:int} */
    private function scope(Request $request, array $input): array
    {
        $authorization = trim((string) $request->header('Authorization', ''));
        if (!str_starts_with($authorization, 'Bearer ')) {
            throw new RuntimeException('C05 cleanup requires an application user session');
        }
        [$application] = (new HumanAuthService())->authenticatedPrincipal(trim(substr($authorization, 7)));
        if ((string) $application->code !== trim((string) getenv('SAND_IAM_C05_APPLICATION_CODE'))) {
            throw new RuntimeException('C05 cleanup application mismatch');
        }
        $prefix = trim((string) ($input['prefix'] ?? ''));
        if (preg_match('/^sand_iam_acceptance_[a-f0-9]{16}_$/', $prefix) !== 1
            || ($input['confirmation'] ?? null) !== self::CONFIRMATION) {
            throw new RuntimeException('C05 cleanup scope confirmation is invalid');
        }
        $auditIds = $this->positiveIds($input['audit_ids'] ?? null);
        $requestIds = $input['request_ids'] ?? null;
        $workItemId = filter_var($input['work_item_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $expectedRequestIds = count($auditIds) === 1
            ? [$prefix . 'chain5-provider-allow']
            : [$prefix . 'chain5-provider-allow', $prefix . 'chain5-provider-route-disabled'];
        if ($auditIds === []
            || !is_array($requestIds)
            || array_values($requestIds) !== $expectedRequestIds
            || $workItemId === false) {
            throw new RuntimeException('C05 cleanup requires the exact audit ids, request ids and work item');
        }
        return ['audit_ids' => $auditIds, 'request_ids' => array_values($requestIds), 'work_item_id' => (int) $workItemId];
    }

    /** @return list<int> */
    private function positiveIds(mixed $value): array
    {
        if (!is_array($value) || count($value) < 1 || count($value) > 2) return [];
        $ids = [];
        foreach ($value as $item) {
            $id = filter_var($item, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id === false) return [];
            $ids[] = (int) $id;
        }
        return count(array_unique($ids)) === count($ids) ? $ids : [];
    }

    /** @param array{audit_ids:list<int>,request_ids:list<string>,work_item_id:int} $scope @return list<array<string,mixed>> */
    private function rows(array $scope, bool $lock): array
    {
        $query = Db::table('standalone_business_audit')
            ->whereIn('id', $scope['audit_ids'])
            ->whereIn('request_id', $scope['request_ids'])
            ->where('action', 'c05.route.inspect')
            ->where('work_item_id', $scope['work_item_id']);
        if ($lock) $query->lockForUpdate();
        return $query->get()->map(static fn (object $row): array => (array) $row)->all();
    }

    /** @param array{audit_ids:list<int>,request_ids:list<string>,work_item_id:int} $scope */
    private function count(array $scope): int
    {
        return count($this->rows($scope, false));
    }
}
