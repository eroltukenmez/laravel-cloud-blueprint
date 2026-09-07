<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Observation;

enum DatabaseClusterTopologyEvidenceSource: string
{
    case EXACT_CLUSTER_RELATIONSHIP = 'exact_cluster_relationship';
    case SCOPED_DATABASE_LIST = 'scoped_database_list';
    case BOTH = 'both';
}
