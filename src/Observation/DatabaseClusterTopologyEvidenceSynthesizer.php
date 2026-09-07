<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Observation;

final readonly class DatabaseClusterTopologyEvidenceSynthesizer
{
    public function synthesize(
        string $clusterId,
        DatabaseClusterExactIdentityStatus $exactIdentity,
        DatabaseClusterRelationshipEvidence $relationship,
        DatabaseClusterScopedListEvidence $scopedList,
    ): DatabaseClusterTopologyEvidence {
        if (trim($clusterId) === '') {
            throw new \InvalidArgumentException('Database Cluster topology identity must not be empty.');
        }
        $synthesis = $this->classify($clusterId, $exactIdentity, $relationship, $scopedList);

        return new DatabaseClusterTopologyEvidence(
            $clusterId,
            $exactIdentity,
            $relationship,
            $scopedList,
            $synthesis,
        );
    }

    private function classify(
        string $clusterId,
        DatabaseClusterExactIdentityStatus $exactIdentity,
        DatabaseClusterRelationshipEvidence $relationship,
        DatabaseClusterScopedListEvidence $scopedList,
    ): DatabaseClusterTopologySynthesis {
        if ($exactIdentity === DatabaseClusterExactIdentityStatus::CONFLICTING) {
            return DatabaseClusterTopologySynthesis::CONFLICTING;
        }
        if ($exactIdentity !== DatabaseClusterExactIdentityStatus::VERIFIED) {
            return DatabaseClusterTopologySynthesis::INCOMPLETE;
        }

        $relationshipIds = $relationship->childIds();
        $scopedIds = $scopedList->childIds();
        if ($this->hasDuplicates($relationshipIds) || $this->hasDuplicates($scopedIds)) {
            return DatabaseClusterTopologySynthesis::CONFLICTING;
        }
        foreach ($scopedList->children() as $child) {
            if ($child->parentProof($clusterId) === DatabaseClusterParentProof::CONFLICTING) {
                return DatabaseClusterTopologySynthesis::CONFLICTING;
            }
        }

        $relationshipComplete = $relationship->status === DatabaseClusterRelationshipEvidenceStatus::COMPLETE;
        $scopedComplete = $scopedList->status === DatabaseClusterScopedListEvidenceStatus::COMPLETE;
        if ($relationshipComplete
            && $this->scopedContradictsCompleteRelationship($relationshipIds, $scopedIds, $scopedComplete)) {
            return DatabaseClusterTopologySynthesis::CONFLICTING;
        }
        if ($relationshipComplete && $scopedComplete) {
            return DatabaseClusterTopologySynthesis::CORROBORATED_COMPLETE;
        }

        if ($relationship->status === DatabaseClusterRelationshipEvidenceStatus::ABSENT
            && $scopedComplete
            && $this->allParentsProven($clusterId, $scopedList)) {
            return DatabaseClusterTopologySynthesis::SCOPED_COMPLETE;
        }

        if ($scopedList->status === DatabaseClusterScopedListEvidenceStatus::PARTIAL
            && ($relationshipIds !== [] || $scopedIds !== [])) {
            return DatabaseClusterTopologySynthesis::PARTIAL_POSITIVE;
        }
        if ($relationship->status === DatabaseClusterRelationshipEvidenceStatus::ABSENT
            && $scopedComplete
            && $scopedIds !== []) {
            return DatabaseClusterTopologySynthesis::PARTIAL_POSITIVE;
        }

        return DatabaseClusterTopologySynthesis::INCOMPLETE;
    }

    /** @param list<string> $ids */
    private function hasDuplicates(array $ids): bool
    {
        return count($ids) !== count(array_unique($ids));
    }

    /**
     * A complete exact relationship proves its full child set. Any scoped child outside it is a
     * contradiction, while a partial scoped list may legitimately omit relationship children.
     *
     * @param list<string> $relationshipIds
     * @param list<string> $scopedIds
     */
    private function scopedContradictsCompleteRelationship(
        array $relationshipIds,
        array $scopedIds,
        bool $scopedComplete,
    ): bool {
        if (array_diff($scopedIds, $relationshipIds) !== []) {
            return true;
        }

        if (!$scopedComplete) {
            return false;
        }

        sort($relationshipIds, SORT_STRING);
        sort($scopedIds, SORT_STRING);

        return $relationshipIds !== $scopedIds;
    }

    private function allParentsProven(
        string $clusterId,
        DatabaseClusterScopedListEvidence $scopedList,
    ): bool {
        foreach ($scopedList->children() as $child) {
            if ($child->parentProof($clusterId) !== DatabaseClusterParentProof::EXACT) {
                return false;
            }
        }

        return true;
    }
}
