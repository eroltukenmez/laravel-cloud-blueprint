<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Observation;

enum ReasonCode: string
{
    case ENVIRONMENT_BRANCH_DIFFERENCE = 'environment_branch_difference';
    case ENVIRONMENT_VARIABLE_VALUE_DIFFERENCE = 'environment_variable_value_difference';
    case DATABASE_ATTACHMENT_IN_SYNC = 'database_attachment_in_sync';
    case DATABASE_ATTACHMENT_DIFFERENCE = 'database_attachment_difference';
    case DATABASE_ATTACHMENT_RELATIONSHIP_INCOMPLETE = 'database_attachment_relationship_incomplete';
    case DATABASE_ATTACHMENT_ENVIRONMENT_IDENTITY_INVALID = 'database_attachment_environment_identity_invalid';
    case DATABASE_ATTACHMENT_DATABASE_IDENTITY_INVALID = 'database_attachment_database_identity_invalid';
}
