<?php
namespace App\Services\IguideLab;

/** A new wire identity; the frozen v2 semantic, geometry and evidence schema remain unchanged. */
final class LabLifestyleOriginalOpticalCutProtocol
{
    public const VERSION='responses-lifestyle-cut-original-details-v1';
    public static function compile(array $recipe,array $context,array $images): array
    {
        $payload=LabLifestyleOpticalCutProtocol::compile($recipe,$context,$images);
        return LabLifestyleOriginalDetailTransport::apply($payload,$context['viewIds'],$context['lifestyleFraming']['opticalDetails']['views']);
    }
    public static function parse(array $response,array $context): array
    {return LabLifestyleOpticalCutProtocol::parse($response,$context);}
}
