<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    /**
     * GET /api/v1/reports/top-departments
     * Top 5 departments with most requests in the last 3 months
     */
    public function topDepartments(): JsonResponse
    {
        $rows = DB::select("
            SELECT
                d.id,
                d.name                                                  AS department,
                d.code,
                COUNT(r.id)                                             AS total_requests,
                COUNT(CASE WHEN r.status = 'COMPLETED' THEN 1 END)     AS completed,
                COUNT(CASE WHEN r.status = 'REJECTED'  THEN 1 END)     AS rejected,
                COUNT(CASE WHEN r.status = 'IN_PROCUREMENT' THEN 1 END) AS in_procurement
            FROM requests r
            JOIN departments d ON d.id = r.department_id
            WHERE r.submitted_at >= NOW() - INTERVAL '3 months'
              AND r.deleted_at  IS NULL
              AND d.deleted_at  IS NULL
            GROUP BY d.id, d.name, d.code
            ORDER BY total_requests DESC
            LIMIT 5
        ");

        return response()->json(['success' => true, 'data' => $rows]);
    }

    /**
     * GET /api/v1/reports/category-per-month
     * Most requested item category per month (last 12 months)
     */
    public function categoryPerMonth(): JsonResponse
    {
        $rows = DB::select("
            WITH monthly_category AS (
                SELECT
                    DATE_TRUNC('month', r.submitted_at)  AS bulan,
                    ri.category,
                    COUNT(ri.id)                         AS total_item,
                    SUM(ri.quantity)                     AS total_qty,
                    RANK() OVER (
                        PARTITION BY DATE_TRUNC('month', r.submitted_at)
                        ORDER BY COUNT(ri.id) DESC
                    ) AS rnk
                FROM request_items ri
                JOIN requests r ON r.id = ri.request_id
                WHERE r.deleted_at  IS NULL
                  AND r.submitted_at IS NOT NULL
                  AND r.submitted_at >= NOW() - INTERVAL '12 months'
                GROUP BY DATE_TRUNC('month', r.submitted_at), ri.category
            )
            SELECT
                TO_CHAR(bulan, 'YYYY-MM') AS bulan,
                category,
                total_item,
                total_qty
            FROM monthly_category
            WHERE rnk = 1
            ORDER BY bulan DESC
        ");

        return response()->json(['success' => true, 'data' => $rows]);
    }

    /**
     * GET /api/v1/reports/lead-time
     * Average (and median) lead time from SUBMITTED to COMPLETED
     */
    public function leadTime(): JsonResponse
    {
        $stats = DB::selectOne("
            SELECT
                COUNT(*)                                                    AS total_selesai,
                ROUND(AVG(
                    EXTRACT(EPOCH FROM (completed_at - submitted_at)) / 3600
                )::NUMERIC, 2)                                          AS avg_jam,

                ROUND((PERCENTILE_CONT(0.5) WITHIN GROUP (
                    ORDER BY EXTRACT(EPOCH FROM (completed_at - submitted_at)) / 3600
                ))::NUMERIC, 2)                                         AS median_jam,

                ROUND(MIN(
                    EXTRACT(EPOCH FROM (completed_at - submitted_at)) / 3600
                )::NUMERIC, 2)                                          AS min_jam,

                ROUND(MAX(
                    EXTRACT(EPOCH FROM (completed_at - submitted_at)) / 3600
                )::NUMERIC, 2)                                          AS max_jam
            FROM requests
            WHERE status       = 'COMPLETED'
              AND submitted_at  IS NOT NULL
              AND completed_at  IS NOT NULL
              AND deleted_at    IS NULL
        ");

        // Lead time by department (bonus breakdown)
        $byDept = DB::select("
            SELECT
                d.name AS department,
                COUNT(*) AS total,
                ROUND(AVG(
                    EXTRACT(EPOCH FROM (r.completed_at - r.submitted_at)) / 3600
                ), 2) AS avg_jam
            FROM requests r
            JOIN departments d ON d.id = r.department_id
            WHERE r.status = 'COMPLETED'
              AND r.submitted_at IS NOT NULL
              AND r.completed_at IS NOT NULL
              AND r.deleted_at   IS NULL
            GROUP BY d.id, d.name
            ORDER BY avg_jam DESC
        ");

        return response()->json([
            'success' => true,
            'data'    => [
                'summary'        => $stats,
                'by_department'  => $byDept,
            ],
        ]);
    }
}
