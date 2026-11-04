<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

abstract class Controller
{
    /**
     * The message of a failed business rule, which is safe to show the customer.
     * Services throw plain RuntimeExceptions for those. Its subclasses include
     * database errors, whose messages contain SQL, so they are reported instead.
     */
    protected function publicErrorMessage(RuntimeException $e): string
    {
        if ($e::class === RuntimeException::class) {
            return $e->getMessage();
        }

        report($e);

        return 'خطایی رخ داد. لطفاً دوباره تلاش کنید.';
    }

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
        ] + $this->paginationDetails($paginator));
    }

    /**
     * The `links` and `meta` blocks every paginated endpoint returns at the
     * top level, whatever shape its `data` has.
     *
     * @return array{links: array<string, ?string>, meta: array<string, ?int>}
     */
    protected function paginationDetails(LengthAwarePaginator $paginator): array
    {
        return [
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
        ];
    }
}
