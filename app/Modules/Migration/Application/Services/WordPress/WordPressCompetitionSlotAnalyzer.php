<?php

declare(strict_types=1);

namespace App\Modules\Migration\Application\Services\WordPress;

use Illuminate\Support\Facades\DB;

/**
 * Read-only AQ submission slot analysis.
 */
final class WordPressCompetitionSlotAnalyzer
{
    public function __construct(
        private readonly WordPressConnectionService $connection,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function analyze(): array
    {
        $this->connection->assertProductionPrefix();
        $conn = $this->connection->connectionName();
        $subs = $this->connection->table('aq_submissions');

        $total = (int) DB::connection($conn)->table($subs)->count();
        $participants = (int) DB::connection($conn)->table($subs)->distinct('enrollment_id')->count('enrollment_id');

        $size1 = (int) (DB::connection($conn)->selectOne(
            'SELECT COUNT(*) c FROM (SELECT enrollment_id, sample_name, COUNT(*) n FROM '.$this->connection->physicalTable('aq_submissions').' GROUP BY enrollment_id, sample_name HAVING n = 1) x'
        )->c ?? 0);
        $size2 = (int) (DB::connection($conn)->selectOne(
            'SELECT COUNT(*) c FROM (SELECT enrollment_id, sample_name, COUNT(*) n FROM '.$this->connection->physicalTable('aq_submissions').' GROUP BY enrollment_id, sample_name HAVING n = 2) x'
        )->c ?? 0);
        $sizeGt2 = (int) (DB::connection($conn)->selectOne(
            'SELECT COUNT(*) c FROM (SELECT enrollment_id, sample_name, COUNT(*) n FROM '.$this->connection->physicalTable('aq_submissions').' GROUP BY enrollment_id, sample_name HAVING n > 2) x'
        )->c ?? 0);

        $emptyNames = (int) DB::connection($conn)->table($subs)
            ->where(function ($q): void {
                $q->whereNull('sample_name')->orWhere('sample_name', '');
            })->count();

        $orderRegressions = 0;
        $checked = 0;
        foreach (DB::connection($conn)->table($this->connection->table('aq_enrollments'))->pluck('id') as $eid) {
            $prev = null;
            foreach (DB::connection($conn)->table($subs)->where('enrollment_id', $eid)->orderBy('id')->pluck('created_at') as $created) {
                $checked++;
                if ($prev !== null && $created < $prev) {
                    $orderRegressions++;
                }
                $prev = $created;
            }
        }

        $verdict = 'COMPETITION_SLOT_SCHEMA_RESOLVED';
        // Analytical ambiguity remains for sample_name grouping (informational).
        $mappingAmbiguous = true;

        return [
            'participants_analyzed' => $participants,
            'submissions_analyzed' => $total,
            'sample_name_patterns' => [
                'groups_size_1' => $size1,
                'groups_size_2' => $size2,
                'groups_size_gt_2' => $sizeGt2,
                'empty_sample_name' => $emptyNames,
                'note' => 'sample_name is free-text specimen label; size>2 exceeds max_images_per_sample=2',
            ],
            'ordering_reliability' => [
                'id_vs_created_at_regressions' => $orderRegressions,
                'checked' => $checked,
                'id_order_stable' => $orderRegressions === 0,
            ],
            'rejected_auto_rule' => 'sample_number=floor(n/max)+1 ignores sample_name and breaks on >2 photos per name',
            'preservation_policy' => [
                'preserve_sample_name' => true,
                'preserve_source_id_order' => true,
                'invent_sample_number' => false,
                'metadata_keys' => ['legacy_submission_id', 'sample_name', 'source_order_index', 'image_id'],
            ],
            'mapping_ambiguous' => $mappingAmbiguous,
            'verdict' => $verdict,
            'schema_blocker' => null,
            'schema' => [
                'code' => 'COMPETITION_SLOT_SCHEMA_RESOLVED',
                'reason' => 'competition_submissions.sample_number and photo_index are nullable for historical AQ rows; new submissions still require slots via validation',
                'sample_name_to_slot_mapping' => 'AMBIGUOUS — not invented',
                'invent_sample_number' => false,
                'invent_photo_index' => false,
            ],
            'wrote_to_database' => false,
        ];
    }
}
