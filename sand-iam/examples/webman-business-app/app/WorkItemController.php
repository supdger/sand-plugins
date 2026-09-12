<?php

declare(strict_types=1);

namespace Example\WorkItem;

use Webman\Http\Request;
use Webman\Http\Response;

final class WorkItemController
{
    public function read(Request $request): Response
    {
        $workItem = $this->resolvedWorkItem($request);
        return json(['id' => $workItem->id, 'state' => $workItem->state]);
    }

    public function close(Request $request): Response
    {
        $workItem = $this->resolvedWorkItem($request);
        (new WorkItemRepository())->close($workItem);
        return json(['id' => $workItem->id, 'state' => 'closed']);
    }

    public function batchClose(Request $request): Response
    {
        $workItems = $request->resolvedWorkItems ?? null;
        if (!is_array($workItems)) throw new \LogicException('SandIAM resolver did not provide loaded work items');
        $repository = new WorkItemRepository();
        foreach ($workItems as $workItem) $repository->close($workItem);
        return json(['closed' => count($workItems)]);
    }

    private function resolvedWorkItem(Request $request): WorkItem
    {
        $workItem = $request->resolvedWorkItem ?? null;
        if (!$workItem instanceof WorkItem) throw new \LogicException('SandIAM resolver did not provide a loaded work item');
        return $workItem;
    }
}
