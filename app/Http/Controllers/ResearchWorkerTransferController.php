<?php

namespace App\Http\Controllers;

use App\Services\Research\WorkerTransfers;
use Illuminate\Http\Request;

class ResearchWorkerTransferController extends Controller
{
    public function transfer(Request $request, string $id, string $action, WorkerTransfers $transfers)
    {
        abort_unless(in_array($action, ['input', 'authorize', 'result'], true), 404);
        abort_unless(($action === 'input' && $request->isMethod('get')) || ($action !== 'input' && $request->isMethod('post')), 405);
        abort_if((int) $request->header('Content-Length', 0) > 28000000, 413);
        $result = $transfers->access($id, $request->bearerToken() ?? '', $action, $action === 'result' ? $request->getContent() : null);

        return response($result, 200, ['Content-Type' => 'application/json', 'Cache-Control' => 'no-store']);
    }
}
