<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Application;

use LaravelCloudBlueprint\Blueprint\Decoder\StructuredDataDecoder;
use LaravelCloudBlueprint\Blueprint\Normalization\BlueprintNormalizer;
use LaravelCloudBlueprint\Blueprint\Validation\BlueprintValidator;

final readonly class BlueprintLoader
{
    public function __construct(
        private StructuredDataDecoder $decoder,
        private BlueprintValidator $validator,
        private BlueprintNormalizer $normalizer,
    ) {
    }

    public function load(string $content): BlueprintLoadResult
    {
        $data = $this->decoder->decode($content);
        $validation = $this->validator->validate($data);

        if (!$validation->isValid()) {
            return BlueprintLoadResult::invalid($validation);
        }

        return BlueprintLoadResult::valid($this->normalizer->normalize($data), $validation);
    }
}
