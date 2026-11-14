<?php
namespace App\Services\IguideLab;

/** Closed opt-in transport: one revised cut and up to two original details, in one follow-up. */
final class LabStagingCombinedLayoutProtocol
{
    public const VERSION = 'responses-staging-layout-v2';
    public const GATE = 'staging-prospective-layout/2';

    public static function compile(array $recipe,array $context,array $images):array
    {return LabStagingLayoutProtocol::compile($recipe,$context,$images,true);}

    public static function parse(array $response,array $context):array
    {return LabStagingLayoutProtocol::parse($response,$context,true);}

    public static function gateSchema():array
    {return LabStagingLayoutProtocol::gateSchema(true);}
}
