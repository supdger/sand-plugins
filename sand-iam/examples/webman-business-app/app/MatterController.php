<?php

declare(strict_types=1);

namespace Example\Matter;

use Webman\Http\Request;
use Webman\Http\Response;

final class MatterController
{
    public function read(Request $request): Response
    {
        $matter = $this->resolvedMatter($request);
        return json(['id' => $matter->id, 'state' => $matter->state]);
    }

    public function archive(Request $request): Response
    {
        $matter = $this->resolvedMatter($request);
        (new MatterRepository())->archive($matter); // the scope guard already ran before this handler.
        return json(['id' => $matter->id, 'state' => 'archived']);
    }

    public function batchArchive(Request $request): Response
    {
        $matters = $request->resolvedMatters ?? null;
        if (!is_array($matters)) throw new \LogicException('SandIAM resolver did not provide loaded matters');
        $repository = new MatterRepository();
        foreach ($matters as $matter) $repository->archive($matter);
        return json(['archived' => count($matters)]);
    }

    private function resolvedMatter(Request $request): Matter
    {
        $matter = $request->resolvedMatter ?? null;
        if (!$matter instanceof Matter) throw new \LogicException('SandIAM resolver did not provide a loaded matter');
        return $matter;
    }
}
