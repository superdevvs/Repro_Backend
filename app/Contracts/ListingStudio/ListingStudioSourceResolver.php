<?php

namespace App\Contracts\ListingStudio;

use App\Models\User;

interface ListingStudioSourceResolver
{
    /**
     * Read/validate an owned source outside financial write transactions.
     * Snapshot: kind,id,sha256,mime,width,height,byteSize,disk,path,original:{same media fields}.
     * All paths/descriptors are private and must never be serialized in a client DTO.
     */
    public function resolve(User $client, string $kind, string $sourceKind, string $sourceId): array;

    /**
     * Revalidate owner/readiness/expiry and retain an accepted source.
     * Called inside the short core transaction after item creation; DB work only.
     * Throw a reviewed PublicApiException to roll back order + financial reservation.
     */
    public function pin(array $snapshot, string $itemId): void;
}
