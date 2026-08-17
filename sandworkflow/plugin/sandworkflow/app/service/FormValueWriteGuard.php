<?php
declare(strict_types=1);

namespace plugin\sandworkflow\app\service;

use plugin\sandadmin\exception\ApiException;

final class FormValueWriteGuard
{
    public function apply(
        array $definitionSnapshot,
        string $nodeId,
        array $currentValue,
        mixed $updates
    ): array {
        return $this->applyWithAudit(
            $definitionSnapshot,
            $nodeId,
            $currentValue,
            $updates
        )['form_value'];
    }

    /**
     * @return array{
     *     form_value: array,
     *     changes: list<array{path: string, before: mixed, after: mixed}>
     * }
     */
    public function applyWithAudit(
        array $definitionSnapshot,
        string $nodeId,
        array $currentValue,
        mixed $updates
    ): array {
        if (!is_array($updates)) {
            throw new ApiException('表单字段更新格式错误');
        }
        if ($updates === []) {
            return ['form_value' => $currentValue, 'changes' => []];
        }

        $nodeConfig = $definitionSnapshot['nodeConfig'] ?? null;
        $node = is_array($nodeConfig)
            ? $this->findNodeByIdentity($nodeConfig, $nodeId)
            : null;
        if ($node === null) {
            throw new ApiException('当前流程节点不存在，不能修改表单');
        }
        if (!array_key_exists('formAuths', $node) || !is_array($node['formAuths'])) {
            throw new ApiException('当前节点未配置可编辑表单字段');
        }

        $editable = $this->editableFields($node['formAuths']);
        $result = $currentValue;
        $changes = [];
        foreach ($updates as $name => $value) {
            $fieldName = trim((string) $name);
            if ($fieldName === '' || !array_key_exists($fieldName, $editable)) {
                throw new ApiException('字段无编辑权限：' . $fieldName);
            }
            $detailChildren = $editable[$fieldName];
            if ($detailChildren === null) {
                $before = $result[$fieldName] ?? null;
                $result[$fieldName] = $value;
                $this->appendChange($changes, $fieldName, $before, $value);
                continue;
            }
            $result[$fieldName] = $this->mergeDetailRows(
                $fieldName,
                $result[$fieldName] ?? [],
                $value,
                $detailChildren,
                $changes
            );
        }
        return ['form_value' => $result, 'changes' => $changes];
    }

    private function editableFields(array $formAuths): array
    {
        $editable = [];
        foreach ($formAuths as $auth) {
            if (!is_array($auth)) {
                continue;
            }
            $name = trim((string) ($auth['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $details = is_array($auth['details'] ?? null) ? $auth['details'] : [];
            if ($details === []) {
                if ($this->permissionFlag($auth['editable'] ?? false)) {
                    $editable[$name] = null;
                }
                continue;
            }
            $children = [];
            foreach ($details as $detail) {
                if (!is_array($detail) || !$this->permissionFlag($detail['editable'] ?? false)) {
                    continue;
                }
                $childName = trim((string) ($detail['name'] ?? ''));
                if ($childName !== '') {
                    $children[$childName] = true;
                }
            }
            if ($children !== []) {
                $editable[$name] = $children;
            }
        }
        return $editable;
    }

    private function mergeDetailRows(
        string $fieldName,
        mixed $currentRows,
        mixed $updates,
        array $editableChildren,
        array &$changes
    ): array {
        if (!is_array($currentRows) || !array_is_list($currentRows)
            || !is_array($updates) || !array_is_list($updates)) {
            throw new ApiException('明细字段更新格式错误：' . $fieldName);
        }
        if (count($updates) !== count($currentRows)) {
            throw new ApiException('明细字段本次不允许新增或删除行：' . $fieldName);
        }

        $result = $currentRows;
        foreach ($updates as $index => $rowUpdates) {
            if (!is_array($rowUpdates) || !is_array($currentRows[$index] ?? null)) {
                throw new ApiException('明细字段行格式错误：' . $fieldName);
            }
            foreach ($rowUpdates as $childName => $childValue) {
                $normalizedName = trim((string) $childName);
                if ($normalizedName === '' || !isset($editableChildren[$normalizedName])) {
                    throw new ApiException('明细字段无编辑权限：' . $normalizedName);
                }
                $before = $result[$index][$normalizedName] ?? null;
                $result[$index][$normalizedName] = $childValue;
                $this->appendChange(
                    $changes,
                    sprintf('%s[%d].%s', $fieldName, $index, $normalizedName),
                    $before,
                    $childValue
                );
            }
        }
        return $result;
    }

    private function appendChange(
        array &$changes,
        string $path,
        mixed $before,
        mixed $after
    ): void {
        if ($before === $after) {
            return;
        }
        $changes[] = [
            'path' => $path,
            'before' => $before,
            'after' => $after,
        ];
    }

    private function findNodeByIdentity(array $node, string $nodeId): ?array
    {
        if ((string) ($node['nodeId'] ?? $node['id'] ?? '') === $nodeId) {
            return $node;
        }
        if (is_array($node['childNode'] ?? null)) {
            $matched = $this->findNodeByIdentity($node['childNode'], $nodeId);
            if ($matched !== null) {
                return $matched;
            }
        }
        foreach ((array) ($node['conditionNodes'] ?? []) as $branch) {
            if (!is_array($branch)) {
                continue;
            }
            $matched = $this->findNodeByIdentity($branch, $nodeId);
            if ($matched !== null) {
                return $matched;
            }
        }
        return null;
    }

    private function permissionFlag(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1';
    }
}
