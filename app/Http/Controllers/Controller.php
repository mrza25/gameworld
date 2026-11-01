<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

abstract class Controller
{
    /**
     * Whether the payment gateway sent the customer back with a failure status,
     * e.g. `status=NOK` after they cancelled on the gateway page.
     */
    protected function gatewayReportedFailure(Request $request): bool
    {
        return $request->filled('status') && strtoupper($request->string('status')->toString()) !== 'OK';
    }

    /**
     * A page of results in the Laravel resource format: data stays the array of
     * rows, with pagination in links and meta.
     *
     * @param  iterable<int, mixed>  $rows
     */
    protected function paginatedResponse(LengthAwarePaginator $paginator, iterable $rows): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'data' => collect($rows)->values(),
            'links' => [
                'first' => $paginator->url(1),
                'last' => $paginator->url($paginator->lastPage()),
                'prev' => $paginator->previousPageUrl(),
                'next' => $paginator->nextPageUrl(),
            ],
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        ]);
    }
}
