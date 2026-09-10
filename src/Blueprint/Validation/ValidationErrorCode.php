<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Blueprint\Validation;

enum ValidationErrorCode: string
{
    case REQUIRED = 'required';
    case INVALID_TYPE = 'invalid_type';
    case UNKNOWN_PROPERTY = 'unknown_property';
    case UNSUPPORTED_VERSION = 'unsupported_version';
    case UNSUPPORTED_PROVIDER = 'unsupported_provider';
    case INVALID_VARIABLE_SOURCE = 'invalid_variable_source';
    case EMPTY_VALUE = 'empty_value';
    case UNSUPPORTED_DATABASE_TYPE = 'unsupported_database_type';
    case INVALID_DATABASE_REFERENCE = 'invalid_database_reference';
    case INVALID_LOGICAL_IDENTIFIER = 'invalid_logical_identifier';
}
