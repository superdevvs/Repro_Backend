<?php

namespace App\Services\SystemEmails;

class EmailTypeDefinition
{
    /**
     * @param  array<int, string>  $requiredSections
     * @param  array<int, string>  $allowedRecipientTypes
     */
    public function __construct(
        public readonly string $alias,
        public readonly int $version,
        public readonly string $category,
        public readonly string $templateView,
        public readonly string $templateVersion,
        public readonly array $requiredSections,
        public readonly array $allowedRecipientTypes,
        public readonly string $deliveryMode = 'sync',
        public readonly bool $editable = false,
        public readonly string $sourceOfTruth = 'code',
    ) {
    }

    public function resolvedType(): string
    {
        return sprintf('%s_V%d', $this->alias, $this->version);
    }
}
