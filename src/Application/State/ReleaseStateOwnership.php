<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Application\State;

use LaravelCloudBlueprint\Planning\ResourceAddress;
use LaravelCloudBlueprint\State\Contract\StateStore;
use LaravelCloudBlueprint\State\StateDocument;

final readonly class ReleaseStateOwnership
{
    public function preview(ResourceAddress $address, StateStore $states): StateOwnershipReleaseProposal
    {
        return $this->proposal($address, $states->load());
    }

    public function execute(
        StateOwnershipReleaseProposal $approved,
        StateStore $states,
    ): StateOwnershipReleaseResult {
        $transaction = $states->begin();

        try {
            $current = $transaction->load();
            $proposal = $this->proposal($approved->address, $current);
            if (!$approved->matches($proposal)) {
                throw new StateOwnershipReleaseRefusedException(
                    'Local State ownership changed after preview. Review the current State and try again.',
                    $proposal,
                );
            }
            if (!$proposal->canRelease()) {
                throw new StateOwnershipReleaseRefusedException(
                    $proposal->isManaged()
                        ? 'Managed children prevent ownership release.'
                        : 'Resource is no longer managed.',
                    $proposal,
                );
            }

            return new StateOwnershipReleaseResult(
                $proposal,
                $transaction->save($current->withoutResource($approved->address)),
            );
        } finally {
            $transaction->release();
        }
    }

    private function proposal(ResourceAddress $address, StateDocument $state): StateOwnershipReleaseProposal
    {
        return new StateOwnershipReleaseProposal(
            $address,
            $state->find($address),
            ...$state->childrenOf($address),
        );
    }
}
